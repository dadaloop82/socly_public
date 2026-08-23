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
        private readonly AuditService $audit,
        private readonly WorkflowService $workflow
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
    public function sendOtp(string $email, string $ip, string $memberName = ''): array
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

        $vars = [
            'email' => $email,
            'member_email' => $email,
            'member_name' => $memberName !== '' ? $memberName : UserService::deriveDisplayNameFromEmail($email),
            'otp_code' => $code,
            'association_name' => (string) $this->settings->get('association.name', ''),
            'if_member_email' => '1',
        ];
        $sentViaWorkflow = $this->workflow->dispatch('member.enrollment_otp', $vars, 'it', $ip);
        if ($sentViaWorkflow === 0) {
            $sent = app(MailService::class)->send(
                $email,
                __('members.enrollment_otp_mail_subject'),
                __('members.enrollment_otp_mail_body', ['code' => $code])
            );
            if (!$sent['ok']) {
                unset($_SESSION['enrollment_otp_hash'], $_SESSION['enrollment_otp_expires'], $_SESSION['enrollment_otp_email']);
                return ['ok' => false, 'error' => (string) ($sent['error'] ?? __('mail.send_failed'))];
            }
        }
        $this->audit->log('member.enrollment_otp_sent', 'member', null, null, ['email' => $email], $ip);

        return [
            'ok' => true,
            'message' => __('members.enrollment_otp_sent'),
        ];
    }

    public function hasArtifact(int $memberId): bool
    {
        try {
            $row = $this->db->fetch(
                'SELECT id FROM member_enrollment_artifacts WHERE member_id = :id LIMIT 1',
                ['id' => $memberId]
            );
        } catch (\Throwable) {
            return false;
        }
        return $row !== null;
    }

    /** @return array<string,mixed>|null */
    public function latestArtifact(int $memberId): ?array
    {
        try {
            return $this->db->fetch(
                'SELECT * FROM member_enrollment_artifacts WHERE member_id = :id ORDER BY id DESC LIMIT 1',
                ['id' => $memberId]
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<int> $memberIds
     * @return array<int, true> member_id => true when an artifact exists
     */
    public function artifactPresenceMap(array $memberIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $memberIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $rows = $this->db->fetchAll(
                "SELECT DISTINCT member_id FROM member_enrollment_artifacts WHERE member_id IN ($placeholders)",
                $ids
            );
        } catch (\Throwable) {
            return [];
        }
        $map = [];
        foreach ($rows as $row) {
            $map[(int) ($row['member_id'] ?? 0)] = true;
        }
        return $map;
    }

    public function enrollmentRequired(): bool
    {
        return $this->method() !== 'none';
    }

    public function absoluteArtifactPath(?string $relative): ?string
    {
        $relative = trim((string) $relative);
        if ($relative === '') {
            return null;
        }
        return resolve_upload_absolute_path($relative);
    }

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int}|null $scanFile
     */
    private function storeUpload(int $memberId, array $file, string $prefix): array
    {
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION) ?: 'bin');
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
        $name = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $paths = user_upload_paths('enrollment/' . $memberId, null, $name);
        if (!move_uploaded_file((string) $file['tmp_name'], $paths['absolute'])) {
            return [];
        }
        return ['path' => $paths['relative'], 'hash' => hash_file('sha256', $paths['absolute']) ?: null];
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
        $ext = $m[1] === 'jpeg' ? 'jpg' : 'png';
        $name = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $paths = user_upload_paths('enrollment/' . $memberId, null, $name);
        if (file_put_contents($paths['absolute'], $bin) === false) {
            return [];
        }
        return ['path' => $paths['relative'], 'hash' => hash('sha256', $bin)];
    }
}
