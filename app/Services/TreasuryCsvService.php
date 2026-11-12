<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;
use Socly\Support\CsvReader;
use Socly\Support\ImportValueNormalizer;

/**
 * Import/export CSV of the first note.
 * The export produces the same columns the import understands (translated labels). The import
 * follows the member import flow: upload → column mapping → value normalisation → dry run →
 * final report. The file stays in `storage/uploads` and nothing is written to the first note
 * before the explicit confirmation.
 */
final class TreasuryCsvService
{
    /**
     * Managed columns (internal key).
     */
    public const COLUMNS = [
        'movement_date', 'direction', 'amount', 'account', 'transfer_account', 'payment_method', 'category',
        'runts_line', 'budget_item', 'description', 'document_type', 'invoice_number', 'invoice_date', 'beneficiary',
        'member', 'approved',
    ];

    /**
     * First-note fields a CSV column can be mapped to.
     */
    public const IMPORT_TARGETS = self::COLUMNS;

    /**
     * Fields whose CSV values can be normalised (raw CSV value → program value).
     */
    private const NORMALIZATION_FIELDS = [
        'direction', 'approved', 'account', 'transfer_account', 'payment_method',
        'category', 'runts_line', 'budget_item', 'document_type', 'member',
    ];

    /**
     * Fields the dry run lets the user correct by hand: the ones that can make a row fail.
     */
    private const EDITABLE_FIELDS = [
        'movement_date', 'direction', 'amount', 'account', 'transfer_account',
        'description', 'document_type', 'invoice_number', 'invoice_date',
    ];

    /**
     * Week/month distance between two occurrences → recurring frequency.
     */
    private const RECURRING_MONTHS = [1 => 'monthly', 3 => 'quarterly', 6 => 'semiannual', 12 => 'yearly'];

    private const SESSION_KEY = '_treasury_csv_import';
    private const REPORT_KEY = '_treasury_csv_import_report';
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const MAX_ROWS = 20000;
    /**
     * Rows shown in preview (statistics always cover entire file).
     */
    private const PREVIEW_ROWS = 200;
    private const SAMPLE_ROWS = 40;

    /** @var array{by_key:array<string,int>,labels:array<int,string>}|null */
    private ?array $memberLookupCache = null;

    /** @var array<string,array<string,string>>|null */
    private ?array $valueMapCache = null;

    public function __construct(
        private readonly Database $db,
        private readonly TreasuryService $treasury,
        private readonly RecurringService $recurring
    ) {
    }

    /** @return array<string,string> key => localized header */
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
            if (trim((string) ($row['superseded_at'] ?? '')) !== '') {
                // The transferred originals remain in the history but are not re-exported: the copy on the
                // destination account is the valid line.
                continue;
            }
            $account = TreasuryService::normalizeAccount((string) ($row['account'] ?? ''));
            $transferAccount = TreasuryService::normalizeAccount((string) ($row['transfer_account'] ?? ''));
            $method = TreasuryService::normalizePaymentMethod((string) ($row['payment_method'] ?? ''));
            $categoryKey = trim((string) ($row['category'] ?? ''));
            $documentTypeKey = TreasuryService::normalizeDocumentType((string) ($row['document_type'] ?? ''));
            $runtsLine = strtoupper(trim((string) ($row['runts_line'] ?? '')));
            $memberName = trim((string) (($row['last_name'] ?? '') . ' ' . ($row['first_name'] ?? '')));
            $out[] = [
                'movement_date' => (string) ($row['movement_date'] ?? ''),
                'direction' => (string) ($row['direction'] ?? ''),
                'amount' => number_format((float) ($row['amount'] ?? 0), 2, ',', ''),
                'account' => $account !== '' ? $this->treasury->accountLabel($account) : '',
                'transfer_account' => $transferAccount !== '' ? $this->treasury->accountLabel($transferAccount) : '',
                'payment_method' => $method !== '' ? (string) __('treasury.method_' . $method) : '',
                'category' => $categoryKey !== '' ? $this->treasury->categoryLabel($categoryKey) : '',
                'runts_line' => $runtsLine !== '' ? RuntsReportService::lineLabel($runtsLine) : '',
                'budget_item' => !empty($row['budget_item_label']) ? (string) $row['budget_item_label'] : '',
                'description' => (string) ($row['description'] ?? ''),
                'document_type' => $documentTypeKey !== ''
                    ? (string) __('treasury.document_type_' . $documentTypeKey)
                    : '',
                'invoice_number' => (string) ($row['invoice_number'] ?? ''),
                'invoice_date' => (string) ($row['invoice_date'] ?? ''),
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

    /**
     * Sample CSV (headers + one line) for import.
     */
    public function templateCsv(): string
    {
        $accounts = $this->treasury->accountOptions();
        return $this->toCsv([[
            'movement_date' => date('Y-m-d'),
            'direction' => 'expense',
            'amount' => '123,45',
            'account' => (string) ($accounts[0]['label'] ?? ''),
            'transfer_account' => '',
            'payment_method' => (string) __('treasury.method_bank_transfer'),
            'category' => $this->treasury->categoryLabel('utilities'),
            'runts_line' => RuntsReportService::lineLabel('A-E2'),
            'budget_item' => '',
            'description' => (string) __('treasury.csv_example_description'),
            'document_type' => (string) __('treasury.document_type_invoice'),
            'invoice_number' => '',
            'invoice_date' => '',
            'beneficiary' => '',
            'member' => '',
            'approved' => '1',
        ]]);
    }

    // ── Import: wizard status ─────────────────────── ───────────────────────

    /** @return array<string,mixed>|null */
    public function state(): ?array
    {
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($state)) {
            return null;
        }
        $path = $this->absolutePath((string) ($state['file'] ?? ''));
        if ($path === null || !is_file($path)) {
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function saveState(array $state): void
    {
        $_SESSION[self::SESSION_KEY] = $state;
    }

    public function reset(bool $clearReport = true): void
    {
        $this->valueMapCache = null;
        $state = $this->state();
        if ($state !== null) {
            $path = $this->absolutePath((string) ($state['file'] ?? ''));
            if ($path !== null && is_file($path)) {
                unlink($path);
            }
        }
        unset($_SESSION[self::SESSION_KEY]);
        if ($clearReport) {
            unset($_SESSION[self::REPORT_KEY]);
        }
    }

    /**
     * “First line contains headers” switch.
     */
    public function setHasHeader(bool $hasHeader): void
    {
        $state = $this->state();
        if ($state === null) {
            return;
        }
        $state['has_header'] = $hasHeader;
        $this->saveState($state);
    }

    private function absolutePath(string $relative): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        return storage_path('uploads/' . $relative);
    }

    private function reader(): ?CsvReader
    {
        $state = $this->state();
        $path = $this->absolutePath((string) ($state['file'] ?? ''));
        if ($path === null || !is_file($path)) {
            return null;
        }

        return new CsvReader($path);
    }

    /**
     * Saves the loaded CSV to storage and keeps only the references in session.
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int}|null $file
     * @return array{ok:bool,error?:string}
     */
    public function startFromUpload(?array $file): array
    {
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'treasury.import.error_missing'];
        }
        if ((int) ($file['size'] ?? 0) <= 0) {
            return ['ok' => false, 'error' => 'treasury.import.error_empty'];
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'treasury.import.error_too_large'];
        }
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'txt', 'tsv', 'dat'], true)) {
            return ['ok' => false, 'error' => 'treasury.import.error_extension'];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            return ['ok' => false, 'error' => 'treasury.import.error_missing'];
        }

        $paths = user_upload_paths('treasury-import');
        if (!is_dir($paths['absolute']) || !is_writable($paths['absolute'])) {
            $fallback = 'treasury-import';
            $fallbackDir = storage_path('uploads/' . $fallback);
            ensure_directory($fallbackDir);
            if (!is_writable($fallbackDir)) {
                return ['ok' => false, 'error' => 'treasury.import.error_store'];
            }
            $paths = ['absolute' => $fallbackDir, 'relative' => $fallback];
        }
        $filename = bin2hex(random_bytes(12)) . '.csv';
        $target = $paths['absolute'] . '/' . $filename;
        $stored = false;
        try {
            if (is_uploaded_file($tmp)) {
                $stored = move_uploaded_file($tmp, $target);
            } elseif (PHP_SAPI === 'cli' && is_file($tmp)) {
                $stored = copy($tmp, $target);
            }
        } catch (\Throwable $e) {
            try {
                app('logger')->error('treasury.import.upload_failed', ['error' => $e->getMessage()]);
            } catch (\Throwable) {
            }
            $stored = false;
        }
        if (!$stored) {
            return ['ok' => false, 'error' => 'treasury.import.error_store'];
        }

        $reader = new CsvReader($target);
        $rows = $reader->rows(self::MAX_ROWS + 1);
        if ($rows === []) {
            unlink($target);
            return ['ok' => false, 'error' => 'treasury.import.error_empty'];
        }
        if (count($rows) > self::MAX_ROWS) {
            unlink($target);
            return ['ok' => false, 'error' => 'treasury.import.error_too_many_rows'];
        }

        $this->reset();
        $this->saveState([
            'file' => $paths['relative'] . '/' . $filename,
            'source_name' => mb_substr((string) ($file['name'] ?? 'prima-nota.csv'), 0, 200),
            'delimiter' => $reader->delimiter(),
            'encoding' => $reader->encoding(),
            'has_header' => $reader->hasHeader($rows),
            'map' => [],
            'options' => $this->normalizeOptions([]),
            'created_at' => date('c'),
        ]);

        return ['ok' => true];
    }

    /**
     * Table read from CSV (headers + data rows).
     *
     * @return array{ok:bool,error?:string,headers:list<string>,rows:list<array{line:int,cells:list<string>}>,has_header:bool,delimiter:string,encoding:string,delimiter_label:string}
     */
    public function table(): array
    {
        $state = $this->state();
        $reader = $this->reader();
        if ($state === null || $reader === null) {
            return [
                'ok' => false,
                'error' => 'treasury.import.error_no_file',
                'headers' => [],
                'rows' => [],
                'has_header' => false,
                'delimiter' => '',
                'encoding' => '',
                'delimiter_label' => '',
            ];
        }
        $raw = $reader->rows(self::MAX_ROWS + 1);
        $hasHeader = !empty($state['has_header']);
        $headers = [];
        $rows = $raw;
        if ($hasHeader && $raw !== []) {
            $headers = array_values(array_map('strval', (array) ($raw[0]['cells'] ?? [])));
            $rows = array_slice($raw, 1);
        }
        if ($headers === []) {
            $width = 0;
            foreach (array_slice($raw, 0, self::SAMPLE_ROWS) as $row) {
                $width = max($width, count($row['cells']));
            }
            for ($i = 0; $i < $width; $i++) {
                $headers[] = (string) __('treasury.import.column_n', ['n' => (string) ($i + 1)]);
            }
        }
        $width = count($headers);
        $rows = array_map(
            static function (array $row) use ($width): array {
                $cells = array_slice(array_pad($row['cells'], $width, ''), 0, $width);
                return ['line' => (int) ($row['line'] ?? 0), 'cells' => array_map('strval', $cells)];
            },
            $rows
        );

        return [
            'ok' => true,
            'headers' => $headers,
            'rows' => $rows,
            'has_header' => $hasHeader,
            'delimiter' => $reader->delimiter(),
            'encoding' => $reader->encoding(),
            'delimiter_label' => $reader->delimiterLabel(),
        ];
    }

    /**
     * One entry per CSV column: data type, example and suggested field.
     *
     * @param array{headers:list<string>,rows:list<array{line:int,cells:list<string>}>,has_header:bool} $table
     * @return list<array{index:int,header:string,suggested:string,sample:string,kind:string,filled:int,rows:int}>
     */
    public function columns(array $table): array
    {
        $columns = [];
        $used = [];
        foreach (array_values($table['headers']) as $index => $header) {
            $values = [];
            foreach ($table['rows'] as $row) {
                $values[] = (string) ($row['cells'][$index] ?? '');
            }
            $sample = '';
            $filled = 0;
            foreach ($values as $value) {
                if (trim($value) === '') {
                    continue;
                }
                $filled++;
                if ($sample === '') {
                    $sample = mb_strimwidth(ImportValueNormalizer::text($value), 0, 60, '…');
                }
            }
            $suggested = !empty($table['has_header']) ? $this->guessField((string) $header) : '';
            if ($suggested !== '' && isset($used[$suggested])) {
                $suggested = '';
            }
            if ($suggested !== '') {
                $used[$suggested] = true;
            }
            $columns[] = [
                'index' => (int) $index,
                'header' => (string) $header,
                'suggested' => $suggested,
                'sample' => $sample,
                'kind' => ImportValueNormalizer::detectColumnKind($values),
                'filled' => $filled,
                'rows' => count($values),
            ];
        }

        return $columns;
    }

    /**
     * First note fields grouped for the matching menu.
     *
     * @return list<array{label:string,options:list<array{value:string,label:string}>}>
     */
    public function targetGroups(): array
    {
        $labels = $this->headers();
        $group = static function (array $keys) use ($labels): array {
            $options = [];
            foreach ($keys as $key) {
                $options[] = ['value' => $key, 'label' => (string) ($labels[$key] ?? $key)];
            }
            return $options;
        };

        return [
            [
                'label' => (string) __('treasury.import.group_movement'),
                'options' => $group(['movement_date', 'direction', 'amount', 'approved']),
            ],
            [
                'label' => (string) __('treasury.import.group_wallet'),
                'options' => $group(['account', 'transfer_account', 'payment_method']),
            ],
            [
                'label' => (string) __('treasury.import.group_classification'),
                'options' => $group(['category', 'runts_line', 'budget_item']),
            ],
            [
                'label' => (string) __('treasury.import.group_details'),
                'options' => $group(['description', 'document_type', 'invoice_number', 'invoice_date', 'beneficiary', 'member']),
            ],
        ];
    }

    /** @return array<int,string> column index => selected field */
    public function mappedTargets(): array
    {
        $state = $this->state();
        $map = is_array($state['map'] ?? null) ? $state['map'] : [];
        $out = [];
        foreach ($map as $index => $target) {
            $out[(int) $index] = (string) $target;
        }

        return $out;
    }

    /**
     * Save the columns → fields pairing and the import options.
     *
     * @param array<string|int,mixed> $rawMap
     * @param array<string,mixed> $rawOptions
     * @return array{ok:bool,error?:string,field?:string}
     */
    public function saveMapping(array $rawMap, array $rawOptions): array
    {
        $state = $this->state();
        if ($state === null) {
            return ['ok' => false, 'error' => 'treasury.import.error_no_file'];
        }
        $labels = $this->headers();
        $map = [];
        $used = [];
        foreach ($rawMap as $index => $target) {
            $index = (int) $index;
            $target = trim((string) $target);
            if ($target === '' || $target === ImportValueNormalizer::TARGET_IGNORE) {
                // We save the explicit ignoring: upon reload the column remains at "Ignore".
                $map[$index] = ImportValueNormalizer::TARGET_IGNORE;
                continue;
            }
            if (!in_array($target, self::IMPORT_TARGETS, true)) {
                continue;
            }
            if (isset($used[$target])) {
                return [
                    'ok' => false,
                    'error' => 'treasury.import.error_duplicate_target',
                    'field' => (string) ($labels[$target] ?? $target),
                ];
            }
            $used[$target] = true;
            $map[$index] = $target;
        }
        foreach (['movement_date', 'amount'] as $required) {
            if (!in_array($required, $map, true)) {
                return [
                    'ok' => false,
                    'error' => 'treasury.import.error_need_field',
                    'field' => (string) ($labels[$required] ?? $required),
                ];
            }
        }
        $previousMap = is_array($state['map'] ?? null) ? $state['map'] : [];
        $state['map'] = $map;
        $state['options'] = $this->normalizeOptions($rawOptions);
        // A changed mapping invalidates the previous value normalisation.
        if ($previousMap !== $map) {
            $state['value_map'] = [];
            $this->valueMapCache = null;
        }
        $this->saveState($state);

        return ['ok' => true];
    }

    /**
     * Default values used when a column is not in the file.
     *
     * @return array{default_direction:string,default_account:string,default_category:string,default_payment_method:string,approve:string,skip_duplicates:string}
     */
    public function options(): array
    {
        $state = $this->state();
        $raw = is_array($state['options'] ?? null) ? $state['options'] : [];

        return $this->normalizeOptions($raw);
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{default_direction:string,default_account:string,default_category:string,default_payment_method:string,approve:string,skip_duplicates:string}
     */
    private function normalizeOptions(array $raw): array
    {
        $accounts = $this->treasury->accountOptions(false);
        $activeAccounts = array_values(array_filter($accounts, static fn (array $account): bool => !empty($account['active'])));
        $validAccounts = array_map(static fn (array $account): string => (string) $account['key'], $activeAccounts);
        $activeAccount = (string) ($activeAccounts[0]['key'] ?? '');
        $account = TreasuryService::normalizeAccount((string) ($raw['default_account'] ?? ''));
        if ($account === '' || !in_array($account, $validAccounts, true)) {
            $account = $activeAccount;
        }
        $category = trim((string) ($raw['default_category'] ?? ''));
        if (!in_array($category, array_column($this->treasury->categoryOptions(), 'key'), true)) {
            $category = 'other';
        }
        $direction = (string) ($raw['default_direction'] ?? '');
        if (!in_array($direction, ['income', 'expense'], true)) {
            $direction = 'expense';
        }

        return [
            'default_direction' => $direction,
            'default_account' => $account,
            'default_category' => $category,
            'default_payment_method' => $this->matchMethod((string) ($raw['default_payment_method'] ?? '')),
            'approve' => array_key_exists('approve', $raw) && (string) $raw['approve'] === '0' ? '0' : '1',
            'skip_duplicates' => array_key_exists('skip_duplicates', $raw) && (string) $raw['skip_duplicates'] === '0' ? '0' : '1',
        ];
    }

    /**
     * Raw-value → program-value map saved in the wizard state.
     *
     * @return array<string,array<string,string>>
     */
    public function valueMap(): array
    {
        if ($this->valueMapCache !== null) {
            return $this->valueMapCache;
        }
        $state = $this->state();
        $map = is_array($state['value_map'] ?? null) ? $state['value_map'] : [];
        $out = [];
        foreach ($map as $field => $pairs) {
            if (!is_array($pairs)) {
                continue;
            }
            foreach ($pairs as $raw => $target) {
                $out[(string) $field][(string) $raw] = (string) $target;
            }
        }

        return $this->valueMapCache = $out;
    }

    /**
     * Unique CSV values for every mapped field, with the program options and a suggested match.
     *
     * @return array{ok:bool,error?:string,fields:list<array{field:string,label:string,values:list<array{index:int,raw:string,group:string,variants:list<string>,count:int,suggested:string,options:list<array{value:string,label:string}>}>}>}
     */
    public function normalization(): array
    {
        $result = ['ok' => false, 'fields' => []];
        $state = $this->state();
        $table = $this->table();
        if ($state === null || empty($table['ok'])) {
            $result['error'] = (string) ($table['error'] ?? 'treasury.import.error_no_file');
            return $result;
        }
        $map = is_array($state['map'] ?? null) ? $state['map'] : [];
        if ($map === []) {
            $result['error'] = 'treasury.import.error_no_mapping';
            return $result;
        }

        $labels = $this->headers();
        foreach (self::NORMALIZATION_FIELDS as $field) {
            $index = array_search($field, $map, true);
            if ($index === false) {
                continue;
            }
            // Values are grouped ignoring case, accents and symbols: “Contanti”, “contanti”,
            // “.Contanti” are the same value and must stay on one row.
            $counts = [];
            $variants = [];
            foreach ($table['rows'] as $row) {
                $raw = trim((string) ($row['cells'][(int) $index] ?? ''));
                if ($raw === '') {
                    continue;
                }
                $key = $this->valueGroupKey($raw);
                $counts[$key] = (int) ($counts[$key] ?? 0) + 1;
                $variants[$key][$raw] = (int) ($variants[$key][$raw] ?? 0) + 1;
            }
            if ($counts === []) {
                continue;
            }
            arsort($counts, SORT_NUMERIC);
            $baseOptions = $field === 'member' ? [] : $this->valueOptions($field);
            $noneLabel = $this->valueNoneLabel($field);
            $includeNone = $field !== 'approved';
            $values = [];
            $entry = 0;
            foreach ($counts as $key => $count) {
                $key = (string) $key;
                $spellings = array_keys($variants[$key] ?? []);
                $raw = $this->representativeValue($variants[$key] ?? []);
                // Symbols around the value must not break the automatic match ('.Contanti').
                $clean = trim((string) preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $raw));
                $options = $baseOptions;
                if ($includeNone) {
                    array_unshift($options, ['value' => '__none__', 'label' => $noneLabel]);
                }
                if ($field === 'category') {
                    $options[] = [
                        'value' => '__new__',
                        'label' => (string) __('treasury.import.value_new_category', ['label' => $raw]),
                    ];
                }
                $values[] = [
                    'index' => $entry,
                    'raw' => $raw,
                    'group' => $key,
                    'variants' => count($spellings) > 1 ? array_map('strval', $spellings) : [],
                    'count' => (int) $count,
                    'suggested' => $this->valueSuggestion($field, $clean !== '' ? $clean : $raw),
                    'options' => $options,
                ];
                $entry++;
            }
            $result['fields'][] = [
                'field' => $field,
                'label' => (string) ($labels[$field] ?? $field),
                'values' => $values,
            ];
        }
        $result['ok'] = true;

        return $result;
    }

    /**
     * Choices made in the dry run: manual corrections, ignored rows, duplicates to include anyway and
     * the recurring series to set up. They only change the analysis, never the first note.
     *
     * @param array<string,mixed> $raw whole payload of the dry-run adjustment form
     * @return array{ok:bool,error?:string}
     */
    public function saveRowChoices(array $raw): array
    {
        $state = $this->state();
        if ($state === null) {
            return ['ok' => false, 'error' => 'treasury.import.error_no_file'];
        }
        // Only the rows rendered in the preview are replaced: choices already made for rows outside
        // the shown slice stay untouched.
        $present = $this->lineFlags($raw['row_present'] ?? []);
        $previous = $this->rowChoices();
        $fixes = $previous['fixes'];
        $ignored = $previous['ignored'];
        $included = $previous['included'];
        foreach (array_keys($present) as $line) {
            unset($fixes[$line], $ignored[$line], $included[$line]);
        }
        $reset = is_array($raw['fix_reset'] ?? null) ? $raw['fix_reset'] : [];
        foreach ((array) ($raw['fix'] ?? []) as $line => $fields) {
            $line = (int) $line;
            if ($line < 1 || !isset($present[$line]) || !is_array($fields) || !empty($reset[$line])) {
                continue;
            }
            foreach ($fields as $field => $value) {
                $field = (string) $field;
                if (!in_array($field, self::EDITABLE_FIELDS, true)) {
                    continue;
                }
                $value = mb_substr(trim((string) $value), 0, 500);
                if ($value === '') {
                    // Empty field = back to the value found in the CSV.
                    continue;
                }
                $fixes[$line][$field] = $value;
            }
        }
        $ignored += $this->lineFlags($raw['ignore'] ?? []);
        $included += $this->lineFlags($raw['include'] ?? []);
        ksort($fixes);
        ksort($ignored);
        ksort($included);

        $state['fixes'] = $fixes;
        $state['ignored'] = $ignored;
        $state['included'] = $included;
        $recurring = [];
        foreach ((array) ($raw['recurring_keys'] ?? []) as $key) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }
            $recurring[$key] = !empty($raw['recurring'][$key]) ? '1' : '0';
        }
        $state['recurring'] = $recurring;
        $this->saveState($state);

        return ['ok' => true];
    }

    /**
     * @param mixed $raw
     * @return array<int,true>
     */
    private function lineFlags($raw): array
    {
        $out = [];
        foreach ((array) $raw as $line => $flag) {
            $line = (int) $line;
            if ($line > 0 && (string) $flag === '1') {
                $out[$line] = true;
            }
        }

        return $out;
    }

    /**
     * @return array{fixes:array<int,array<string,string>>,ignored:array<int,true>,included:array<int,true>,recurring:array<string,string>}
     */
    private function rowChoices(): array
    {
        $state = $this->state();
        $fixes = [];
        foreach ((array) ($state['fixes'] ?? []) as $line => $fields) {
            if (!is_array($fields) || $fields === []) {
                continue;
            }
            foreach ($fields as $field => $value) {
                $fixes[(int) $line][(string) $field] = (string) $value;
            }
        }
        $recurring = [];
        foreach ((array) ($state['recurring'] ?? []) as $key => $value) {
            $recurring[(string) $key] = (string) $value === '1' ? '1' : '0';
        }

        return [
            'fixes' => $fixes,
            'ignored' => $this->lineFlags($state['ignored'] ?? []),
            'included' => $this->lineFlags($state['included'] ?? []),
            'recurring' => $recurring,
        ];
    }

    // @@VALUE_HELPERS@@

    /** @return list<array{value:string,label:string}> */
    private function valueOptions(string $field): array
    {
        $out = [];
        switch ($field) {
            case 'direction':
                return [
                    ['value' => 'income', 'label' => (string) __('treasury.direction_sign_income')],
                    ['value' => 'expense', 'label' => (string) __('treasury.direction_sign_expense')],
                    ['value' => 'transfer', 'label' => (string) __('treasury.direction_sign_transfer')],
                ];
            case 'approved':
                return [
                    ['value' => '1', 'label' => (string) __('treasury.import.value_yes')],
                    ['value' => '0', 'label' => (string) __('treasury.import.value_no')],
                ];
            case 'account':
            case 'transfer_account':
                foreach ($this->treasury->accountOptions(true) as $account) {
                    $out[] = ['value' => (string) $account['key'], 'label' => (string) $account['label']];
                }
                return $out;
            case 'payment_method':
                foreach ($this->treasury->paymentMethodGroups() as $group) {
                    foreach ((array) ($group['keys'] ?? []) as $method) {
                        $out[] = ['value' => (string) $method, 'label' => (string) __('treasury.method_' . $method)];
                    }
                }
                return $out;
            case 'category':
                foreach ($this->treasury->categoryOptions() as $category) {
                    $out[] = ['value' => (string) $category['key'], 'label' => (string) $category['label']];
                }
                return $out;
            case 'runts_line':
                // The section is part of the label: “1) Servizi” exists in several sections.
                return RuntsReportService::lineOptions();
            case 'budget_item':
                foreach ($this->treasury->budgetItemFilterOptions() as $option) {
                    $out[] = ['value' => (string) $option['value'], 'label' => (string) $option['label']];
                }
                return $out;
            case 'document_type':
                return $this->documentTypeOptions();
            case 'member':
                return $this->memberChoices();
        }

        return [];
    }

    private function valueNoneLabel(string $field): string
    {
        return match ($field) {
            'account' => (string) __('treasury.import.value_default_account'),
            'transfer_account' => (string) __('treasury.import.value_no_transfer_account'),
            'payment_method' => (string) __('treasury.import.value_no_method'),
            'category' => (string) __('treasury.import.value_default_category'),
            'runts_line' => (string) __('treasury.import.value_derive_runts'),
            'budget_item' => (string) __('treasury.import.value_no_budget'),
            'document_type' => (string) __('treasury.import.value_no_document_type'),
            'member' => (string) __('treasury.import.value_no_member'),
            'direction' => (string) __('treasury.import.value_default_direction'),
            default => (string) __('treasury.import.value_none'),
        };
    }

    /**
     * Grouping key of a CSV value: case, accents, punctuation and symbols are ignored, so “Contanti”,
     * “contanti”, “conTanti” and “.Contanti” are the same value. Values made only of symbols fall back
     * to the lowercased text.
     */
    private function valueGroupKey(string $raw): string
    {
        $key = ImportValueNormalizer::matchKey($raw);
        if ($key !== '') {
            return $key;
        }
        $text = mb_strtolower(ImportValueNormalizer::text($raw), 'UTF-8');

        return $text !== '' ? '~' . $text : '~';
    }

    /**
     * Spelling shown for a group of equivalent values: the most frequent, preferring a clean one
     * (no leading symbol) and avoiding a fully uppercase variant when another one exists.
     *
     * @param array<string,int> $variants raw spelling => rows
     */
    private function representativeValue(array $variants): string
    {
        $best = '';
        $bestScore = -1;
        foreach ($variants as $raw => $count) {
            $raw = (string) $raw;
            $score = (int) $count * 10;
            if (preg_match('/^[\p{L}\p{N}]/u', $raw) === 1) {
                $score += 3;
            }
            if (preg_match('/\p{L}/u', $raw) === 1 && $raw === mb_strtoupper($raw, 'UTF-8')) {
                $score -= 1;
            }
            if ($score > $bestScore || ($score === $bestScore && strcmp($raw, $best) < 0)) {
                $bestScore = $score;
                $best = $raw;
            }
        }

        return $best;
    }

    // @@VALUE_SAVE@@

    /**
     * Save the raw-value normalisation (field → raw → program value).
     *
     * @param array<string,mixed> $rawValues
     * @param array<string,mixed> $targets
     * @return array{ok:bool,error?:string}
     */
    public function saveNormalization(array $rawValues, array $targets): array
    {
        $state = $this->state();
        if ($state === null) {
            return ['ok' => false, 'error' => 'treasury.import.error_no_file'];
        }
        $allowed = [];
        foreach (self::NORMALIZATION_FIELDS as $field) {
            $allowed[$field] = array_map(
                static fn (array $option): string => (string) $option['value'],
                $this->valueOptions($field)
            );
        }
        $map = [];
        foreach ($rawValues as $field => $list) {
            $field = (string) $field;
            if (!in_array($field, self::NORMALIZATION_FIELDS, true) || !is_array($list)) {
                continue;
            }
            foreach ($list as $index => $raw) {
                $raw = trim((string) $raw);
                if ($raw === '') {
                    continue;
                }
                $target = trim((string) ($targets[$field][$index] ?? ''));
                if ($target === '') {
                    $target = $field === 'member' ? '__none__' : $this->valueSuggestion($field, $raw);
                }
                if ($field === 'member' && $target !== '__none__') {
                    $lookup = $this->memberLookup();
                    $memberId = (int) ($lookup['by_key'][mb_strtolower($target)] ?? 0);
                    if ($memberId < 1 && ctype_digit($target) && isset($lookup['labels'][(int) $target])) {
                        $memberId = (int) $target;
                    }
                    $target = $memberId > 0 ? (string) $memberId : '__none__';
                }
                $valid = $target === '__none__'
                    || ($field === 'category' && $target === '__new__')
                    || in_array($target, $allowed[$field], true);
                if ($valid) {
                    $map[$field][$raw] = $target;
                    // The same target applies to every spelling of the value (“contanti”, “.Contanti”).
                    $groupKey = $this->valueGroupKey($raw);
                    if ($groupKey !== $raw) {
                        $map[$field][$groupKey] = $target;
                    }
                }
            }
        }
        $state['value_map'] = $map;
        $this->valueMapCache = $map;
        $this->saveState($state);

        return ['ok' => true];
    }

    /**
     * @return array{value:string,none:bool,new:bool}
     */
    private function mappedValue(string $field, string $raw): array
    {
        if ($raw === '') {
            return ['value' => '', 'none' => false, 'new' => false];
        }
        $map = $this->valueMap()[$field] ?? [];
        $target = $map[$raw] ?? $map[mb_strtolower($raw)] ?? $map[$this->valueGroupKey($raw)] ?? null;
        if ($target === null) {
            return ['value' => '', 'none' => false, 'new' => false];
        }
        if ($target === '__none__') {
            return ['value' => '', 'none' => true, 'new' => false];
        }
        if ($target === '__new__') {
            return ['value' => $raw, 'none' => false, 'new' => true];
        }

        return ['value' => $target, 'none' => false, 'new' => false];
    }

    /** Suggested program value for a raw CSV value. */
    private function valueSuggestion(string $field, string $raw): string
    {
        $raw = trim($raw);
        switch ($field) {
            case 'direction':
                $parsed = $this->parseDirection($raw);
                return $parsed !== '' ? $parsed : '__none__';
            case 'approved':
                $bool = ImportValueNormalizer::toBool($raw);
                if ($bool === null) {
                    return (string) $this->options()['approve'];
                }
                return $bool ? '1' : '0';
            case 'account':
            case 'transfer_account':
                $key = $this->matchAccount($raw, $this->treasury->accountOptions(false));
                if ($key === '') {
                    return '__none__';
                }
                foreach ($this->treasury->accountOptions(true) as $account) {
                    if ((string) $account['key'] === $key) {
                        return $key;
                    }
                }
                return '__none__';
            case 'payment_method':
                $method = $this->matchMethod($raw);
                if ($method === '') {
                    return '__none__';
                }
                $supported = array_map(
                    static fn (array $option): string => (string) $option['value'],
                    $this->valueOptions('payment_method')
                );
                return in_array($method, $supported, true) ? $method : '__none__';
            case 'category':
                $key = $this->resolveCategoryKey($raw);
                return $key ?? '__new__';
            case 'runts_line':
                $line = $this->matchRuntsLine($raw);
                if ($line === '') {
                    return '__none__';
                }
                $supported = array_map(
                    static fn (array $option): string => (string) $option['value'],
                    $this->valueOptions('runts_line')
                );
                return in_array($line, $supported, true) ? $line : '__none__';
            case 'budget_item':
                $id = $this->matchBudgetItem($raw, $this->treasury->budgetItemFilterOptions());
                return $id > 0 ? (string) $id : '__none__';
            case 'document_type':
                $type = $this->matchDocumentType($raw);
                return $type !== '' ? $type : '__none__';
            case 'member':
                $id = (int) ($this->memberLookup()['by_key'][mb_strtolower($raw)] ?? 0);
                return $id > 0 ? (string) $id : '__none__';
        }

        return '__none__';
    }

    /** @return list<array{value:string,label:string}> */
    public function memberChoices(): array
    {
        $choices = [];
        foreach ($this->memberLookup()['labels'] as $id => $label) {
            if ($label === '') {
                continue;
            }
            $choices[] = ['value' => (string) $id, 'label' => $label];
        }
        usort($choices, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $choices;
    }

    /**
     * Parse the file without writing anything: each line becomes “new”, “skip”, “ignore” or “error”.
     * Manual corrections, ignored rows, duplicates to include and the recurring choices saved by
     * saveRowChoices() are applied here, so the preview and the commit share the same evaluation.
     *
     * @return array{ok:bool,error?:string,stats:array{total:int,create:int,skip:int,error:int,ignore:int},rows:list<array<string,mixed>>,recurring:list<array<string,mixed>>,shown:int,total_rows:int}
     */
    public function analyze(int $limit = self::PREVIEW_ROWS): array
    {
        $result = [
            'ok' => false,
            'stats' => ['total' => 0, 'create' => 0, 'skip' => 0, 'error' => 0, 'ignore' => 0],
            'rows' => [],
            'recurring' => [],
            'shown' => 0,
            'total_rows' => 0,
        ];
        $state = $this->state();
        $table = $this->table();
        if ($state === null || empty($table['ok'])) {
            $result['error'] = (string) ($table['error'] ?? 'treasury.import.error_no_file');
            return $result;
        }
        $map = is_array($state['map'] ?? null) ? $state['map'] : [];
        if ($map === []) {
            $result['error'] = 'treasury.import.error_no_mapping';
            return $result;
        }

        $options = $this->options();
        $accounts = $this->treasury->accountOptions(false);
        $accountKeys = array_map(static fn (array $account): string => (string) $account['key'], $accounts);
        $activeKeys = [];
        $accountLabels = [];
        foreach ($accounts as $account) {
            $accountLabels[(string) $account['key']] = (string) $account['label'];
            if (!empty($account['active'])) {
                $activeKeys[] = (string) $account['key'];
            }
        }
        $categories = $this->treasury->categoryMap();
        $budgetOptions = $this->treasury->budgetItemFilterOptions();
        $members = $this->memberLookup();
        $existing = $options['skip_duplicates'] === '1' ? $this->existingFingerprints() : [];
        $choices = $this->rowChoices();
        $batchSeen = [];

        $result['ok'] = true;
        $rows = [];
        foreach ($table['rows'] as $raw) {
            $values = [];
            foreach ($map as $index => $target) {
                if ((string) $target === ImportValueNormalizer::TARGET_IGNORE) {
                    continue;
                }
                $values[(string) $target] = trim((string) ($raw['cells'][(int) $index] ?? ''));
            }
            $line = (int) ($raw['line'] ?? 0);
            // Manual corrections applied in the dry run replace the CSV cells before parsing.
            $fixes = $choices['fixes'][$line] ?? [];
            foreach ($fixes as $field => $fixedValue) {
                $values[$field] = $fixedValue;
            }
            $errors = [];
            $warnings = [];

            $date = ImportValueNormalizer::toDate((string) ($values['movement_date'] ?? '')) ?? '';
            if ($date === '') {
                $errors[] = (string) __('treasury.import.error_date', ['line' => (string) $line]);
            }

            $directionRaw = (string) ($values['direction'] ?? '');
            $mappedDirection = $this->mappedValue('direction', $directionRaw);
            if ($mappedDirection['value'] !== '') {
                $direction = $mappedDirection['value'];
            } elseif ($mappedDirection['none'] || $directionRaw === '') {
                $direction = (string) $options['default_direction'];
            } else {
                $direction = $this->parseDirection($directionRaw);
            }
            if (!in_array($direction, TreasuryService::DIRECTIONS, true)) {
                $direction = '';
                $errors[] = (string) __('treasury.import.error_direction', ['line' => (string) $line]);
            }

            $amountRaw = (string) ($values['amount'] ?? '');
            $amount = ImportValueNormalizer::toAmount($amountRaw);
            if ($amount === null || $amount <= 0) {
                $errors[] = (string) __('treasury.import.error_amount', [
                    'line' => (string) $line,
                    'value' => $amountRaw !== '' ? $amountRaw : '—',
                ]);
            }

            $accountRaw = (string) ($values['account'] ?? '');
            $mappedAccount = $this->mappedValue('account', $accountRaw);
            if ($mappedAccount['value'] !== '') {
                $account = $mappedAccount['value'];
            } elseif ($mappedAccount['none']) {
                $account = '';
            } else {
                $account = $accountRaw !== '' ? $this->matchAccount($accountRaw, $accounts) : '';
            }
            if ($account !== '' && (!in_array($account, $accountKeys, true) || !in_array($account, $activeKeys, true))) {
                $warnings[] = (string) __('treasury.import.warning_account_fallback', [
                    'label' => $accountRaw,
                    'account' => (string) ($accountLabels[(string) $options['default_account']] ?? $options['default_account']),
                ]);
                $account = '';
            }
            if ($account === '') {
                $account = (string) $options['default_account'];
            }
            if ($account === '') {
                $errors[] = (string) __('treasury.import.error_account', ['line' => (string) $line]);
            }

            $methodRaw = (string) ($values['payment_method'] ?? '');
            $mappedMethod = $this->mappedValue('payment_method', $methodRaw);
            if ($mappedMethod['value'] !== '') {
                $method = $mappedMethod['value'];
            } elseif ($mappedMethod['none']) {
                $method = '';
            } else {
                $method = $this->matchMethod($methodRaw);
                if ($method === '') {
                    $method = (string) $options['default_payment_method'];
                }
            }

            // Transfer account: the destination account (dedicated column) is needed, different from the
            // departure one.
            $transferAccount = '';
            if ($direction === 'transfer') {
                $transferRaw = (string) ($values['transfer_account'] ?? '');
                $mappedTransfer = $this->mappedValue('transfer_account', $transferRaw);
                if ($mappedTransfer['value'] !== '') {
                    $candidate = $mappedTransfer['value'];
                } elseif ($mappedTransfer['none']) {
                    $candidate = '';
                } else {
                    $candidate = $transferRaw !== '' ? $this->matchAccount($transferRaw, $accounts) : '';
                }
                if ($candidate === '' || !in_array($candidate, $accountKeys, true)
                    || !in_array($candidate, $activeKeys, true) || $candidate === $account) {
                    $errors[] = (string) __('treasury.import.error_transfer', ['line' => (string) $line]);
                } else {
                    $transferAccount = $candidate;
                }
                $method = '';
            }

            $categoryRaw = (string) ($values['category'] ?? '');
            $categoryKey = (string) $options['default_category'];
            $newCategory = '';
            $mappedCategory = $this->mappedValue('category', $categoryRaw);
            if ($direction === 'transfer') {
                // A transfer does not have its own economic category.
                $categoryKey = 'internal_transfer';
            } elseif ($mappedCategory['new']) {
                $categoryKey = '__new__';
                $newCategory = mb_substr($categoryRaw, 0, 80);
                $warnings[] = (string) __('treasury.import.warning_new_category', ['label' => $newCategory]);
            } elseif ($mappedCategory['none']) {
                $categoryKey = (string) $options['default_category'];
            } elseif ($mappedCategory['value'] !== '') {
                $categoryKey = $mappedCategory['value'];
            } elseif ($categoryRaw !== '') {
                $resolved = $this->resolveCategoryKey($categoryRaw);
                if ($resolved !== null) {
                    $categoryKey = $resolved;
                } else {
                    $categoryKey = '__new__';
                    $newCategory = mb_substr($categoryRaw, 0, 80);
                    $warnings[] = (string) __('treasury.import.warning_new_category', ['label' => $newCategory]);
                }
            }
            $categoryLabel = $categoryKey === '__new__'
                ? $newCategory
                : (string) ($categories[$categoryKey] ?? $this->treasury->categoryLabel($categoryKey));

            $runtsLine = '';
            if ($direction === 'income' || $direction === 'expense') {
                $runtsRaw = (string) ($values['runts_line'] ?? '');
                $mappedRunts = $this->mappedValue('runts_line', $runtsRaw);
                if ($mappedRunts['value'] !== '') {
                    $manualRunts = $mappedRunts['value'];
                } elseif ($mappedRunts['none']) {
                    $manualRunts = '';
                } else {
                    $manualRunts = $this->matchRuntsLine($runtsRaw);
                }
                $runtsLine = RuntsReportService::normalizeLine(
                    $manualRunts,
                    $categoryKey === '__new__' ? 'other' : $categoryKey,
                    $direction
                );
            }

            $budgetRaw = (string) ($values['budget_item'] ?? '');
            $mappedBudget = $this->mappedValue('budget_item', $budgetRaw);
            if ($mappedBudget['value'] !== '') {
                $budgetItemId = max(0, (int) $mappedBudget['value']);
            } elseif ($mappedBudget['none']) {
                $budgetItemId = 0;
            } else {
                $budgetItemId = $this->matchBudgetItem($budgetRaw, $budgetOptions);
            }

            $memberRaw = (string) ($values['member'] ?? '');
            $memberId = 0;
            $memberLabel = '';
            $mappedMember = $this->mappedValue('member', $memberRaw);
            if ($mappedMember['value'] !== '') {
                $memberId = max(0, (int) $mappedMember['value']);
            } elseif ($mappedMember['none']) {
                $memberId = 0;
            } elseif ($memberRaw !== '') {
                $memberId = (int) ($members['by_key'][mb_strtolower($memberRaw)] ?? 0);
            }
            if ($memberId > 0 && !isset($members['labels'][$memberId])) {
                $memberId = 0;
            }
            if ($memberId > 0) {
                $memberLabel = (string) ($members['labels'][$memberId] ?? $memberRaw);
            } elseif ($memberRaw !== '' && !$mappedMember['none']) {
                $warnings[] = (string) __('treasury.import.warning_member', ['name' => $memberRaw]);
            }

            $approved = (string) $options['approve'] === '1';
            $approvedRaw = (string) ($values['approved'] ?? '');
            $mappedApproved = $this->mappedValue('approved', $approvedRaw);
            if ($mappedApproved['value'] !== '') {
                $approved = $mappedApproved['value'] === '1';
            } elseif ($approvedRaw !== '') {
                $bool = ImportValueNormalizer::toBool($approvedRaw);
                if ($bool !== null) {
                    $approved = $bool;
                }
            }
            $description = ImportValueNormalizer::text((string) ($values['description'] ?? ''));
            $beneficiary = ImportValueNormalizer::text((string) ($values['beneficiary'] ?? ''));
            $invoiceNumber = mb_substr(trim((string) ($values['invoice_number'] ?? '')), 0, 120);
            $invoiceDate = '';
            $invoiceDateRaw = trim((string) ($values['invoice_date'] ?? ''));
            if ($invoiceDateRaw !== '') {
                $invoiceDate = ImportValueNormalizer::toDate($invoiceDateRaw) ?? '';
                if ($invoiceDate === '') {
                    $errors[] = (string) __('treasury.import.error_document_date', [
                        'line' => (string) $line,
                        'value' => $invoiceDateRaw,
                    ]);
                }
            }
            if ($direction === 'transfer') {
                $invoiceNumber = '';
                $invoiceDate = '';
            }
            $documentTypeRaw = (string) ($values['document_type'] ?? '');
            $documentType = '';
            $mappedDocumentType = $this->mappedValue('document_type', $documentTypeRaw);
            if ($direction !== 'transfer') {
                if ($mappedDocumentType['value'] !== '') {
                    $documentType = $mappedDocumentType['value'];
                } elseif (!$mappedDocumentType['none'] && $documentTypeRaw !== '') {
                    $documentType = $this->matchDocumentType($documentTypeRaw);
                }
            }
            $invoicePayment = $direction === 'expense' && ($invoiceNumber !== '' || $invoiceDate !== '') ? '1' : '';
            $action = 'create';
            $duplicate = null;
            if ($errors !== []) {
                $action = 'error';
            } else {
                // A movement is “already present” only with the same date, amount and description:
                // anything else is a different movement and must not be lost.
                $fingerprint = $this->fingerprint($date, (float) $amount, $description);
                $twin = $existing[$fingerprint] ?? null;
                $batchLine = (int) ($batchSeen[$fingerprint] ?? 0);
                if ($twin !== null || $batchLine > 0) {
                    $duplicate = [
                        'id' => (int) ($twin['id'] ?? 0),
                        'date' => (string) ($twin['date'] ?? ''),
                        'description' => mb_strimwidth((string) ($twin['description'] ?? ''), 0, 60, '…'),
                        'line' => $batchLine,
                        'included' => isset($choices['included'][$line]),
                    ];
                    if ($duplicate['included']) {
                        $action = 'create';
                        $warnings[] = (string) ($batchLine > 0
                            ? __('treasury.import.duplicate_batch_included', ['line' => (string) $batchLine])
                            : __('treasury.import.duplicate_existing_included', [
                                'id' => (string) $duplicate['id'],
                                'date' => format_date($duplicate['date']) ?: $duplicate['date'],
                                'description' => $duplicate['description'],
                            ]));
                    } else {
                        $action = 'skip';
                        $warnings[] = (string) ($batchLine > 0
                            ? __('treasury.import.duplicate_batch', ['line' => (string) $batchLine])
                            : __('treasury.import.duplicate_existing', [
                                'id' => (string) $duplicate['id'],
                                'date' => format_date($duplicate['date']) ?: $duplicate['date'],
                                'description' => $duplicate['description'],
                            ]));
                    }
                } else {
                    $batchSeen[$fingerprint] = $line;
                }
            }
            if (isset($choices['ignored'][$line])) {
                // Ignoring wins over everything: the row is neither imported nor reported as an error.
                $action = 'ignore';
            }

            $rows[] = [
                'line' => $line,
                'action' => $action,
                'ignored' => $action === 'ignore',
                'fixed' => $fixes !== [],
                'duplicate' => $duplicate,
                'recurring_key' => '',
                'date' => $date,
                'direction' => $direction,
                'amount' => (float) ($amount ?? 0),
                'category' => $categoryLabel,
                'runts_line' => $runtsLine !== '' ? RuntsReportService::lineLabelWithSection($runtsLine) : '',
                'account' => (string) ($accountLabels[$account] ?? $account),
                'transfer_account' => $transferAccount !== '' ? (string) ($accountLabels[$transferAccount] ?? $transferAccount) : '',
                'method' => $method !== '' ? (string) __('treasury.method_' . $method) : '',
                'description' => $description,
                'document_type' => $documentType !== '' ? (string) __('treasury.document_type_' . $documentType) : '',
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $invoiceDate,
                'beneficiary' => $beneficiary,
                'member' => $memberLabel,
                'approved' => $approved,
                'errors' => $errors,
                'warnings' => $warnings,
                'edit' => $errors !== [] || $fixes !== [] ? [
                    'movement_date' => $date !== '' ? $date : (string) ($values['movement_date'] ?? ''),
                    'direction' => $direction,
                    'amount' => $amount !== null ? number_format($amount, 2, '.', '') : $amountRaw,
                    'account' => $account,
                    'transfer_account' => $transferAccount,
                    'description' => (string) ($values['description'] ?? ''),
                    'document_type' => $documentType,
                    'invoice_number' => (string) ($values['invoice_number'] ?? ''),
                    'invoice_date' => $invoiceDate !== '' ? $invoiceDate : $invoiceDateRaw,
                ] : [],
                'payload' => [
                    'direction' => $direction,
                    'amount' => $amount !== null ? (string) $amount : '',
                    'movement_date' => $date,
                    'account' => $account,
                    'transfer_account' => $transferAccount,
                    'payment_method' => $method,
                    'category' => $categoryKey,
                    'new_category' => $newCategory,
                    'runts_line' => $runtsLine,
                    'budget_item_id' => $budgetItemId > 0 ? (string) $budgetItemId : '',
                    'description' => $description,
                    'document_type' => $documentType,
                    'invoice_number' => $invoiceNumber,
                    'invoice_date' => $invoiceDate,
                    'invoice_payment' => $invoicePayment,
                    'beneficiary' => $beneficiary,
                    'member_id' => $memberId > 0 ? (string) $memberId : '',
                    'member_involved' => $memberId > 0 ? '1' : '',
                ],
            ];
        }
        // Recurring series are recognised across the whole file, then every row is annotated with the
        // series it belongs to (the commit uses it to create the rule and link the movements).
        $detection = $this->detectRecurring($rows);
        $series = $detection['series'];
        $importable = [];
        foreach ($detection['rows'] as $index => $key) {
            if ((string) ($rows[$index]['action'] ?? '') === 'create') {
                $importable[$key] = true;
            }
        }
        // A rule is proposed only when at least one occurrence will really be imported.
        foreach (array_keys($series) as $key) {
            if (!isset($importable[$key])) {
                unset($series[$key]);
            }
        }
        foreach ($detection['rows'] as $index => $key) {
            if (isset($series[$key], $rows[$index])) {
                $rows[$index]['recurring_key'] = $key;
            }
        }
        foreach ($series as $key => $meta) {
            $series[$key]['enabled'] = ($choices['recurring'][(string) $key] ?? '1') === '1';
            $series[$key]['frequency_label'] = (string) __('treasury.recurring_freq_' . (string) $meta['frequency']);
        }
        foreach ($rows as $row) {
            $action = (string) ($row['action'] ?? 'error');
            $result['stats']['total']++;
            if (isset($result['stats'][$action])) {
                $result['stats'][$action]++;
            }
        }
        $result['total_rows'] = $result['stats']['total'];
        $result['recurring'] = array_values($series);
        // Problems first (errors, ignored, skipped): they stay visible even when only part of the file
        // is shown.
        usort($rows, static function (array $a, array $b): int {
            $order = ['error' => 0, 'ignore' => 1, 'skip' => 2, 'create' => 3];
            $left = $order[(string) ($a['action'] ?? 'error')] ?? 4;
            $right = $order[(string) ($b['action'] ?? 'error')] ?? 4;
            if ($left !== $right) {
                return $left <=> $right;
            }

            return (int) ($a['line'] ?? 0) <=> (int) ($b['line'] ?? 0);
        });
        $result['rows'] = $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
        $result['shown'] = count($result['rows']);

        return $result;
    }

    /**
     * Recognises the recurring series: same direction and category, description with the numbers
     * stripped (“Fattura 12/2024 ENEL” → “fatturaenel”), at least three occurrences and a constant
     * interval. Both expenses (rent, utilities, instalments) and income (fees, grants) are detected.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{series:array<string,array<string,mixed>>,rows:array<int,string>}
     */
    private function detectRecurring(array $rows): array
    {
        $today = date('Y-m-d');
        $groups = [];
        foreach ($rows as $index => $row) {
            $action = (string) ($row['action'] ?? 'error');
            if ($action !== 'create' && $action !== 'skip') {
                continue;
            }
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            $direction = (string) ($payload['direction'] ?? '');
            $date = (string) ($payload['movement_date'] ?? '');
            $amount = (float) ($payload['amount'] ?? 0);
            if (($direction !== 'income' && $direction !== 'expense') || $date === '' || $amount <= 0) {
                continue;
            }
            $needle = $this->recurringKey((string) ($payload['description'] ?? ''));
            if (mb_strlen($needle) < 4) {
                continue;
            }
            $key = $direction . '|' . (string) ($payload['category'] ?? '') . '|' . $needle;
            $groups[$key][] = ['index' => (int) $index, 'date' => $date, 'amount' => $amount];
        }

        $series = [];
        $members = [];
        foreach ($groups as $key => $entries) {
            if (count($entries) < 3) {
                continue;
            }
            usort($entries, static fn (array $a, array $b): int => (string) $a['date'] <=> (string) $b['date']);
            $frequency = $this->recurringFrequency($entries);
            if ($frequency === '') {
                continue;
            }
            $last = $entries[count($entries) - 1];
            $lastRow = $rows[(int) $last['index']] ?? [];
            $payload = is_array($lastRow['payload'] ?? null) ? $lastRow['payload'] : [];
            $lastDate = (string) $last['date'];
            $amounts = array_map(
                static fn (array $entry): string => number_format((float) $entry['amount'], 2, '.', ''),
                $entries
            );
            $description = (string) ($payload['description'] ?? '');
            $series[$key] = [
                'key' => (string) $key,
                'frequency' => $frequency,
                'day' => (int) date('j', strtotime($lastDate) ?: time()),
                'count' => count($entries),
                'from' => (string) $entries[0]['date'],
                'to' => $lastDate,
                // The rule starts from the last occurrence (never in the past): no catch-up entries.
                'start' => $lastDate < $today ? $today : $lastDate,
                'variable' => count(array_unique($amounts)) > 1,
                'amount' => round((float) $last['amount'], 2),
                'label' => mb_strimwidth($description, 0, 70, '…'),
                'direction' => (string) ($payload['direction'] ?? ''),
                'category' => (string) ($payload['category'] ?? ''),
                'account' => (string) ($payload['account'] ?? ''),
                'payment_method' => (string) ($payload['payment_method'] ?? ''),
                'description' => $description,
                'beneficiary' => (string) ($payload['beneficiary'] ?? ''),
                'member_id' => (string) ($payload['member_id'] ?? ''),
                'budget_item_id' => (string) ($payload['budget_item_id'] ?? ''),
                'runts_line' => (string) ($payload['runts_line'] ?? ''),
            ];
            foreach ($entries as $entry) {
                $members[(int) $entry['index']] = (string) $key;
            }
        }

        return ['series' => $series, 'rows' => $members];
    }

    /**
     * Grouping key of a recurring series: the description without numbers and month names, so
     * “Affitto gennaio 2025” and “Affitto febbraio 2025” end up in the same series.
     */
    private function recurringKey(string $description): string
    {
        $months = 'gen|genn|gennaio|feb|febbr|febbraio|marzo|apr|aprile|mag|maggio|mai|giu|giugno|lug|luglio'
            . '|ago|agosto|set|settembre|ott|ottobre|nov|novembre|dic|dicembre'
            . '|januar|jänner|februar|märz|maerz|juni|juli|august|oktober|dezember'
            . '|january|february|march|may|june|july|october|december';
        $text = (string) preg_replace('/\b(' . $months . ')\b/u', ' ', mb_strtolower($description, 'UTF-8'));

        return (string) preg_replace('/[^a-z]+/', '', ImportValueNormalizer::matchKey($text));
    }

    /**
     * Constant interval of the occurrences → frequency (empty when the series is not regular).
     *
     * @param list<array{index:int,date:string,amount:float}> $entries sorted by date
     */
    private function recurringFrequency(array $entries): string
    {
        $days = [];
        $months = [];
        foreach ($entries as $entry) {
            $timestamp = strtotime((string) $entry['date']);
            if ($timestamp === false) {
                return '';
            }
            $days[] = (int) date('Y', $timestamp) * 366 + (int) date('z', $timestamp);
            $months[] = (int) date('Y', $timestamp) * 12 + (int) date('n', $timestamp);
        }
        $weekly = true;
        for ($i = 1; $i < count($days); $i++) {
            if ($days[$i] - $days[$i - 1] !== 7) {
                $weekly = false;
                break;
            }
        }
        if ($weekly) {
            return 'weekly';
        }
        $step = $months[1] - $months[0];
        if ($step < 1) {
            return '';
        }
        for ($i = 1; $i < count($months); $i++) {
            if ($months[$i] - $months[$i - 1] !== $step) {
                return '';
            }
        }

        return self::RECURRING_MONTHS[$step] ?? '';
    }

    /**
     * Writes only the valid rows to the first note, then prepares the run report.
     *
     * @return array{ok:bool,error?:string,report:array<string,mixed>}
     */
    public function commit(string $ip, ?int $userId = null): array
    {
        $analysis = $this->analyze(0);
        if (empty($analysis['ok'])) {
            return [
                'ok' => false,
                'error' => (string) ($analysis['error'] ?? 'treasury.import.error_no_mapping'),
                'report' => [],
            ];
        }
        $state = $this->state();
        $created = 0;
        $skipped = 0;
        $ignored = 0;
        $failed = 0;
        $errors = [];
        $newCategories = [];
        // Only the recurring series kept in the dry run get a rule; the imported movements of the
        // series are linked to it.
        $series = [];
        foreach ((array) ($analysis['recurring'] ?? []) as $meta) {
            if (is_array($meta) && !empty($meta['enabled'])) {
                $series[(string) ($meta['key'] ?? '')] = $meta;
            }
        }
        $rules = [];
        foreach ($analysis['rows'] as $row) {
            $action = (string) ($row['action'] ?? 'error');
            if ($action === 'skip') {
                $skipped++;
                continue;
            }
            if ($action === 'ignore') {
                $ignored++;
                continue;
            }
            if ($action !== 'create') {
                $failed++;
                $errors[] = [
                    'line' => (int) ($row['line'] ?? 0),
                    'description' => (string) ($row['description'] ?? ''),
                    'message' => implode(' ', array_map('strval', (array) ($row['errors'] ?? []))),
                ];
                continue;
            }
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            $result = $this->treasury->create($payload, null, $ip, $userId);
            if (empty($result['ok'])) {
                $failed++;
                $errors[] = [
                    'line' => (int) ($row['line'] ?? 0),
                    'description' => (string) ($row['description'] ?? ''),
                    'message' => implode(' ', array_map('strval', (array) ($result['errors'] ?? []))),
                ];
                continue;
            }
            $movementId = (int) ($result['id'] ?? 0);
            if (empty($row['approved'])) {
                $this->db->update(
                    'treasury_movements',
                    ['approved_at' => null, 'approved_by' => null],
                    'id = :id',
                    ['id' => $movementId]
                );
            }
            $seriesKey = (string) ($row['recurring_key'] ?? '');
            if ($seriesKey !== '' && isset($series[$seriesKey])) {
                if (!isset($rules[$seriesKey])) {
                    $rules[$seriesKey] = $this->createRecurringRule($series[$seriesKey], $userId, $ip);
                }
                if ($rules[$seriesKey] > 0 && $movementId > 0) {
                    $this->db->update(
                        'treasury_movements',
                        ['recurring_id' => $rules[$seriesKey]],
                        'id = :id',
                        ['id' => $movementId]
                    );
                }
            }
            if ((string) ($payload['category'] ?? '') === '__new__') {
                $label = trim((string) ($payload['new_category'] ?? ''));
                if ($label !== '') {
                    $newCategories[$label] = true;
                }
            }
            $created++;
        }
        $recurringRules = count(array_filter($rules, static fn (int $id): bool => $id > 0));
        $report = [
            'run_id' => time(),
            'at' => date('Y-m-d H:i:s'),
            'source_name' => (string) ($state['source_name'] ?? ''),
            'created' => $created,
            'skipped' => $skipped,
            'ignored' => $ignored,
            'failed' => $failed,
            'recurring_rules' => $recurringRules,
            'errors' => $errors,
            'new_categories' => array_keys($newCategories),
        ];
        $_SESSION[self::REPORT_KEY] = $report;
        $this->reset(false);
        app('logger')->info('treasury.csv_imported', [
            'created' => $created,
            'skipped' => $skipped,
            'ignored' => $ignored,
            'failed' => $failed,
            'recurring_rules' => $recurringRules,
        ]);

        return ['ok' => true, 'report' => $report];
    }

    /**
     * Creates the recurring rule detected in the dry run; 0 when it cannot be created.
     *
     * @param array<string,mixed> $series
     */
    private function createRecurringRule(array $series, ?int $userId, string $ip): int
    {
        $result = $this->recurring->createFromInput([
            'direction' => (string) ($series['direction'] ?? 'expense'),
            'amount' => (float) ($series['amount'] ?? 0),
            'category' => (string) ($series['category'] ?? ''),
            'description' => (string) ($series['description'] ?? ''),
            'account' => (string) ($series['account'] ?? ''),
            'payment_method' => (string) ($series['payment_method'] ?? ''),
            'beneficiary' => (string) ($series['beneficiary'] ?? ''),
            'member_id' => (int) ($series['member_id'] ?? 0),
            'budget_item_id' => (int) ($series['budget_item_id'] ?? 0),
            'runts_line' => (string) ($series['runts_line'] ?? ''),
            'recurring_frequency' => (string) ($series['frequency'] ?? 'monthly'),
            'recurring_day' => (int) ($series['day'] ?? 1),
            'recurring_start' => (string) ($series['start'] ?? ''),
            'recurring_variable' => !empty($series['variable']) ? '1' : '',
        ], (string) ($series['start'] ?? ''), $userId, $ip);

        return empty($result['ok']) ? 0 : (int) ($result['id'] ?? 0);
    }

    /** @return array<string,mixed> */
    public function lastReport(): array
    {
        $report = $_SESSION[self::REPORT_KEY] ?? [];

        return is_array($report) ? $report : [];
    }

    /** @return list<array{line:int,description:string,message:string}> */
    public function errorRows(): array
    {
        $report = $this->lastReport();
        $errors = $report['errors'] ?? [];

        return is_array($errors) ? array_values($errors) : [];
    }

    // ── Recognition of values ────────────────────── ───────────────────────

    /**
     * Type from a free label: first the exact comparison (slug, IT/EN/DE, customized categories),
     * then a unique prefix (“Utilities” → “Utilities (electricity, gas…)”).
     */
    private function resolveCategoryKey(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $resolved = $this->treasury->resolveCategoryFromLabel($raw);
        if ($resolved !== null) {
            return $resolved;
        }
        $needle = ImportValueNormalizer::matchKey($raw);
        if (mb_strlen($needle) < 3) {
            return null;
        }
        $matches = [];
        foreach ($this->treasury->categoryOptions() as $option) {
            $key = (string) ($option['key'] ?? '');
            $label = ImportValueNormalizer::matchKey((string) ($option['label'] ?? ''));
            if ($key === '' || $label === '') {
                continue;
            }
            if (str_starts_with($label, $needle) || str_starts_with($needle, $label)) {
                $matches[$key] = true;
            }
        }

        return count($matches) === 1 ? (string) array_key_first($matches) : null;
    }

    /**
     * Recognizes the field from the column name (IT/EN/DE).
     */
    private function guessField(string $header): string
    {
        $key = ImportValueNormalizer::matchKey($header);
        if ($key === '') {
            return '';
        }
        $aliases = [
            'movement_date' => ['data', 'dataoperazione', 'datamovimento', 'date', 'datum', 'buchungsdatum', 'valuta', 'movementdate', 'operationdate'],
            'direction' => ['tipo', 'tipomovimento', 'segno', 'direction', 'typ', 'entrata', 'uscita', 'einnahme', 'ausgabe', 'inout'],
            'amount' => ['importo', 'importoeuro', 'amount', 'betrag', 'somma', 'value', 'summe'],
            'transfer_account' => ['transferaccount', 'contodestinazione', 'contodidestinazione', 'acconto', 'aconto', 'targetaccount', 'zielkonto', 'destinationaccount'],
            'account' => ['conto', 'contofinanziario', 'account', 'konto', 'cassa', 'banca', 'wallet'],
            'payment_method' => ['metodo', 'metodopagamento', 'modalitapagamento', 'paymentmethod', 'zahlungsart', 'zahlungsmethode'],
            'category' => ['tipologia', 'categoria', 'category', 'kategorie', 'rubrica'],
            'runts_line' => ['vocemodellod', 'voce', 'modello', 'runts', 'position', 'vocerunts'],
            'budget_item' => ['centro', 'centrodicosto', 'costo', 'progetto', 'budget', 'kostenstelle', 'vocepreventivo'],
            'document_type' => ['tipodocumento', 'tipodoc', 'documenttype', 'doctype', 'belegart', 'belegtyp', 'documentkind', 'tipologiadocumento'],
            'invoice_number' => ['numerodocumento', 'numerofattura', 'numerodoc', 'documentnumber', 'invoicenumber', 'rechnungsnummer', 'docnumber', 'beleg', 'belegnummer'],
            'invoice_date' => ['datadocumento', 'datafattura', 'datadoc', 'documentdate', 'invoicedate', 'rechnungsdatum', 'docdate', 'datariferimento', 'belegdatum'],
            'description' => ['descrizione', 'causale', 'description', 'beschreibung', 'note', 'memo'],
            'beneficiary' => ['beneficiario', 'fornitore', 'beneficiary', 'empfanger', 'payee'],
            'member' => ['socio', 'numerosocio', 'member', 'mitglied', 'socionumero'],
            'approved' => ['approvato', 'approvazione', 'approval', 'freigegeben', 'stato', 'status'],
        ];
        foreach ($aliases as $field => $needles) {
            if (in_array($key, $needles, true)) {
                return $field;
            }
        }
        foreach ($aliases as $field => $needles) {
            foreach ($needles as $needle) {
                if ($needle === '' || !str_contains($key, $needle)) {
                    continue;
                }
                // “Typology” should not be mistaken for “Type”.
                if ($field === 'direction' && str_contains($key, 'tipologia')) {
                    continue;
                }
                return $field;
            }
        }

        return '';
    }

    private function parseDirection(string $raw): string
    {
        $key = mb_strtolower(trim($raw));
        if ($key === '') {
            return '';
        }
        $income = ['entrata', 'entrate', 'income', 'einnahme', 'einnahmen', 'eingang', 'in', '+', '1', 'avere', 'credit', 'haben'];
        $expense = ['uscita', 'uscite', 'expense', 'ausgabe', 'ausgaben', 'ausgang', 'out', '-', '0', 'dare', 'debit', 'soll'];
        $transfer = ['giroconto', 'giroconti', 'transfer', 'umbuchung', 'interner transfer'];
        if (in_array($key, $transfer, true)) {
            return 'transfer';
        }
        if (in_array($key, $income, true)) {
            return 'income';
        }
        if (in_array($key, $expense, true)) {
            return 'expense';
        }
        // Current localized labels (export of the same installation).
        if ($key === mb_strtolower((string) __('treasury.direction_sign_income'))) {
            return 'income';
        }
        if ($key === mb_strtolower((string) __('treasury.direction_sign_expense'))) {
            return 'expense';
        }
        if ($key === mb_strtolower((string) __('treasury.direction_sign_transfer'))) {
            return 'transfer';
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
            if ($key !== '' && ($needle === $key || $needle === $label || $slug === $key)) {
                return $key;
            }
        }
        // “Bank”, “Cash”, “Cash”…: partial match on the label.
        foreach ($accounts as $account) {
            $key = TreasuryService::normalizeAccount((string) $account['key']);
            $label = mb_strtolower(trim((string) $account['label']));
            if ($key !== '' && $label !== '' && (str_contains($label, $needle) || str_contains($needle, $label))) {
                return $key;
            }
        }

        return $slug;
    }

    private function matchMethod(string $raw): string
    {
        $needle = ImportValueNormalizer::text($raw);
        if ($needle === '') {
            return '';
        }
        $compacted = ImportValueNormalizer::matchKey($needle);
        foreach (TreasuryService::METHODS as $method) {
            if ($compacted === ImportValueNormalizer::matchKey($method)) {
                return TreasuryService::normalizePaymentMethod($method);
            }
            foreach (['it', 'en', 'de'] as $locale) {
                $label = $this->translate('treasury.method_' . $method, $locale);
                if ($label !== '' && ImportValueNormalizer::matchKey($label) === $compacted) {
                    return TreasuryService::normalizePaymentMethod($method);
                }
            }
        }
        $candidates = [];
        foreach (TreasuryService::METHODS as $method) {
            foreach (['it', 'en', 'de'] as $locale) {
                $labelKey = ImportValueNormalizer::matchKey($this->translate('treasury.method_' . $method, $locale));
                if ($labelKey !== '' && mb_strlen($compacted) >= 4
                    && (str_contains($labelKey, $compacted) || str_contains($compacted, $labelKey))) {
                    $candidates[$method] = true;
                }
            }
        }
        if (count($candidates) === 1) {
            return (string) array_key_first($candidates);
        }
        if ($candidates !== []) {
            $normalized = array_unique(array_map(
                static fn (string $method): string => TreasuryService::normalizePaymentMethod($method),
                array_keys($candidates)
            ));
            if (count($normalized) === 1) {
                $normalizedTarget = (string) reset($normalized);
                foreach ($this->treasury->paymentMethodGroups() as $group) {
                    if (in_array($normalizedTarget, (array) ($group['keys'] ?? []), true)) {
                        return $normalizedTarget;
                    }
                }
                $methods = array_keys($candidates);
                usort($methods, static function (string $a, string $b): int {
                    $labelA = ImportValueNormalizer::matchKey((string) __('treasury.method_' . $a));
                    $labelB = ImportValueNormalizer::matchKey((string) __('treasury.method_' . $b));
                    return strlen($labelA) <=> strlen($labelB);
                });
                return (string) $methods[0];
            }
        }
        $aliases = [
            'bank' => 'bank_transfer',
            'bonifico' => 'bank_transfer',
            'bonificobancario' => 'bank_transfer',
            'pos' => 'pos_card',
            'creditcard' => 'pos_card',
        ];
        if (isset($aliases[$compacted])) {
            return $aliases[$compacted];
        }
        $fallback = ImportValueNormalizer::toPaymentMethod($needle);

        return match ($fallback) {
            'cash' => 'cash',
            'bank' => 'bank_transfer',
            'other' => 'other',
            default => '',
        };
    }

    /**
     * Document types offered by the import (key + translated label).
     *
     * @return list<array{value:string,label:string}>
     */
    private function documentTypeOptions(): array
    {
        $out = [];
        foreach (TreasuryService::DOCUMENT_TYPES as $key) {
            $out[] = ['value' => (string) $key, 'label' => (string) __('treasury.document_type_' . $key)];
        }

        return $out;
    }

    /**
     * Document type from a free label (IT/EN/DE, case and symbols ignored): “Fattura”, “invoice”,
     * “Rechnung” → invoice; “nota di credito” and “nota di debito” stay distinct.
     */
    private function matchDocumentType(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $direct = TreasuryService::normalizeDocumentType($raw);
        if ($direct !== '') {
            return $direct;
        }
        $needle = ImportValueNormalizer::matchKey($raw);
        if (mb_strlen($needle) < 3) {
            return '';
        }
        $matches = [];
        foreach (TreasuryService::DOCUMENT_TYPES as $key) {
            $label = ImportValueNormalizer::matchKey((string) __('treasury.document_type_' . $key));
            if ($label === '') {
                continue;
            }
            if ($label === $needle || str_starts_with($label, $needle) || str_starts_with($needle, $label)) {
                $matches[$key] = true;
            }
        }

        return count($matches) === 1 ? (string) array_key_first($matches) : '';
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
            if ($label === $needle || ($label !== '' && str_contains($label, $needle))) {
                return (int) $option['value'];
            }
        }

        return 0;
    }

    /**
     * Translation into a specific language, without changing the language of the request.
     */
    private function translate(string $key, string $locale): string
    {
        static $cache = [];
        if (!isset($cache[$locale])) {
            $file = dirname(__DIR__, 2) . '/lang/' . $locale . '/messages.php';
            $cache[$locale] = is_file($file) ? (array) require $file : [];
        }
        $messages = $cache[$locale];
        foreach (explode('.', $key) as $segment) {
            if (!is_array($messages) || !array_key_exists($segment, $messages)) {
                return '';
            }
            $messages = $messages[$segment];
        }

        return is_string($messages) ? $messages : '';
    }

    /**
     * Member index: membership number and "Surname Name" (as in export).
     *
     * @return array{by_key:array<string,int>,labels:array<int,string>}
     */
    private function memberLookup(): array
    {
        if ($this->memberLookupCache !== null) {
            return $this->memberLookupCache;
        }
        $byKey = [];
        $labels = [];
        $rows = $this->db->fetchAll(
            "SELECT m.id, m.member_number,
                    (SELECT v.value FROM member_field_values v
                     INNER JOIN member_field_definitions d ON d.id = v.field_definition_id AND d.`key` = 'first_name'
                     WHERE v.member_id = m.id LIMIT 1) AS first_name,
                    (SELECT v.value FROM member_field_values v
                     INNER JOIN member_field_definitions d ON d.id = v.field_definition_id AND d.`key` = 'last_name'
                     WHERE v.member_id = m.id LIMIT 1) AS last_name
             FROM members m"
        );
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $first = trim((string) ($row['first_name'] ?? ''));
            $last = trim((string) ($row['last_name'] ?? ''));
            $label = trim($last . ' ' . $first);
            $labels[$id] = $label;
            $candidates = [
                trim((string) ($row['member_number'] ?? '')),
                $label,
                trim($first . ' ' . $last),
            ];
            foreach ($candidates as $candidate) {
                $candidate = mb_strtolower(trim($candidate));
                if ($candidate !== '' && !isset($byKey[$candidate])) {
                    $byKey[$candidate] = $id;
                }
            }
        }

        return $this->memberLookupCache = ['by_key' => $byKey, 'labels' => $labels];
    }

    /**
     * Fingerprints of the movements already in the first note (only those not superseded): they are
     * used to spot duplicates during the preview, together with the row they match so the dry run can
     * name it.
     *
     * @return array<string,array{id:int,date:string,description:string}>
     */
    private function existingFingerprints(): array
    {
        $out = [];
        foreach ($this->db->fetchAll(
            'SELECT id, movement_date, amount, description
             FROM treasury_movements WHERE superseded_at IS NULL'
        ) as $row) {
            $date = substr(trim((string) ($row['movement_date'] ?? '')), 0, 10);
            $description = (string) ($row['description'] ?? '');
            $fingerprint = $this->fingerprint($date, (float) ($row['amount'] ?? 0), $description);
            if (isset($out[$fingerprint])) {
                continue;
            }
            $out[$fingerprint] = [
                'id' => (int) ($row['id'] ?? 0),
                'date' => $date,
                'description' => trim($description),
            ];
        }

        return $out;
    }

    /**
     * Same date, same amount and same description: anything else is a different movement.
     */
    private function fingerprint(string $date, float $amount, string $description): string
    {
        return $date . '|' . number_format($amount, 2, '.', '') . '|'
            . mb_strtolower(ImportValueNormalizer::text($description));
    }
}
