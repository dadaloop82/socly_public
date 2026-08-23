<?php

declare(strict_types=1);

namespace Socly\Support;

/**
 * Canonical member-field types: each carries UI logic + validation.
 */
final class MemberFieldTypes
{
    public const TEXT = 'text';
    public const TEXTAREA = 'textarea';
    public const EMAIL = 'email';
    public const PHONE = 'phone';
    public const DATE = 'date';
    public const FISCAL_CODE = 'fiscal_code';
    public const GENDER = 'gender';
    public const LANGUAGE = 'language';
    public const BIRTH_PLACE = 'birth_place';
    public const CITY = 'city';
    public const STREET = 'street';
    public const HOUSE_NUMBER = 'house_number';
    public const POSTAL_CODE = 'postal_code';
    public const CHECKBOX = 'checkbox';
    public const PHOTO = 'photo';

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::catalogue());
    }

    /**
     * @return array<string, array{label_key:string, rule:string, description_key:string}>
     */
    public static function catalogue(): array
    {
        return [
            self::TEXT => [
                'label_key' => 'fields.type_text',
                'description_key' => 'fields.type_text_desc',
                'rule' => 'string|max:255',
            ],
            self::TEXTAREA => [
                'label_key' => 'fields.type_textarea',
                'description_key' => 'fields.type_textarea_desc',
                'rule' => 'string|max:2000',
            ],
            self::EMAIL => [
                'label_key' => 'fields.type_email',
                'description_key' => 'fields.type_email_desc',
                'rule' => 'email|max:190',
            ],
            self::PHONE => [
                'label_key' => 'fields.type_phone',
                'description_key' => 'fields.type_phone_desc',
                'rule' => 'phone',
            ],
            self::DATE => [
                'label_key' => 'fields.type_date',
                'description_key' => 'fields.type_date_desc',
                'rule' => 'date',
            ],
            self::FISCAL_CODE => [
                'label_key' => 'fields.type_fiscal_code',
                'description_key' => 'fields.type_fiscal_code_desc',
                'rule' => 'fiscal_code',
            ],
            self::GENDER => [
                'label_key' => 'fields.type_gender',
                'description_key' => 'fields.type_gender_desc',
                'rule' => 'in:M,F,X',
            ],
            self::LANGUAGE => [
                'label_key' => 'fields.type_language',
                'description_key' => 'fields.type_language_desc',
                'rule' => 'in:it,de,en,other',
            ],
            self::BIRTH_PLACE => [
                'label_key' => 'fields.type_birth_place',
                'description_key' => 'fields.type_birth_place_desc',
                'rule' => 'string|max:120',
            ],
            self::CITY => [
                'label_key' => 'fields.type_city',
                'description_key' => 'fields.type_city_desc',
                'rule' => 'string|max:120',
            ],
            self::STREET => [
                'label_key' => 'fields.type_street',
                'description_key' => 'fields.type_street_desc',
                'rule' => 'string|max:255',
            ],
            self::HOUSE_NUMBER => [
                'label_key' => 'fields.type_house_number',
                'description_key' => 'fields.type_house_number_desc',
                'rule' => 'string|max:20',
            ],
            self::POSTAL_CODE => [
                'label_key' => 'fields.type_postal_code',
                'description_key' => 'fields.type_postal_code_desc',
                'rule' => 'string|max:20',
            ],
            self::CHECKBOX => [
                'label_key' => 'fields.type_checkbox',
                'description_key' => 'fields.type_checkbox_desc',
                'rule' => 'accepted',
            ],
            self::PHOTO => [
                'label_key' => 'fields.type_photo',
                'description_key' => 'fields.type_photo_desc',
                'rule' => 'string|max:255',
            ],
        ];
    }

    public static function isValid(string $type): bool
    {
        return isset(self::catalogue()[$type]);
    }

    public static function label(string $type): string
    {
        $def = self::catalogue()[$type] ?? null;
        return $def ? __($def['label_key']) : $type;
    }

    public static function description(string $type): string
    {
        $def = self::catalogue()[$type] ?? null;
        return $def ? __($def['description_key']) : '';
    }

    /** Base validation rule for a type (without required). */
    public static function baseRule(string $type): string
    {
        return self::catalogue()[$type]['rule'] ?? 'string|max:255';
    }

    public static function validationRule(string $type, bool $required = false, string $key = ''): string
    {
        if ($type === self::CHECKBOX) {
            return $required ? 'accepted' : 'boolean';
        }
        if ($type === self::PHOTO) {
            return self::baseRule($type);
        }
        $base = self::baseRule($type);
        if ($key === 'birth_date') {
            $base = 'date|adult_birth_date';
        }
        if ($required && !str_contains($base, 'required') && !str_contains($base, 'accepted')) {
            return 'required|' . $base;
        }
        return $base;
    }

    /**
     * Normalize legacy / key-specific types to the catalogue.
     */
    public static function resolve(string $type, string $key = ''): string
    {
        $locked = self::lockedTypeForKey($key);
        if ($locked !== null) {
            return $locked;
        }
        if ($type === 'select' && $key === 'gender') {
            return self::GENDER;
        }
        if ($type === 'select' && $key === 'preferred_language') {
            return self::LANGUAGE;
        }
        if ($type === 'address') {
            return self::STREET;
        }
        if ($type === 'select') {
            return self::TEXT;
        }
        return self::isValid($type) ? $type : self::TEXT;
    }

    /** System keys keep a fixed semantic type (UI + validation). */
    public static function lockedTypeForKey(string $key): ?string
    {
        return match ($key) {
            'photo' => self::PHOTO,
            'gender' => self::GENDER,
            'preferred_language' => self::LANGUAGE,
            'fiscal_code' => self::FISCAL_CODE,
            'birth_place' => self::BIRTH_PLACE,
            'birth_date' => self::DATE,
            'email' => self::EMAIL,
            'phone' => self::PHONE,
            'city' => self::CITY,
            'address' => self::STREET,
            'house_number' => self::HOUSE_NUMBER,
            'postal_code' => self::POSTAL_CODE,
            'privacy_ack', 'statute_ack' => self::CHECKBOX,
            default => null,
        };
    }

    /**
     * Core member-archive fields: always enabled + required (not user-toggleable).
     * Contact/address fields (email, phone, city, …) stay configurable.
     */
    public static function isCoreArchiveField(string $key): bool
    {
        return in_array($key, [
            'first_name',
            'last_name',
            'gender',
            'preferred_language',
            'birth_place',
            'birth_date',
            'fiscal_code',
            'privacy_ack',
            'statute_ack',
        ], true);
    }

    public static function slugifyKey(string $label): string
    {
        $key = mb_strtolower(trim($label), 'UTF-8');
        $key = strtr($key, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss',
        ]);
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? '';
        $key = trim($key, '_');
        if ($key === '') {
            $key = 'campo';
        }
        if (strlen($key) > 40) {
            $key = rtrim(substr($key, 0, 40), '_');
        }
        return $key;
    }
}
