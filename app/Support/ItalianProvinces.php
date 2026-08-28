<?php

declare(strict_types=1);

namespace Socly\Support;

final class ItalianProvinces
{
    /** @var array<string, string> */
    private const SIGLA_TO_NAME = [
        'AG' => 'Agrigento', 'AL' => 'Alessandria', 'AN' => 'Ancona', 'AO' => 'Aosta', 'AP' => 'Ascoli Piceno',
        'AQ' => 'L\'Aquila', 'AR' => 'Arezzo', 'AT' => 'Asti', 'AV' => 'Avellino', 'BA' => 'Bari',
        'BG' => 'Bergamo', 'BI' => 'Biella', 'BL' => 'Belluno', 'BN' => 'Benevento', 'BO' => 'Bologna',
        'BR' => 'Brindisi', 'BS' => 'Brescia', 'BT' => 'Barletta-Andria-Trani', 'BZ' => 'Bolzano',
        'CA' => 'Cagliari', 'CB' => 'Campobasso', 'CE' => 'Caserta', 'CH' => 'Chieti', 'CL' => 'Caltanissetta',
        'CN' => 'Cuneo', 'CO' => 'Como', 'CR' => 'Cremona', 'CS' => 'Cosenza', 'CT' => 'Catania',
        'CZ' => 'Catanzaro', 'EN' => 'Enna', 'FC' => 'Forlì-Cesena', 'FE' => 'Ferrara', 'FG' => 'Foggia',
        'FI' => 'Firenze', 'FM' => 'Fermo', 'FR' => 'Frosinone', 'GE' => 'Genova', 'GO' => 'Gorizia',
        'GR' => 'Grosseto', 'IM' => 'Imperia', 'IS' => 'Isernia', 'KR' => 'Crotone', 'LC' => 'Lecco',
        'LE' => 'Lecce', 'LI' => 'Livorno', 'LO' => 'Lodi', 'LT' => 'Latina', 'LU' => 'Lucca',
        'MB' => 'Monza e Brianza', 'MC' => 'Macerata', 'ME' => 'Messina', 'MI' => 'Milano', 'MN' => 'Mantova',
        'MO' => 'Modena', 'MS' => 'Massa-Carrara', 'MT' => 'Matera', 'NA' => 'Napoli', 'NO' => 'Novara',
        'NU' => 'Nuoro', 'OR' => 'Oristano', 'PA' => 'Palermo', 'PC' => 'Piacenza', 'PD' => 'Padova',
        'PE' => 'Pescara', 'PG' => 'Perugia', 'PI' => 'Pisa', 'PN' => 'Pordenone', 'PO' => 'Prato',
        'PR' => 'Parma', 'PT' => 'Pistoia', 'PU' => 'Pesaro e Urbino', 'PV' => 'Pavia', 'PZ' => 'Potenza',
        'RA' => 'Ravenna', 'RC' => 'Reggio Calabria', 'RE' => 'Reggio Emilia', 'RG' => 'Ragusa',
        'RI' => 'Rieti', 'RM' => 'Roma', 'RN' => 'Rimini', 'RO' => 'Rovigo', 'SA' => 'Salerno',
        'SI' => 'Siena', 'SO' => 'Sondrio', 'SP' => 'La Spezia', 'SR' => 'Siracusa', 'SS' => 'Sassari',
        'SU' => 'Sud Sardegna', 'SV' => 'Savona', 'TA' => 'Taranto', 'TE' => 'Teramo', 'TN' => 'Trento',
        'TO' => 'Torino', 'TP' => 'Trapani', 'TR' => 'Terni', 'TS' => 'Trieste', 'TV' => 'Treviso',
        'UD' => 'Udine', 'VA' => 'Varese', 'VB' => 'Verbano-Cusio-Ossola', 'VC' => 'Vercelli',
        'VE' => 'Venezia', 'VI' => 'Vicenza', 'VR' => 'Verona', 'VT' => 'Viterbo', 'VV' => 'Vibo Valentia',
    ];

    public static function expandName(string $value): string
    {
        $raw = trim($value);
        if ($raw === '') {
            return '';
        }
        $code = strtoupper(preg_replace('/[^A-Za-z]/', '', $raw) ?? '');
        if (strlen($code) === 2 && isset(self::SIGLA_TO_NAME[$code])) {
            return self::SIGLA_TO_NAME[$code];
        }
        return assoc_capitalize_name($raw);
    }

    /** @return list<array{label:string,name:string,sigla:string}> */
    public static function search(string $query, int $limit = 8): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 1) {
            return [];
        }
        $q = mb_strtolower($query);
        $code = strtoupper(preg_replace('/[^A-Za-z]/', '', $query) ?? '');
        $starts = [];
        $contains = [];
        foreach (self::SIGLA_TO_NAME as $sigla => $name) {
            $nameLower = mb_strtolower($name);
            $item = [
                'label' => $name . ' (' . $sigla . ')',
                'name' => $name,
                'sigla' => $sigla,
            ];
            if ($code !== '' && strlen($code) <= 2 && str_starts_with($sigla, $code)) {
                $starts[] = $item;
                continue;
            }
            if (str_starts_with($nameLower, $q)) {
                $starts[] = $item;
            } elseif (str_contains($nameLower, $q)) {
                $contains[] = $item;
            }
            if (count($starts) >= $limit) {
                break;
            }
        }
        return array_slice(array_merge($starts, $contains), 0, $limit);
    }

    /**
     * @return array{action:'none'|'apply'|'confirm'|'not_found',item?:array<string,mixed>,label?:string}
     */
    public static function resolveQuery(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['action' => 'none'];
        }

        $code = strtoupper(preg_replace('/[^A-Za-z]/', '', $query) ?? '');
        if (strlen($code) === 2 && isset(self::SIGLA_TO_NAME[$code])) {
            $name = self::SIGLA_TO_NAME[$code];
            if ($name === $query) {
                return ['action' => 'none'];
            }
            return [
                'action' => 'apply',
                'item' => ['label' => $name . ' (' . $code . ')', 'name' => $name, 'sigla' => $code],
                'label' => $name,
            ];
        }

        $typedNorm = self::normalize($query);
        $results = self::search($query, 5);
        if ($results === []) {
            return ['action' => 'not_found'];
        }

        foreach ($results as $row) {
            if (self::normalize((string) $row['name']) === $typedNorm) {
                if ($row['name'] === $query) {
                    return ['action' => 'none'];
                }
                return ['action' => 'apply', 'item' => $row, 'label' => $row['name']];
            }
        }

        $top = $results[0];
        $canonical = (string) $top['name'];
        $canonicalNorm = self::normalize($canonical);
        if ($canonicalNorm === $typedNorm || self::placesSimilar($typedNorm, $canonicalNorm)) {
            return ['action' => 'apply', 'item' => $top, 'label' => $canonical];
        }

        return ['action' => 'confirm', 'item' => $top, 'label' => $canonical];
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($transliterated) && $transliterated !== '') {
            $value = mb_strtolower($transliterated);
        }
        $value = preg_replace('/[^a-z0-9\s\'-]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private static function placesSimilar(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        if (str_starts_with($b, $a) || str_starts_with($a, $b)) {
            return abs(mb_strlen($a) - mb_strlen($b)) <= 3;
        }
        if (strlen($a) <= 64 && strlen($b) <= 64) {
            return levenshtein($a, $b) <= 2;
        }
        return false;
    }
}
