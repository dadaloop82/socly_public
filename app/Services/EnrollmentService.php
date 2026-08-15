<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;

final class EnrollmentService
{
    public const METHODS = ['none', 'print_scan', 'tablet_sign', 'otp_email'];

    public function __construct(
        private readonly Database $db,
        private readonly SettingsService $settings,
        private readonly AuditService $audit
    ) {
    }

    public function method(): string
    {
        $m = (string) $this->settings->get('membership.enrollment_validation', 'none');
        return in_array($m, self::METHODS, true) ? $m : 'none';
    }

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int}|null $scanFile
     * @return array{ok:bool,errors?:array<string,string>}
     */
    public function validateCreatePayload(array $data, ?array $scanFile): array
    {
        $method = $this->method();
        if ($method === 'none') {
            return ['ok' => true];
        }

        if ($method === 'print_scan') {
            if ($scanFile === null || ($scanFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                return ['ok' => false, 'errors' => ['enrollment_scan' => __('members.enrollment_scan_required')]];
            }
            $size = (int) ($scanFile['size'] ?? 0);
            if ($size <= 0 || $size > 8 * 1024 * 1024) {
                return ['ok' => false, 'errors' => ['enrollment_scan' => __('validation.photo')]];
            }
            return ['ok' => true];
        }

        if ($method === 'tablet_sign') {
            $sig = (string) ($data['enrollment_signature'] ?? '');
            if ($sig === '' || !preg_match('#^data:image/(png|jpeg);base64,#', $sig)) {
                return ['ok' => false, 'errors' => ['enrollment_signature' => __('members.enrollment_sign_required')]];
            }
            return ['ok' => true];
        }

        if ($method === 'otp_email') {
            $code = trim((string) ($data['enrollment_otp'] ?? ''));
            $expected = (string) ($_SESSION['enrollment_otp_hash'] ?? '');
            $expires = (int) ($_SESSION['enrollment_otp_expires'] ?? 0);
            if ($expected === '' || $expires < time()) {
                return ['ok' => false, 'errors' => ['enrollment_otp' => __('members.enrollment_otp_expired')]];
            }
            if ($code === '' || !hash_equals($expected, hash('sha256', $code))) {
                return ['ok' => false, 'errors' => ['enrollment_otp' => __('members.enrollment_otp_invalid')]];
            }
            return ['ok' => true];
        }

        return ['ok' => true];
    }

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int}|null $scanFile
     */
    public function storeArtifact(int $memberId, array $data, ?array $scanFile, string $ip): void
    {
        $method = $this->method();
        if ($method === 'none') {
            return;
        }

        $path = null;
        $hash = null;
        $meta = ['method' => $method];

        if ($method === 'print_scan' && $scanFile) {
            $stored = $this->storeUpload($memberId, $scanFile, 'scan');
            $path = $stored['path'] ?? null;
            $hash = $stored['hash'] ?? null;
        } elseif ($method === 'tablet_sign') {
            $stored = $this->storeDataUrl($memberId, (string) ($data['enrollment_signature'] ?? ''), 'sign');
            $path = $stored['path'] ?? null;
            $hash = $stored['hash'] ?? null;
        } elseif ($method === 'otp_email') {
            $hash = (string) ($_SESSION['enrollment_otp_hash'] ?? '');
            $meta['email'] = (string) ($_SESSION['enrollment_otp_email'] ?? '');
            unset($_SESSION['enrollment_otp_hash'], $_SESSION['enrollment_otp_expires'], $_SESSION['enrollment_otp_email']);
        }

        try {
            $this->db->insert('member_enrollment_artifacts', [
                'member_id' => $memberId,
                'method' => $method,
                'storage_path' => $path,
                'content_hash' => $hash,
                'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'created_by' => auth_user()['id'] ?? null,
            ]);
        } catch (\Throwable) {
            // Migration may not have run yet on older installs mid-upgrade.
        }
        $this->audit->log('member.enrollment_attested', 'member', (string) $memberId, null, $meta, $ip);
    }

    /** @return array{ok:bool,error?:string,message?:string} */
    public function sendOtp(string $email, string $ip): array
    {
        if (!app(MailService::class)->isReady()) {
            return ['ok' => false, 'error' => __('mail.required_for_otp')];
        }
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => __('members.enrollment_otp_email_required')];
        }
        $code = (string) random_int(100000, 999999);
        $_SESSION['enrollment_otp_hash'] = hash('sha256', $code);
        $_SESSION['enrollment_otp_expires'] = time() + 900;
        $_SESSION['enrollment_otp_email'] = $email;

        $sent = app(MailService::class)->send(
            $email,
            __('members.enrollment_otp_mail_subject'),
            __('members.enrollment_otp_mail_body', ['code' => $code])
        );
        if (!$sent['ok']) {
            unset($_SESSION['enrollment_otp_hash'], $_SESSION['enrollment_otp_expires'], $_SESSION['enrollment_otp_email']);
            return ['ok' => false, 'error' => (string) ($sent['error'] ?? __('mail.send_failed'))];
        }
        $this->audit->log('member.enrollment_otp_sent', 'member', null, null, ['email' => $email], $ip);

        return [
            'ok' => true,
            'message' => __('members.enrollment_otp_sent'),
        ];
    }

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return array{path?:string,hash?:string}
     */
    private function storeUpload(int $memberId, array $file, string $prefix): array
    {
        $dir = storage_path('uploads/enrollment/' . $memberId);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION) ?: 'bin');
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
        $name = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $absolute = $dir . '/' . $name;
        if (!move_uploaded_file((string) $file['tmp_name'], $absolute)) {
            return [];
        }
        $relative = 'enrollment/' . $memberId . '/' . $name;
        return ['path' => $relative, 'hash' => hash_file('sha256', $absolute) ?: null];
    }

    /** @return array{path?:string,hash?:string} */
    private function storeDataUrl(int $memberId, string $dataUrl, string $prefix): array
    {
        if (!preg_match('#^data:image/(png|jpeg);base64,(.+)$#', $dataUrl, $m)) {
            return [];
        }
        $bin = base64_decode($m[2], true);
        if ($bin === false || $bin === '') {
            return [];
        }
        $dir = storage_path('uploads/enrollment/' . $memberId);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $ext = $m[1] === 'jpeg' ? 'jpg' : 'png';
        $name = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $absolute = $dir . '/' . $name;
        if (file_put_contents($absolute, $bin) === false) {
            return [];
        }
        $relative = 'enrollment/' . $memberId . '/' . $name;
        return ['path' => $relative, 'hash' => hash('sha256', $bin)];
    }
}
