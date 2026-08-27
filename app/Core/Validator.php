<?php

declare(strict_types=1);

namespace Socly\Core;

final class Validator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $rules  field => 'required|email|...'
     */
    public function validate(array $data, array $rules): bool
    {
        $this->errors = [];
        foreach ($rules as $field => $ruleString) {
            $value = $data[$field] ?? null;
            foreach (explode('|', $ruleString) as $rule) {
                $params = [];
                if (str_contains($rule, ':')) {
                    [$rule, $paramStr] = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                }
                $this->apply($field, $value, $rule, $params, $data, $ruleString);
            }
        }
        return $this->errors === [];
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstErrors(): array
    {
        $out = [];
        foreach ($this->errors as $field => $messages) {
            $out[$field] = $messages[0];
        }
        return $out;
    }

    private function apply(string $field, mixed $value, string $rule, array $params, array $data, string $ruleString = ''): void
    {
        $fail = function (string $message) use ($field): void {
            $this->errors[$field][] = $message;
        };
        $asString = str_contains($ruleString, 'string');
        $asNumeric = str_contains($ruleString, 'numeric') || str_contains($ruleString, 'integer');

        switch ($rule) {
            case 'required':
                if ($value === null || $value === '') {
                    $fail(__('validation.required'));
                }
                break;
            case 'email':
                if ($value !== null && $value !== '' && !filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
                    $fail(__('validation.email'));
                }
                break;
            case 'string':
                if ($value !== null && $value !== '' && !is_string($value) && !is_numeric($value)) {
                    $fail(__('validation.string'));
                }
                break;
            case 'numeric':
                if ($value !== null && $value !== '' && !is_numeric($value)) {
                    $fail(__('validation.numeric'));
                }
                break;
            case 'integer':
                if ($value !== null && $value !== '' && filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $fail(__('validation.integer'));
                }
                break;
            case 'min':
                $min = (float) ($params[0] ?? 0);
                if ($value === null || $value === '') {
                    break;
                }
                if ($asNumeric || (!$asString && is_numeric($value))) {
                    if ((float) $value < $min) {
                        $fail(__('validation.min', ['min' => (string) $min]));
                    }
                } elseif (mb_strlen((string) $value) < (int) $min) {
                    $fail(__('validation.min_string', ['min' => (string) $min]));
                }
                break;
            case 'max':
                $max = (float) ($params[0] ?? 0);
                if ($value === null || $value === '') {
                    break;
                }
                if ($asNumeric || (!$asString && is_numeric($value))) {
                    if ((float) $value > $max) {
                        $fail(__('validation.max', ['max' => (string) $max]));
                    }
                } elseif (mb_strlen((string) $value) > (int) $max) {
                    $fail(__('validation.max_string', ['max' => (string) $max]));
                }
                break;
            case 'date':
                if ($value !== null && $value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
                    $fail(__('validation.date'));
                }
                break;
            case 'adult_birth_date':
                if ($value !== null && $value !== '') {
                    $birthErr = validate_adult_birth_date((string) $value);
                    if ($birthErr !== null) {
                        $fail(__($birthErr));
                    }
                }
                break;
            case 'in':
                if ($value !== null && $value !== '' && !in_array((string) $value, $params, true)) {
                    $fail(__('validation.in'));
                }
                break;
            case 'confirmed':
                if ((string) $value !== (string) ($data[$field . '_confirmation'] ?? '')) {
                    $fail(__('validation.confirmed'));
                }
                break;
            case 'fiscal_code':
                if ($value !== null && $value !== '' && !$this->isValidFiscalCode((string) $value)) {
                    $fail(__('validation.fiscal_code'));
                }
                break;
            case 'phone':
                if ($value !== null && $value !== '') {
                    $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
                    if (str_starts_with($digits, '39') && strlen($digits) > 10) {
                        $digits = substr($digits, 2);
                    }
                    $ok = (bool) preg_match('/^3\d{8,9}$/', $digits) // mobile
                        || (bool) preg_match('/^0\d{5,10}$/', $digits); // landline
                    if (!$ok) {
                        $fail(__('validation.phone'));
                    }
                }
                break;
            case 'accepted':
                $ok = $value === true || $value === 1 || $value === '1' || $value === 'on' || $value === 'yes';
                if (!$ok) {
                    $fail(__('validation.accepted'));
                }
                break;
            case 'color':
                if ($value !== null && $value !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $value)) {
                    $fail(__('validation.color'));
                }
                break;
            case 'boolean':
                break;
        }
    }

    public function isValidFiscalCode(string $code): bool
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[ABCDEHLMPRST][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/', $code)) {
            return false;
        }
        $oddMap = [
            '0' => 1, '1' => 0, '2' => 5, '3' => 7, '4' => 9, '5' => 13, '6' => 15, '7' => 17, '8' => 19, '9' => 21,
            'A' => 1, 'B' => 0, 'C' => 5, 'D' => 7, 'E' => 9, 'F' => 13, 'G' => 15, 'H' => 17, 'I' => 19, 'J' => 21,
            'K' => 2, 'L' => 4, 'M' => 18, 'N' => 20, 'O' => 11, 'P' => 3, 'Q' => 6, 'R' => 8, 'S' => 12, 'T' => 14,
            'U' => 16, 'V' => 10, 'W' => 22, 'X' => 25, 'Y' => 24, 'Z' => 23,
        ];
        $evenMap = [];
        foreach (range(0, 9) as $i) {
            $evenMap[(string) $i] = $i;
        }
        foreach (range('A', 'Z') as $i => $letter) {
            $evenMap[$letter] = $i;
        }
        $sum = 0;
        for ($i = 0; $i < 15; $i++) {
            $ch = $code[$i];
            $sum += ($i % 2 === 0) ? ($oddMap[$ch] ?? 0) : ($evenMap[$ch] ?? 0);
        }
        $check = chr(($sum % 26) + ord('A'));
        return $check === $code[15];
    }

    /** Italian Partita IVA (11 digits) with check digit. Accepts optional IT prefix. */
    public function isValidVatNumber(string $vat): bool
    {
        $vat = strtoupper(preg_replace('/\s+/', '', $vat) ?? '');
        if (str_starts_with($vat, 'IT')) {
            $vat = substr($vat, 2);
        }
        if (preg_match('/^\d{11}$/', $vat) !== 1) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $n = (int) $vat[$i];
            if ($i % 2 === 0) {
                $sum += $n;
            } else {
                $doubled = $n * 2;
                $sum += $doubled > 9 ? $doubled - 9 : $doubled;
            }
        }
        $check = (10 - ($sum % 10)) % 10;
        return $check === (int) $vat[10];
    }

    /**
     * Entity fiscal code: 11-digit VAT-style CF, or 16-char codice fiscale with check digit.
     */
    public function isValidEntityFiscalCode(string $code): bool
    {
        $code = strtoupper(preg_replace('/\s+/', '', $code) ?? '');
        if ($this->isValidVatNumber($code)) {
            return true;
        }
        return $this->isValidFiscalCode($code);
    }
}
