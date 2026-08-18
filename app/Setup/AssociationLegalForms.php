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

    /**
     * Map a RUNTS section label to a legal-form abbreviation, if possible.
     */
    public static function fromRuntsSection(string $section, string $denomination = ''): ?string
    {
        $hay = mb_strtoupper(trim($section . ' ' . $denomination), 'UTF-8');
        if ($hay === '') {
            return null;
        }
        if (str_contains($hay, 'PROMOZIONE SOCIALE') || preg_match('/\bAPS\b/', $hay)) {
            return 'APS';
        }
        if (str_contains($hay, 'ORGANIZZAZIONI DI VOLONTARIATO') || preg_match('/\bODV\b/', $hay)) {
            return 'ODV';
        }
        if (str_contains($hay, 'IMPRESE SOCIALI') || str_contains($hay, 'IMPRESA SOCIALE')) {
            if (str_contains($hay, 'COOPERATIV')) {
                return 'COOP';
            }
            return 'ETS';
        }
        if (str_contains($hay, 'MUTUO SOCCORSO')) {
            return 'ETS';
        }
        if (str_contains($hay, 'FILANTROPIC')) {
            return 'ETS';
        }
        if (str_contains($hay, 'TERZO SETTORE') || preg_match('/\bETS\b/', $hay)) {
            return 'ETS';
        }
        if (str_contains($hay, 'COOPERATIV')) {
            return 'COOP';
        }
        return null;
    }
}
