<?php

declare(strict_types=1);

namespace Socly\Setup;

/**
 * Italian legal-form abbreviations (max 6 chars) for associations / entities.
 */
final class AssociationLegalForms
{
    /**
     * @return array<string, string> code => label_key
     */
    public static function all(): array
    {
        return [
            'APS' => 'setup.legal_form_aps',
            'ASD' => 'setup.legal_form_asd',
            'ODV' => 'setup.legal_form_odv',
            'ETS' => 'setup.legal_form_ets',
            'SSD' => 'setup.legal_form_ssd',
            'ONLUS' => 'setup.legal_form_onlus',
            'SRL' => 'setup.legal_form_srl',
            'SPA' => 'setup.legal_form_spa',
            'SAS' => 'setup.legal_form_sas',
            'SNC' => 'setup.legal_form_snc',
            'SS' => 'setup.legal_form_ss',
            'COOP' => 'setup.legal_form_coop',
        ];
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    /** @return list<array{value:string,label_key:string}> */
    public static function options(): array
    {
        $out = [];
        foreach (self::all() as $code => $labelKey) {
            $out[] = ['value' => $code, 'label_key' => $labelKey];
        }
        return $out;
    }

    public static function isValid(string $code): bool
    {
        $code = strtoupper(trim($code));
        return isset(self::all()[$code]) && strlen($code) <= 6;
    }
}
