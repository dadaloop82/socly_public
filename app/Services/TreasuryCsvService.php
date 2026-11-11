<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;

/**
 * Import/export CSV della prima nota: stesse colonne nei due versi, etichette tradotte in export
 * e riconoscimento flessibile in import (chiave, etichetta IT/EN/DE, importi con virgola).
 */
final class TreasuryCsvService
{
    /** Colonne gestite (chiave interna). */
    public const COLUMNS = [
        'movement_date', 'direction', 'amount', 'account', 'payment_method', 'category',
        'runts_line', 'budget_item', 'description', 'beneficiary', 'member', 'approved',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly TreasuryService $treasury
    ) {
    }

    /** @return array<string,string> chiave => intestazione localizzata */
    public function headers(): array
    {
        $labels = [];
        foreach (self::COLUMNS as $column) {
            $labels[$column] = (string) __('treasury.csv_' . $column);
        }

        return $labels;
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,string>>
     */
    public function exportRows(string $query, array $filters, int $limit = 5000): array
    {
        $rows = [];
        for ($offset = 0; $offset < $limit; $offset += 500) {
            $page = $this->treasury->ledger(500, $query, 'date_desc', $filters, $offset);
            $chunk = $page['movements'] ?? [];
            if ($chunk === []) {
                break;
            }
            $rows = array_merge($rows, $chunk);
            if (count($chunk) < 500) {
                break;
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $account = TreasuryService::normalizeAccount((string) ($row['account'] ?? ''));
            $method = TreasuryService::normalizePaymentMethod((string) ($row['payment_method'] ?? ''));
            $categoryKey = trim((string) ($row['category'] ?? ''));
            $runtsLine = strtoupper(trim((string) ($row['runts_line'] ?? '')));
            $memberName = trim((string) (($row['last_name'] ?? '') . ' ' . ($row['first_name'] ?? '')));
            $out[] = [
                'movement_date' => (string) ($row['movement_date'] ?? ''),
                'direction' => (string) ($row['direction'] ?? ''),
                'amount' => number_format((float) ($row['amount'] ?? 0), 2, ',', ''),
                'account' => $account !== '' ? $this->treasury->accountLabel($account) : '',
                'payment_method' => $method !== '' ? (string) __('treasury.method_' . $method) : '',
                'category' => $categoryKey !== '' ? $this->treasury->categoryLabel($categoryKey) : '',
                'runts_line' => $runtsLine !== '' ? RuntsReportService::lineLabel($runtsLine) : '',
                'budget_item' => !empty($row['budget_item_label']) ? (string) $row['budget_item_label'] : '',
                'description' => (string) ($row['description'] ?? ''),
                'beneficiary' => (string) ($row['beneficiary'] ?? ''),
                'member' => $memberName,
                'approved' => trim((string) ($row['approved_at'] ?? '')) !== '' ? '1' : '0',
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string,string>> $rows
     * @param array<string,string>|null $headers
     */
    public function toCsv(array $rows, ?array $headers = null): string
    {
        $headers = $headers ?? $this->headers();
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_values($headers), ';');
        foreach ($rows as $row) {
            $line = [];
            foreach (array_keys($headers) as $column) {
                $line[] = (string) ($row[$column] ?? '');
            }
            fputcsv($handle, $line, ';');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** CSV di esempio (intestazioni + una riga) per l'import. */
    public function templateCsv(): string
    {
        $accounts = $this->treasury->accountOptions();
        return $this->toCsv([[
            'movement_date' => date('Y-m-d'),
            'direction' => 'expense',
            'amount' => '123,45',
            'account' => (string) ($accounts[0]['label'] ?? ''),
            'payment_method' => (string) __('treasury.method_bank_transfer'),
            'category' => $this->treasury->categoryLabel('utilities'),
            'runts_line' => RuntsReportService::lineLabel('A-E2'),
            'budget_item' => '',
            'description' => (string) __('treasury.csv_example_description'),
            'beneficiary' => '',
            'member' => '',
            'approved' => '1',
        ]]);
    }

    /**
     * @return array{headers:list<string>,rows:list<array<string,string>>,map:array<string,string>,unmapped:list<string>}
     */
    public function parse(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return ['headers' => [], 'rows' => [], 'map' => [], 'unmapped' => []];
        }
        $firstLine = (string) fgets($handle);
        $delimiter = substr_count($firstLine, ',') > substr_count($firstLine, ';') ? ',' : ';';
        rewind($handle);
        $headers = [];
        $rows = [];
        $index = 0;
        while (($cells = fgetcsv($handle, 8192, $delimiter)) !== false) {
            $cells = array_map(static fn ($cell): string => trim(str_replace("\u{FEFF}", '', (string) $cell)), $cells);
            if ($index === 0) {
                $headers = $cells;
                $index++;
                continue;
            }
            if (array_filter($cells, static fn (string $cell): bool => $cell !== '') === []) {
                continue;
            }
            $row = [];
            foreach ($headers as $position => $header) {
                $row[$header] = $cells[$position] ?? '';
            }
            $rows[] = $row;
            $index++;
        }
        fclose($handle);

        $map = [];
        $unmapped = [];
        foreach ($headers as $header) {
            $field = $this->guessField($header);
            if ($field !== '' && !in_array($field, $map, true)) {
                $map[$header] = $field;
            } else {
                $unmapped[] = $header;
            }
        }

        return ['headers' => $headers, 'rows' => $rows, 'map' => $map, 'unmapped' => $unmapped];
    }

    /** Riconosce il campo dal nome della colonna (IT/EN/DE). */
    public function guessField(string $header): string
    {
        $key = mb_strtolower(trim($header));
        if (function_exists('iconv')) {
            $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $key);
            if (is_string($trans) && $trans !== '') {
                $key = $trans;
            }
        }
        $haystack = ' ' . (string) preg_replace('/[^a-z0-9]+/', ' ', $key) . ' ';
        $rules = [
            'movement_date' => ['data', 'date', 'datum'],
            'direction' => ['tipo', 'segno', 'direction', 'typ', 'entrata', 'uscita'],
            'amount' => ['importo', 'amount', 'betrag', 'somma'],
            'account' => ['conto', 'account', 'konto', 'cassa', 'banca'],
            'payment_method' => ['metodo', 'pagamento', 'method', 'zahlungsart'],
            'category' => ['tipologia', 'categoria', 'category', 'kategorie'],
            'runts_line' => ['voce', 'modello', 'runts', 'position'],
            'budget_item' => ['centro', 'costo', 'progetto', 'budget', 'kostenstelle'],
            'description' => ['descrizione', 'causale', 'description', 'beschreibung'],
            'beneficiary' => ['beneficiario', 'fornitore', 'beneficiary', 'empfanger'],
            'member' => ['socio', 'member', 'mitglied'],
            'approved' => ['approvato', 'approval', 'freigegeben', 'stato'],
        ];
        foreach ($rules as $field => $needles) {
            foreach ($needles as $needle) {
                // Corrispondenza su parola intera: “Tipologia” non deve finire in “Tipo”.
                if (str_contains($haystack, ' ' . $needle . ' ') || str_contains($haystack, ' ' . $needle)) {
                    if (str_contains($haystack, ' ' . $needle . ' ')) {
                        return $field;
                    }
                }
            }
        }

        return '';
    }

    /**
     * Importa le righe già mappate. Ogni riga passa da TreasuryService::create(), quindi valgono
     * le stesse regole della prima nota (conti, tipologie, voce Modello D, importi).
     *
     * @param list<array<string,string>> $rows
     * @param array<string,string> $map colonna CSV => campo
     * @return array{created:int,skipped:int,errors:list<string>}
     */
    public function importRows(array $rows, array $map, string $ip, ?int $userId = null): array
    {
        $created = 0;
        $skipped = 0;
        $errors = [];
        $accounts = $this->treasury->accountOptions();
        $budgetOptions = $this->treasury->budgetItemFilterOptions();
        $line = 1;
        foreach ($rows as $row) {
            $line++;
            $get = static function (string $field) use ($row, $map): string {
                foreach ($map as $column => $mapped) {
                    if ($mapped === $field) {
                        return trim((string) ($row[$column] ?? ''));
                    }
                }

                return '';
            };
            $date = $this->parseDate($get('movement_date'));
            $amount = $this->parseAmount($get('amount'));
            $direction = $this->parseDirection($get('direction'));
            if ($date === '' || $amount <= 0 || $direction === '') {
                $skipped++;
                $errors[] = __('treasury.csv_error_row', ['row' => (string) $line]);
                continue;
            }
            $account = $this->matchAccount($get('account'), $accounts);
            $method = $this->matchMethod($get('payment_method'));
            $categoryRaw = $get('category');
            $category = $categoryRaw !== '' ? ($this->treasury->resolveCategoryFromLabel($categoryRaw) ?? 'other') : 'membership_fee';
            $runtsRaw = $get('runts_line');
            $runtsLine = $this->matchRuntsLine($runtsRaw);
            $budgetItemId = $this->matchBudgetItem($get('budget_item'), $budgetOptions);
            $memberId = $this->matchMember($get('member'));
            $result = $this->treasury->create([
                'direction' => $direction,
                'amount' => (string) $amount,
                'movement_date' => $date,
                'account' => $account,
                'payment_method' => $method,
                'category' => $category,
                'new_category' => $category === 'other' && $categoryRaw !== '' ? $categoryRaw : '',
                'runts_line' => $runtsLine,
                'budget_item_id' => $budgetItemId > 0 ? (string) $budgetItemId : '',
                'description' => $get('description'),
                'beneficiary' => $get('beneficiary'),
                'member_id' => $memberId > 0 ? (string) $memberId : '',
                'member_involved' => $memberId > 0 ? '1' : '',
            ], null, $ip, $userId);
            if (empty($result['ok'])) {
                $skipped++;
                $reason = implode(' ', array_map('strval', (array) ($result['errors'] ?? [])));
                $errors[] = __('treasury.csv_error_row_detail', ['row' => (string) $line, 'error' => $reason]);
                continue;
            }
            $approved = mb_strtolower($get('approved'));
            if (!in_array($approved, ['1', 'si', 'sì', 'yes', 'ja', 'true'], true)) {
                $this->db->update('treasury_movements', ['approved_at' => null, 'approved_by' => null], 'id = :id', ['id' => (int) ($result['id'] ?? 0)]);
            }
            $created++;
        }
        if ($created > 0) {
            app('logger')->info('treasury.csv_imported', ['created' => $created, 'skipped' => $skipped]);
        }

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    private function parseDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        foreach (['Y-m-d', 'd/m/Y', 'd.m.Y', 'd-m-Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $raw);
            if ($date instanceof \DateTimeImmutable) {
                return $date->format('Y-m-d');
            }
        }

        return '';
    }

    private function parseAmount(string $raw): float
    {
        $raw = trim($raw);
        $raw = str_replace(['€', 'EUR', ' '], '', $raw);
        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            $raw = str_replace('.', '', $raw);
        }
        $raw = str_replace(',', '.', $raw);

        return (float) $raw;
    }

    private function parseDirection(string $raw): string
    {
        $key = mb_strtolower(trim($raw));
        $in = ['entrata', 'entrate', 'income', 'einnahme', 'einnahmen', '+', 'in'];
        $out = ['uscita', 'uscite', 'expense', 'ausgabe', 'ausgaben', '-', 'out'];
        if (in_array($key, $in, true)) {
            return 'income';
        }
        if (in_array($key, $out, true)) {
            return 'expense';
        }

        return '';
    }

    /** @param list<array{key:string,label:string}> $accounts */
    private function matchAccount(string $raw, array $accounts): string
    {
        $needle = mb_strtolower(trim($raw));
        if ($needle === '') {
            return '';
        }
        $slug = TreasuryService::normalizeAccount($needle);
        foreach ($accounts as $account) {
            $key = TreasuryService::normalizeAccount((string) $account['key']);
            $label = mb_strtolower(trim((string) $account['label']));
            if ($needle === mb_strtolower($key) || $needle === $label) {
                return $key;
            }
        }
        // “Banca”, “Contanti”, “Cassa”…: corrispondenza parziale sull'etichetta.
        foreach ($accounts as $account) {
            $key = TreasuryService::normalizeAccount((string) $account['key']);
            $label = mb_strtolower(trim((string) $account['label']));
            if ($label !== '' && (str_contains($label, $needle) || str_contains($needle, $label))) {
                return $key;
            }
        }

        return $slug;
    }

    private function matchMethod(string $raw): string
    {
        $needle = mb_strtolower(trim($raw));
        if ($needle === '') {
            return '';
        }
        $slug = TreasuryService::normalizePaymentMethod($needle);
        if ($slug !== '') {
            return $slug;
        }
        foreach (TreasuryService::METHODS as $method) {
            if ($needle === mb_strtolower((string) __('treasury.method_' . $method))) {
                return $method;
            }
        }

        return '';
    }

    private function matchRuntsLine(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $upper = strtoupper($raw);
        if (preg_match('/^[A-E]-[EI]\d+$/', $upper) === 1) {
            return $upper;
        }
        foreach (['expense', 'income'] as $direction) {
            foreach (RuntsReportService::selectGroups($direction) as $group) {
                foreach (($group['options'] ?? []) as $option) {
                    if (mb_strtolower(trim((string) $option['label'])) === mb_strtolower($raw)) {
                        return (string) $option['value'];
                    }
                }
            }
        }

        return '';
    }

    /** @param list<array{value:string,label:string}> $options */
    private function matchBudgetItem(string $raw, array $options): int
    {
        $needle = mb_strtolower(trim($raw));
        if ($needle === '') {
            return 0;
        }
        foreach ($options as $option) {
            $label = mb_strtolower(trim((string) $option['label']));
            if ($label === $needle || str_contains($label, $needle)) {
                return (int) $option['value'];
            }
        }

        return 0;
    }

    private function matchMember(string $raw): int
    {
        $needle = trim($raw);
        if ($needle === '') {
            return 0;
        }
        $row = $this->db->fetch(
            'SELECT id FROM members WHERE member_number = :m LIMIT 1',
            ['m' => $needle]
        );
        if ($row !== null) {
            return (int) $row['id'];
        }

        return 0;
    }
}
