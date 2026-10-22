<?php

declare(strict_types=1);

namespace Socly\Setup;

/**
 * Italian legal-form abbreviations (max 6 chars) for associations / entities.
 *
 * The official code is always the Italian one (APS, ODV, …): German long forms
 * are only kept for RUNTS matching, never shown as the selectable label.
 */
final class AssociationLegalForms
{
    /**
     * @return array<string, string> code => label_key (UI locale)
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

    /**
     * Fixed IT / DE long-form pairs (independent of UI locale).
     *
     * @return array<string, array{it:string,de:string,alias?:string}>
     */
    public static function bilingualCatalog(): array
    {
        return [
            'APS' => [
                'it' => 'Associazione di Promozione Sociale',
                'de' => 'Verein zur sozialen Förderung',
                'alias' => 'VBO',
            ],
            'ASD' => [
                'it' => 'Associazione Sportiva Dilettantistica',
                'de' => 'Amateur-Sportverein',
            ],
            'ODV' => [
                'it' => 'Organizzazione di Volontariato',
                'de' => 'Freiwilligenorganisation',
            ],
            'ETS' => [
                'it' => 'Ente del Terzo Settore',
                'de' => 'Körperschaft des Dritten Sektors',
            ],
            'SSD' => [
                'it' => 'Società Sportiva Dilettantistica',
                'de' => 'Amateur-Sportgesellschaft',
            ],
            'ONLUS' => [
                'it' => 'ONLUS',
                'de' => 'ONLUS',
            ],
            'SRL' => [
                'it' => 'Società a responsabilità limitata',
                'de' => 'Gesellschaft mit beschränkter Haftung',
            ],
            'SPA' => [
                'it' => 'Società per azioni',
                'de' => 'Aktiengesellschaft',
            ],
            'SAS' => [
                'it' => 'Società in accomandita semplice',
                'de' => 'Kommanditgesellschaft',
            ],
            'SNC' => [
                'it' => 'Società in nome collettivo',
                'de' => 'Offene Handelsgesellschaft',
            ],
            'SS' => [
                'it' => 'Società semplice',
                'de' => 'Einfache Gesellschaft',
            ],
            'COOP' => [
                'it' => 'Cooperativa',
                'de' => 'Genossenschaft',
            ],
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
     * Select option label: the official Italian code (e.g. "APS").
     */
    public static function displayCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * Italian long form shown under the denomination, e.g.
     * "Associazione di Promozione Sociale".
     */
    public static function meaning(string $code): string
    {
        $code = strtoupper(trim($code));
        $row = self::bilingualCatalog()[$code] ?? null;
        if ($row === null) {
            $key = self::all()[$code] ?? '';
            return $key !== '' ? (string) __($key) : $code;
        }
        return trim((string) $row['it']);
    }

    /**
     * Long-form phrases (any language) that count as already embedding this legal code.
     *
     * @return list<string>
     */
    public static function embeddedPhrases(string $code): array
    {
        $code = strtoupper(trim($code));
        $row = self::bilingualCatalog()[$code] ?? null;
        if ($row === null) {
            return [];
        }
        $out = [];
        foreach (['it', 'de'] as $lang) {
            $phrase = trim((string) ($row[$lang] ?? ''));
            if ($phrase !== '') {
                $out[] = $phrase;
            }
        }
        $alias = trim((string) ($row['alias'] ?? ''));
        if ($alias !== '') {
            $out[] = $alias;
        }
        return $out;
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
        if (str_contains($hay, 'PROMOZIONE SOCIALE') || preg_match('/\bAPS\b/', $hay) || preg_match('/\bVBO\b/', $hay)) {
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
