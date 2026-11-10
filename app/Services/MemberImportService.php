<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;
use Socly\Core\Plugin\PluginManager;
use Socly\Support\CsvReader;
use Socly\Support\ImportValueNormalizer as N;
use Socly\Support\MemberFieldTypes;

/**
 * Import soci da CSV: analisi, mappatura colonne e scrittura transazionale.
 *
 * Il servizio non riusa MemberService::create() perché l'import deve poter
 * scrivere saldi e pagamenti storici, accettare consensi già raccolti e
 * aggiornare soci esistenti campo per campo. Riusa invece le stesse tabelle,
 * le stesse definizioni di campo e la stessa logica di deduplica.
 */
final class MemberImportService
{
    private const SESSION_KEY = '_member_import';
    private const REPORT_KEY = '_member_import_report';
    private const PROFILE_SETTING = 'members.import.profiles';
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const MAX_ROWS = 20000;
    /** Righe mostrate nell'anteprima quando c'è una ricerca attiva. */
    private const PREVIEW_ROWS = 200;
    /** Righe mostrate nell'anteprima senza ricerca: solo un saggio. */
    private const PREVIEW_DEFAULT_ROWS = 5;
    private const SAMPLE_ROWS = 40;
    private const BATCH_SIZE = 100;

    /** Mapping targets that are not member profile fields. */
    private const SYSTEM_TARGETS = [
        'sys:member_number' => 'members.import.target_member_number',
        'sys:member_type_id' => 'members.import.target_member_type',
        'sys:status' => 'members.import.target_status',
        'sys:admitted_at' => 'members.import.target_admitted_at',
        'sys:notes' => 'members.import.target_notes',
        'sys:balance_due' => 'members.import.target_balance_due',
        'sys:card_qr' => 'members.import.target_card_qr',
        'sys:card_issued_on' => 'members.import.target_card_issued_on',
        'sys:card_active' => 'members.import.target_card_active',
        'sys:paid_total' => 'members.import.target_paid_total',
        'sys:paid_at' => 'members.import.target_paid_at',
        'sys:payment_method' => 'members.import.target_payment_method',
    ];

    /** Ordered alias dictionary: first match wins (most specific targets first). */
    private const HEADER_ALIASES = [
        'sys:paid_at' => ['ultimopagamentodata', 'dataultimopagamento', 'ultimopagamento', 'lastpaymentdate', 'lastpayment', 'letztezahlung', 'datapagamento'],
        'sys:paid_total' => ['totalepagato', 'totaleversato', 'totaleincassato', 'importopagato', 'pagato', 'paidtotal', 'paid', 'bezahlt', 'summebezahlt'],
        'sys:payment_method' => ['ultimopagamentometodo', 'metodopagamento', 'modalitapagamento', 'paymentmethod', 'zahlungsart', 'zahlungsmethode'],
        'sys:balance_due' => ['debito', 'saldodovuto', 'balancedue', 'balance', 'saldo', 'dovuto', 'insoluto', 'residuo', 'outstanding', 'offenerbetrag'],
        'sys:card_qr' => ['qrtessera', 'codiceqr', 'tesseraqr', 'qrcode', 'qrkarte'],
        'sys:card_issued_on' => ['dataemissionetessera', 'emissionetessera', 'dataemissione', 'issuedon', 'ausstellungsdatum'],
        'sys:card_active' => ['tesseraattiva', 'attivatessera', 'cardactive', 'karteaktiv'],
        'sys:member_type_id' => ['tipotesseraattuale', 'tiposocioattuale', 'tiposocio', 'tipotessera', 'tesseramento', 'membershiptype', 'mitgliedstyp', 'tipologia', 'kategorie', 'type', 'tipo'],
        'sys:admitted_at' => ['dataregistrazione', 'dataiscrizione', 'datatesseramento', 'registrationdate', 'eintrittsdatum', 'registrazione', 'dateinscription'],
        'sys:member_number' => ['numerotessera', 'idtessera', 'cardnumber', 'cardid', 'numerosocio', 'nrsocio', 'nrtessera', 'matricola', 'ausweisnummer', 'mitgliedsnummer', 'membernumber', 'idsocio'],
        'sys:status' => ['statosocio', 'mitgliedsstatus', 'stato', 'status'],
        'sys:notes' => ['note', 'notes', 'notizen', 'annotazioni', 'osservazioni', 'bemerkungen', 'remarks'],
        'f:fiscal_code' => ['codicefiscale', 'codfisc', 'fiscalcode', 'steuernummer', 'codicefiscalesocio'],
        'f:first_name' => ['firstname', 'vorname', 'givenname', 'nomedisocio', 'nome', 'name'],
        'f:last_name' => ['cognome', 'lastname', 'nachname', 'surname', 'familyname', 'cognomedisocio'],
        'f:birth_date' => ['datadinascita', 'datanascita', 'geburtsdatum', 'birthdate', 'dateofbirth', 'nascita', 'dob'],
        'f:birth_place' => ['luogodinascita', 'luogonascita', 'comunedinascita', 'geburtsort', 'birthplace', 'placeofbirth', 'natoa'],
        'f:gender' => ['sesso', 'genere', 'geschlecht', 'gender', 'sex'],
        'f:email' => ['indirizzoemail', 'emailaddress', 'postaelettronica', 'email', 'mail'],
        'f:phone' => ['numerotelefono', 'telefonnummer', 'phonenumber', 'cellulare', 'telefono', 'mobile', 'telefon', 'tel'],
        'f:city' => ['cittadiresidenza', 'città', 'comune', 'residenza', 'stadt', 'wohnort', 'city', 'citta'],
        'f:address' => ['indirizzodiresidenza', 'indirizzo', 'strasse', 'adresse', 'street', 'via'],
        'f:house_number' => ['numerocivico', 'nrcivico', 'hausnummer', 'housenumber', 'civico'],
        'f:postal_code' => ['codicepostale', 'postalcode', 'postcode', 'plz', 'zip', 'cap'],
        'f:newsletter_consent' => ['notificheemail', 'consensonotificheemail', 'consensonewsletter', 'comunicazioniemail', 'newsletter', 'emailnotifications'],
        'f:image_consent' => ['liberatoriafoto', 'consensofoto', 'imageconsent'],
        'new:notifiche_telefono' => ['notifichetelefono', 'consensonotifichetelefono', 'notifichesms'],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly MemberService $members,
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
        private readonly PluginManager $plugins,
        private readonly TreasuryService $treasury,
        private readonly \Socly\Core\Validator $validator
    ) {
    }

    /** @var array<int,array<string,string>> member id => field values (diff cache) */
    private array $fieldValueCache = [];

    /** @var array<int,float> member type id => price */
    private array $typePriceCache = [];

    /** @var array<string,true>|null existing profile field keys */
    private ?array $existingFieldKeyCache = null;

    /** @var array<string,true> numeri tessera già presenti in archivio */
    private array $existingNumbers = [];

    /** @var array<string,true> numeri tessera prenotati dalle righe del file */
    private array $reservedNumbers = [];

    /** @var array<string,true> numeri tessera già assegnati in questa importazione */
    private array $usedNumbers = [];

    /** Contatore per i numeri tessera generati. */
    private int $numberCursor = 0;

    /** True when the current mapping comes from a saved profile. */
    private ?bool $profileApplied = null;

    /** @return bool Whether a remembered mapping profile was applied automatically. */
    public function profileApplied(): bool
    {
        return $this->profileApplied === true;
    }

    /** @return array<string,mixed>|null */
    public function state(): ?array
    {
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        return is_array($state) ? $state : null;
    }

    /** @param array<string,mixed> $state */
    private function saveState(array $state): void
    {
        $_SESSION[self::SESSION_KEY] = $state;
    }

    public function reset(): void
    {
        $state = $this->state();
        if ($state !== null) {
            $path = $this->absolutePath((string) ($state['file'] ?? ''));
            if ($path !== null && is_file($path)) {
                unlink($path);
            }
        }
        unset($_SESSION[self::SESSION_KEY], $_SESSION[self::REPORT_KEY]);
    }

    /** Toggle the "first row is a header" flag for ambiguous files. */
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

    /**
     * Store the uploaded CSV in storage and keep only its coordinates in session.
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int}|null $file
     * @return array{ok:bool,error?:string}
     */
    public function startFromUpload(?array $file): array
    {
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'members.import.error_missing'];
        }
        if ((int) ($file['size'] ?? 0) <= 0) {
            return ['ok' => false, 'error' => 'members.import.error_empty'];
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'members.import.error_too_large'];
        }
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'txt', 'tsv', 'dat'], true)) {
            return ['ok' => false, 'error' => 'members.import.error_extension'];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            return ['ok' => false, 'error' => 'members.import.error_missing'];
        }

        $paths = user_upload_paths('members-import');
        if (!is_dir($paths['absolute']) || !is_writable($paths['absolute'])) {
            $fallback = 'members-import';
            $fallbackDir = storage_path('uploads/' . $fallback);
            ensure_directory($fallbackDir);
            if (!is_writable($fallbackDir)) {
                return ['ok' => false, 'error' => 'members.import.error_store'];
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
                // CLI (test e import batch): il file non arriva da un upload HTTP.
                $stored = copy($tmp, $target);
            }
        } catch (\Throwable $e) {
            try {
                app('logger')->error('members.import.upload_failed', ['error' => $e->getMessage()]);
            } catch (\Throwable) {
            }
            $stored = false;
        }
        if (!$stored) {
            return ['ok' => false, 'error' => 'members.import.error_store'];
        }

        $reader = new CsvReader($target);
        $rows = $reader->rows(self::MAX_ROWS + 1);
        if ($rows === []) {
            unlink($target);
            return ['ok' => false, 'error' => 'members.import.error_empty'];
        }
        if (count($rows) > self::MAX_ROWS) {
            unlink($target);
            return ['ok' => false, 'error' => 'members.import.error_too_many_rows'];
        }

        $this->reset();
        $this->saveState([
            'token' => bin2hex(random_bytes(8)),
            'file' => $paths['relative'] . '/' . $filename,
            'source_name' => mb_substr((string) ($file['name'] ?? 'import.csv'), 0, 200),
            'source_hash' => hash_file('sha256', $target) ?: null,
            'delimiter' => $reader->delimiter(),
            'encoding' => $reader->encoding(),
            'has_header' => $reader->hasHeader($rows),
            'map' => [],
            'options' => $this->defaultOptions(),
            'created_at' => date('c'),
        ]);

        return ['ok' => true];
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
     * Parsed table (headers + data rows).
     *
     * @return array{ok:bool,error?:string,headers:list<string>,rows:list<array{line:int,cells:list<string>}>,has_header:bool,delimiter:string,encoding:string}
     */
    public function table(): array
    {
        $state = $this->state();
        $reader = $this->reader();
        if ($state === null || $reader === null) {
            return [
                'ok' => false,
                'error' => 'members.import.error_no_file',
                'headers' => [],
                'rows' => [],
                'has_header' => false,
                'delimiter' => '',
                'encoding' => '',
            ];
        }
        $raw = $reader->rows(self::MAX_ROWS + 1);
        $hasHeader = !empty($state['has_header']);
        $headers = [];
        $rows = $raw;
        if ($hasHeader && $raw !== []) {
            $headers = $raw[0]['cells'];
            $rows = array_slice($raw, 1);
        }
        if ($headers === []) {
            $width = 0;
            foreach (array_slice($raw, 0, self::SAMPLE_ROWS) as $row) {
                $width = max($width, count($row['cells']));
            }
            for ($i = 0; $i < $width; $i++) {
                $headers[] = (string) __('members.import.column_n', ['n' => (string) ($i + 1)]);
            }
        }
        $width = count($headers);
        foreach ($rows as $index => $row) {
            $cells = $row['cells'];
            if (count($cells) < $width) {
                $cells = array_pad($cells, $width, '');
            } elseif (count($cells) > $width) {
                $cells = array_slice($cells, 0, $width);
            }
            $rows[$index]['cells'] = $cells;
        }

        return [
            'ok' => true,
            'headers' => $headers,
            'rows' => $rows,
            'has_header' => $hasHeader,
            'delimiter' => $reader->delimiterLabel(),
            'encoding' => $reader->encoding(),
        ];
    }

    /**
     * Column metadata for the mapping form.
     *
     * @param array{headers:list<string>,rows:list<array{line:int,cells:list<string>}>}|null $table
     * @return list<array{index:int,header:string,suggested:string,sample:string,kind:string,filled:int,rows:int}>
     */
    public function columns(?array $table = null): array
    {
        $table ??= $this->table();
        $suggestions = $this->suggestMapping($table['headers'], $table['rows']);
        $columns = [];
        foreach ($table['headers'] as $index => $header) {
            $samples = [];
            $filled = 0;
            foreach (array_slice($table['rows'], 0, self::SAMPLE_ROWS) as $row) {
                $value = (string) ($row['cells'][$index] ?? '');
                if (N::text($value) !== '') {
                    $samples[] = $value;
                    $filled += 1;
                }
            }
            $columns[] = [
                'index' => $index,
                'header' => (string) $header,
                'suggested' => $suggestions[$index] ?? '',
                'sample' => $samples === [] ? '' : implode(' · ', array_slice($samples, 0, 3)),
                'kind' => N::detectColumnKind($samples),
                'filled' => $filled,
                'rows' => count($table['rows']),
            ];
        }
        return $columns;
    }

    /**
     * Auto-mapping: alias dictionary, field labels, value-kind fallback.
     *
     * @param list<string> $headers
     * @param list<array{line:int,cells:list<string>}> $rows
     * @return array<int,string>
     */
    public function suggestMapping(array $headers, array $rows): array
    {
        $profile = $this->mappingProfile($headers);
        if ($profile !== null) {
            $this->profileApplied = true;
            $existing = $this->existingFieldKeys();
            $clean = [];
            foreach ($profile as $index => $target) {
                $target = (string) $target;
                if (str_starts_with($target, N::TARGET_FIELD_PREFIX)) {
                    $fieldKey = substr($target, strlen(N::TARGET_FIELD_PREFIX));
                    if (!isset($existing[$fieldKey])) {
                        continue; // campo eliminato nel frattempo
                    }
                }
                if ($target !== '') {
                    $clean[(int) $index] = $target;
                }
            }
            return $clean;
        }
        $this->profileApplied = false;
        $aliases = [];
        foreach (self::HEADER_ALIASES as $target => $list) {
            foreach ($list as $rank => $alias) {
                $aliases[$alias] ??= ['target' => $target, 'rank' => $rank];
            }
        }
        $fieldKeys = [];
        foreach ($this->members->fieldDefinitions(false) as $def) {
            $key = (string) ($def['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $fieldKeys[N::matchKey($key)] ??= 'f:' . $key;
            $labels = json_decode((string) ($def['label_json'] ?? ''), true);
            if (is_array($labels)) {
                foreach ($labels as $text) {
                    $fieldKeys[N::matchKey((string) $text)] ??= 'f:' . $key;
                }
            }
        }

        /** @var array<int,array{target:string,rank:int}> $candidates */
        $candidates = [];
        foreach ($headers as $index => $header) {
            $key = N::matchKey((string) $header);
            if ($key === '') {
                continue;
            }
            $target = '';
            $rank = 0;
            if (isset($aliases[$key])) {
                $target = (string) $aliases[$key]['target'];
                $rank = (int) $aliases[$key]['rank'];
            } elseif (isset($fieldKeys[$key])) {
                $target = (string) $fieldKeys[$key];
            } else {
                $samples = [];
                foreach (array_slice($rows, 0, self::SAMPLE_ROWS) as $row) {
                    $samples[] = (string) ($row['cells'][$index] ?? '');
                }
                $target = match (N::detectColumnKind($samples)) {
                    'email' => 'f:email',
                    'fiscal_code' => 'f:fiscal_code',
                    default => '',
                };
            }
            // A profile field that no longer exists becomes a new field instead of being lost.
            if (str_starts_with($target, N::TARGET_FIELD_PREFIX)) {
                $fieldKey = substr($target, strlen(N::TARGET_FIELD_PREFIX));
                if (!isset($this->existingFieldKeys()[$fieldKey])) {
                    $target = N::TARGET_NEW_PREFIX . MemberFieldTypes::slugifyKey((string) $header);
                }
            }
            if ($target !== '') {
                $candidates[$index] = ['target' => $target, 'rank' => $rank];
            }
        }

        // One target per column: the most specific alias wins (e.g. card number over member id).
        $map = [];
        $used = [];
        uasort($candidates, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);
        foreach ($candidates as $index => $candidate) {
            $target = $candidate['target'];
            if (isset($used[$target])) {
                continue;
            }
            $used[$target] = true;
            $map[$index] = $target;
        }
        ksort($map);
        return $map;
    }

    /** @return array<string,true> */
    private function existingFieldKeys(): array
    {
        if ($this->existingFieldKeyCache !== null) {
            return $this->existingFieldKeyCache;
        }
        $keys = [];
        foreach ($this->members->fieldDefinitions(false) as $def) {
            $key = (string) ($def['key'] ?? '');
            if ($key !== '') {
                $keys[$key] = true;
            }
        }
        return $this->existingFieldKeyCache = $keys;
    }

    /**
     * Mapping targets grouped for the select: profile fields + system columns.
     *
     * @return list<array{label:string,options:list<array{value:string,label:string}>}>
     */
    public function targetGroups(): array
    {
        $profile = [];
        foreach ($this->members->fieldDefinitions(false) as $def) {
            $key = (string) ($def['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $label = localized($def['label_json'] ?? '');
            $profile[] = ['value' => 'f:' . $key, 'label' => $label !== '' ? $label : $key];
        }
        $system = [];
        foreach (self::SYSTEM_TARGETS as $value => $labelKey) {
            $system[] = ['value' => $value, 'label' => (string) __($labelKey)];
        }
        return [
            ['label' => (string) __('members.import.group_profile'), 'options' => $profile],
            ['label' => (string) __('members.import.group_membership'), 'options' => $system],
        ];
    }

    /** @return array<string,string> */
    public function defaultOptions(): array
    {
        $period = $this->members->currentPeriod();
        $types = $this->members->types(true);
        return [
            'period_id' => (string) (int) ($period['id'] ?? 0),
            'default_type_id' => (string) (int) ($types[0]['id'] ?? 0),
            'status' => 'active',
            'update_existing' => '1',
            'update_strategy' => 'fill_empty',
            'duplicate_policy' => 'first',
            'import_payments' => '1',
            'register_treasury' => '0',
            'treasury_date_source' => 'payment',
            'accept_consents' => '1',
            'create_cards' => '1',
            'default_payment_method' => 'cash',
        ];
    }

    /**
     * Sanitize posted options against the whitelist.
     *
     * @param array<string,mixed> $input
     * @return array<string,string>
     */
    public function normalizeOptions(array $input): array
    {
        $options = $this->defaultOptions();
        $flags = ['update_existing', 'import_payments', 'register_treasury', 'accept_consents', 'create_cards'];
        foreach (array_keys($options) as $key) {
            if (!array_key_exists($key, $input)) {
                if (in_array($key, $flags, true)) {
                    $options[$key] = '0';
                }
                continue;
            }
            $options[$key] = is_array($input[$key]) ? '' : (string) $input[$key];
        }
        $options['status'] = in_array($options['status'], MemberService::memberStatuses(), true) ? $options['status'] : 'active';
        $options['update_strategy'] = in_array($options['update_strategy'], ['fill_empty', 'overwrite', 'keep'], true)
            ? $options['update_strategy'] : 'fill_empty';
        $options['duplicate_policy'] = in_array($options['duplicate_policy'], ['first', 'last'], true)
            ? $options['duplicate_policy'] : 'first';
        $options['default_payment_method'] = in_array($options['default_payment_method'], ['cash', 'bank', 'other'], true)
            ? $options['default_payment_method'] : 'cash';
        $options['treasury_date_source'] = in_array($options['treasury_date_source'], ['payment', 'registration'], true)
            ? $options['treasury_date_source'] : 'payment';
        foreach (['period_id', 'default_type_id'] as $numeric) {
            $options[$numeric] = (string) max(0, (int) $options[$numeric]);
        }
        foreach ($flags as $flag) {
            $options[$flag] = in_array($options[$flag], ['1', 'on', 'true', 'yes'], true) ? '1' : '0';
        }
        return $options;
    }

    /**
     * Save map + options in session.
     *
     * @param array<int|string,mixed> $rawMap
     * @param array<string,mixed> $rawOptions
     * @return array{ok:bool,error?:string}
     */
    public function saveMapping(array $rawMap, array $rawOptions): array
    {
        $table = $this->table();
        if (empty($table['ok'])) {
            return ['ok' => false, 'error' => (string) ($table['error'] ?? 'members.import.error_no_file')];
        }
        $columns = count($table['headers']);
        $map = [];
        foreach ($rawMap as $index => $target) {
            $index = (int) $index;
            if ($index < 0 || $index >= $columns || !is_string($target)) {
                continue;
            }
            $target = trim($target);
            if ($target === '' || $target === N::TARGET_IGNORE) {
                continue;
            }
            if (!str_starts_with($target, 'f:') && !str_starts_with($target, 'sys:') && !str_starts_with($target, N::TARGET_NEW_PREFIX)) {
                continue;
            }
            $map[$index] = $target;
        }
        $targets = array_values($map);
        $duplicates = $this->duplicateTargets($map);
        if ($duplicates !== []) {
            return [
                'ok' => false,
                'error' => 'members.import.error_duplicate_target',
                'field' => $this->targetLabel($duplicates[0]),
            ];
        }
        $hasIdentity = in_array('f:first_name', $targets, true)
            || in_array('sys:member_number', $targets, true)
            || in_array('f:fiscal_code', $targets, true);
        if (!$hasIdentity) {
            return ['ok' => false, 'error' => 'members.import.error_need_identity'];
        }
        $state = $this->state() ?? [];
        $state['map'] = $map;
        $state['options'] = $this->normalizeOptions($rawOptions);
        $this->saveState($state);
        // Ricorda le associazioni per i prossimi file con le stesse intestazioni.
        $this->rememberMappingProfile($table['headers'], $map);
        return ['ok' => true];
    }

    /** @return array<int,string> */
    public function mappedTargets(): array
    {
        $state = $this->state() ?? [];
        $map = $state['map'] ?? [];
        return is_array($map) ? $map : [];
    }

    /**
     * Targets mapped to more than one column (order preserved).
     *
     * @param array<int,string> $map
     * @return list<string>
     */
    public function duplicateTargets(array $map): array
    {
        $seen = [];
        $duplicates = [];
        foreach ($map as $target) {
            $target = (string) $target;
            if ($target === '') {
                continue;
            }
            if (isset($seen[$target])) {
                $duplicates[$target] = $target;
                continue;
            }
            $seen[$target] = true;
        }
        return array_values($duplicates);
    }

    /** Human label of a mapping target (for error messages). */
    public function targetLabel(string $target): string
    {
        if (str_starts_with($target, N::TARGET_SYSTEM_PREFIX)) {
            $labelKey = self::SYSTEM_TARGETS[$target] ?? '';
            return $labelKey !== '' ? (string) __($labelKey) : $target;
        }
        if (str_starts_with($target, N::TARGET_FIELD_PREFIX)) {
            $key = substr($target, strlen(N::TARGET_FIELD_PREFIX));
            foreach ($this->members->fieldDefinitions(false) as $def) {
                if ((string) ($def['key'] ?? '') === $key) {
                    $label = localized($def['label_json'] ?? '');
                    return $label !== '' ? $label : $key;
                }
            }
            return $key;
        }
        if (str_starts_with($target, N::TARGET_NEW_PREFIX)) {
            return substr($target, strlen(N::TARGET_NEW_PREFIX));
        }
        return $target;
    }

    /** @return array<string,string> */
    public function options(): array
    {
        $state = $this->state() ?? [];
        $options = $state['options'] ?? [];
        return is_array($options) ? $this->normalizeOptions($options) : $this->defaultOptions();
    }

    /** @param list<string> $headers */
    private function signature(array $headers): string
    {
        return sha1(implode('|', array_map(static fn (string $h): string => N::matchKey($h), $headers)));
    }

    /** @return array<string,mixed> Stored mapping profiles (settings may hold raw JSON). */
    private function profiles(): array
    {
        $raw = $this->settings->get(self::PROFILE_SETTING, []);
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    /**
     * @param list<string> $headers
     * @return array<int,string>|null
     */
    private function mappingProfile(array $headers): ?array
    {
        $profiles = $this->profiles();
        if ($profiles === []) {
            return null;
        }
        $profile = $profiles[$this->signature($headers)] ?? null;
        if (!is_array($profile) || !is_array($profile['map'] ?? null)) {
            return null;
        }
        $map = [];
        foreach ($profile['map'] as $index => $target) {
            $map[(int) $index] = (string) $target;
        }
        return $map;
    }

    /**
     * @param list<string> $headers
     * @param array<int,string> $map
     */
    private function rememberMappingProfile(array $headers, array $map): void
    {
        $profiles = $this->profiles();
        $profiles[$this->signature($headers)] = ['map' => $map, 'saved_at' => date('c')];
        if (count($profiles) > 20) {
            $profiles = array_slice($profiles, -20, null, true);
        }
        $this->settings->set(self::PROFILE_SETTING, $profiles);
    }

    /**
     * Full analysis of the mapped file: normalized rows, duplicates, gaps.
     *
     * Con `$query` valorizzata l'anteprima mostra solo le righe che
     * corrispondono alla ricerca (nome, cognome, numero tessera, errori…):
     * la ricerca lavora su tutte le righe del file, non solo su quelle mostrate.
     *
     * @return array<string,mixed>
     */
    public function analyze(string $query = ''): array
    {
        $table = $this->table();
        if (empty($table['ok'])) {
            return ['ok' => false, 'error' => (string) ($table['error'] ?? 'members.import.error_no_file')];
        }
        $map = $this->mappedTargets();
        if ($map === []) {
            return ['ok' => false, 'error' => 'members.import.error_no_mapping'];
        }
        $options = $this->options();
        $fields = $this->fieldDefinitions();
        $computed = $this->computeRows($table, $map, $options, $fields);
        $allRows = $computed['rows'];
        $query = trim($query);
        $matchedRows = $query !== '' ? $this->filterRows($allRows, $query) : $allRows;
        $limit = $query !== '' ? self::PREVIEW_ROWS : self::PREVIEW_DEFAULT_ROWS;

        return [
            'ok' => true,
            'has_header' => $table['has_header'],
            'delimiter' => $table['delimiter'],
            'encoding' => $table['encoding'],
            'headers' => $table['headers'],
            'map' => $map,
            'options' => $options,
            'fields' => $fields,
            'types' => $this->members->types(false),
            'periods' => $this->members->periods(),
            'stats' => $computed['stats'],
            'rows' => array_slice($matchedRows, 0, $limit),
            'rows_total' => count($allRows),
            'rows_filtered' => count($matchedRows),
            'preview_query' => $query,
            'gaps' => $computed['gaps'],
            'source_name' => (string) ($this->state()['source_name'] ?? ''),
        ];
    }

    /**
     * Righe dell'anteprima che corrispondono a tutti i termini cercati,
     * senza distinzione fra maiuscole e accenti.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function filterRows(array $rows, string $query): array
    {
        $terms = [];
        foreach (preg_split('/\s+/u', $this->foldPreviewText($query)) ?: [] as $term) {
            if ($term !== '') {
                $terms[] = $term;
            }
        }
        if ($terms === []) {
            return $rows;
        }
        $matched = [];
        foreach ($rows as $row) {
            $haystack = $this->foldPreviewText($this->previewHaystack($row));
            foreach ($terms as $term) {
                if (!str_contains($haystack, $term)) {
                    continue 2;
                }
            }
            $matched[] = $row;
        }
        return $matched;
    }

    /** @param array<string,mixed> $row */
    private function previewHaystack(array $row): string
    {
        $parts = [
            (string) ($row['member_label'] ?? ''),
            (string) ($row['reason'] ?? ''),
            (string) ($row['line'] ?? ''),
        ];
        foreach (['resolved', 'values', 'errors', 'warnings'] as $key) {
            $bucket = $row[$key] ?? [];
            if (!is_array($bucket)) {
                continue;
            }
            foreach ($bucket as $value) {
                if (is_scalar($value)) {
                    $parts[] = (string) $value;
                }
            }
        }
        return implode(' ', $parts);
    }

    private function foldPreviewText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') {
            $value = $ascii;
        }
        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * @param array{headers:list<string>,rows:list<array{line:int,cells:list<string>}>} $table
     * @param array<int,string> $map
     * @param array<string,string> $options
     * @param array<string,array<string,mixed>> $fields
     * @return array{rows:list<array<string,mixed>>,stats:array<string,int>,gaps:array<string,mixed>}
     */
    private function computeRows(array $table, array $map, array $options, array $fields, array $resolutions = []): array
    {
        $current = $this->members->currentPeriod();
        $ctx = [
            'typeIndex' => $this->typeIndex(),
            'memberIndex' => $this->memberIndex(),
            'periodId' => (int) ($options['period_id'] > 0 ? $options['period_id'] : ($current['id'] ?? 0)),
            'typeOverrides' => is_array($resolutions['type_overrides'] ?? null) ? $resolutions['type_overrides'] : [],
            'statusOverrides' => is_array($resolutions['status_overrides'] ?? null) ? $resolutions['status_overrides'] : [],
            'methodOverrides' => is_array($resolutions['method_overrides'] ?? null) ? $resolutions['method_overrides'] : [],
        ];
        $gaps = ['types' => [], 'fields' => [], 'methods' => [], 'statuses' => []];
        $rows = [];
        foreach ($table['rows'] as $position => $row) {
            $rows[] = $this->evaluateRow($row, $position, $map, $options, $fields, $table['headers'], $ctx, $gaps);
        }

        // Duplicates inside the same file: first or last occurrence wins.
        $seen = [];
        foreach ($rows as $index => $row) {
            $identity = (string) ($row['identity'] ?? '');
            unset($rows[$index]['identity']);
            if ($identity === '') {
                continue;
            }
            if (!isset($seen[$identity])) {
                $seen[$identity] = $index;
                continue;
            }
            $previous = $seen[$identity];
            if ($options['duplicate_policy'] === 'last') {
                $rows[$previous]['action'] = 'skip';
                $rows[$previous]['reason'] = (string) __('members.import.reason_duplicate_row');
                $seen[$identity] = $index;
                continue;
            }
            $rows[$index]['action'] = 'skip';
            $rows[$index]['reason'] = (string) __('members.import.reason_duplicate_row');
            $rows[$index]['errors'] = [];
        }

        $stats = ['total' => count($rows), 'create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0];
        foreach ($rows as $row) {
            $action = (string) ($row['action'] ?? 'skip');
            $stats[in_array($action, ['create', 'update', 'skip', 'error'], true) ? $action : 'skip'] += 1;
        }
        $rows = array_values($rows);

        return ['rows' => $rows, 'stats' => $stats, 'gaps' => $gaps];
    }

    /**
     * Normalize one CSV row into database-ready values.
     *
     * @param array<int,string> $map
     * @param list<string> $cells
     * @param array<string,array<string,mixed>> $fields
     * @return array{values:array<string,string>,errors:array<string,string>,warnings:list<string>}
     */
    private function normalizeRowValues(array $map, array $cells, array $fields): array
    {
        $values = [];
        $errors = [];
        $warnings = [];
        foreach ($map as $index => $target) {
            $raw = N::text((string) ($cells[(int) $index] ?? ''));
            if ($raw === '') {
                continue;
            }
            if (str_starts_with($target, N::TARGET_NEW_PREFIX)) {
                $values[$target] = $raw;
                continue;
            }
            if (str_starts_with($target, N::TARGET_FIELD_PREFIX)) {
                $key = substr($target, strlen(N::TARGET_FIELD_PREFIX));
                $def = $fields[$key] ?? null;
                if ($def === null) {
                    $warnings[] = (string) __('members.import.warn_unknown_field', ['field' => $key]);
                    continue;
                }
                $value = $this->normalizeFieldValue($def, $raw, $warnings);
                if ($value !== null && $value !== '') {
                    $values[$target] = $value;
                }
                continue;
            }
            $systemKey = substr($target, strlen(N::TARGET_SYSTEM_PREFIX));
            $converted = $this->normalizeSystemValue($systemKey, $raw, $warnings);
            if ($converted !== null && $converted !== '') {
                $values[$target] = $converted;
            }
        }

        return ['values' => $values, 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param array<string,mixed> $def
     * @param list<string> $warnings
     */
    private function normalizeFieldValue(array $def, string $raw, array &$warnings): ?string
    {
        $type = (string) ($def['field_type'] ?? 'text');
        $label = localized($def['label_json'] ?? '');
        $value = match ($type) {
            'date', 'birth_date' => N::toDate($raw),
            'email' => N::toEmail($raw),
            'phone' => N::toPhone($raw),
            'fiscal_code' => N::toFiscalCode($raw),
            'gender' => N::toGender($raw),
            'language' => $this->normalizeLanguage($raw),
            'checkbox' => $this->normalizeCheckbox($raw),
            default => N::text($raw),
        };
        if (($value === null || $value === '') && $type !== 'checkbox') {
            $warnings[] = (string) __('members.import.warn_value_not_parsed', [
                'value' => $raw,
                'field' => $label !== '' ? $label : (string) ($def['key'] ?? ''),
            ]);
            return null;
        }
        if ($type === 'fiscal_code' && $value !== null && strlen((string) $value) === 16
            && !$this->validator->isValidFiscalCode((string) $value)) {
            $warnings[] = (string) __('members.import.warn_fiscal_code', ['value' => (string) $value]);
        }
        if ($type === 'email' && $value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $warnings[] = (string) __('members.import.warn_email', ['value' => (string) $value]);
        }
        return $value;
    }

    private function normalizeLanguage(string $raw): ?string
    {
        $key = N::matchKey($raw);
        return match (true) {
            $key === '' => null,
            str_starts_with($key, 'it'), str_contains($key, 'italian'), str_contains($key, 'italien') => 'it',
            str_starts_with($key, 'de'), str_contains($key, 'tedesc'), str_contains($key, 'deutsch') => 'de',
            str_starts_with($key, 'en'), str_contains($key, 'ingles'), str_contains($key, 'englisch') => 'en',
            default => 'other',
        };
    }

    private function normalizeCheckbox(string $raw): ?string
    {
        $bool = N::toBool($raw);
        return $bool === null ? null : ($bool ? '1' : '0');
    }

    /**
     * @param list<string> $warnings
     */
    private function normalizeSystemValue(string $key, string $raw, array &$warnings): ?string
    {
        return match ($key) {
            'member_number' => mb_substr(preg_replace('/\s+/', '', $raw) ?? $raw, 0, 50),
            'member_type_id', 'status', 'notes', 'card_qr', 'payment_method' => N::text($raw),
            'admitted_at', 'card_issued_on' => $this->expectDate($raw, $warnings),
            'paid_at' => $this->expectDateTime($raw, $warnings),
            'balance_due', 'paid_total' => $this->expectAmount($raw, $warnings),
            'card_active' => $this->normalizeCheckbox($raw) ?? '1',
            default => N::text($raw),
        };
    }

    /** @param list<string> $warnings */
    private function expectDate(string $raw, array &$warnings): ?string
    {
        $date = N::toDate($raw);
        if ($date === null) {
            $warnings[] = (string) __('members.import.warn_date', ['value' => $raw]);
        }
        return $date;
    }

    /** @param list<string> $warnings */
    private function expectDateTime(string $raw, array &$warnings): ?string
    {
        $dateTime = N::toDateTime($raw);
        if ($dateTime === null) {
            $warnings[] = (string) __('members.import.warn_date', ['value' => $raw]);
        }
        return $dateTime;
    }

    /** @param list<string> $warnings */
    private function expectAmount(string $raw, array &$warnings): ?string
    {
        $amount = N::toAmount($raw);
        if ($amount === null) {
            $warnings[] = (string) __('members.import.warn_amount', ['value' => $raw]);
            return null;
        }
        return number_format($amount, 2, '.', '');
    }

    /**
     * Register "new profile field" targets found in the file (references step).
     *
     * @param array<string,string> $values
     * @param array<int,string> $map
     * @param list<string> $headers
     * @param array<string,mixed> $gaps
     */
    private function collectNewFieldGaps(array $values, array $map, array $headers, array &$gaps): void
    {
        foreach (array_keys($values) as $target) {
            $target = (string) $target;
            if (!str_starts_with($target, N::TARGET_NEW_PREFIX)) {
                continue;
            }
            $slug = substr($target, strlen(N::TARGET_NEW_PREFIX));
            $index = array_search($target, $map, true);
            $header = $index === false ? $slug : (string) ($headers[(int) $index] ?? $slug);
            $gaps['fields'][$slug] ??= [
                'slug' => $slug,
                'header' => $header,
                'label' => N::labelFromHeader($header !== '' ? $header : $slug),
                'count' => 0,
            ];
            $gaps['fields'][$slug]['count'] += 1;
        }
    }

    /**
     * Resolve type/status/payment method for a row and register missing values.
     *
     * @param array<string,string> $values
     * @param array<string,string> $options
     * @param array{typeIndex:array<string,mixed>,memberIndex:array<string,mixed>,periodId:int} $ctx
     * @param array<string,mixed> $gaps
     * @param array<string,string> $errors
     * @return array{member_type_id:int,status:string,payment_method:?string,paid_amount:?float,member_type:?array<string,mixed>}
     */
    private function resolveRowReferences(string $action, array $values, array $options, array $ctx, array &$gaps, array &$errors): array
    {
        $typeRaw = N::text((string) ($values['sys:member_type_id'] ?? ''));
        $type = $typeRaw !== '' ? $this->resolveType($typeRaw, $ctx['typeIndex']) : null;
        if ($type === null && $typeRaw !== '') {
            $actionRaw = (string) ($ctx['typeOverrides'][N::matchKey($typeRaw)] ?? '');
            $overrideId = str_starts_with($actionRaw, 'map:') ? (int) substr($actionRaw, 4) : (int) $actionRaw;
            if ($overrideId > 0) {
                $type = $ctx['typeIndex']['byId'][$overrideId] ?? ['id' => $overrideId, 'name_json' => ''];
            }
        }
        if ($typeRaw !== '' && $type === null) {
            $slug = N::matchKey($typeRaw);
            $gaps['types'][$slug] ??= [
                'slug' => $slug,
                'label' => $typeRaw,
                'count' => 0,
                'suggested_it' => $typeRaw,
                'suggested_de' => $typeRaw,
                'suggested_en' => $typeRaw,
            ];
            $gaps['types'][$slug]['count'] += 1;
        }
        $typeId = $type !== null ? (int) $type['id'] : (int) $options['default_type_id'];
        if ($action === 'create' && $typeId <= 0) {
            $errors['member_type_id'] = (string) __('members.import.err_missing_type');
        }

        $statusRaw = N::text((string) ($values['sys:status'] ?? ''));
        $status = $statusRaw !== '' ? N::toStatus($statusRaw) : null;
        if ($statusRaw !== '' && $status === null) {
            $slug = N::matchKey($statusRaw);
            $override = (string) ($ctx['statusOverrides'][$slug] ?? '');
            if (in_array($override, MemberService::memberStatuses(), true)) {
                $status = $override;
            } else {
                $gaps['statuses'][$slug] ??= ['label' => $statusRaw, 'count' => 0];
                $gaps['statuses'][$slug]['count'] += 1;
            }
        }

        $paidAmount = N::toAmount((string) ($values['sys:paid_total'] ?? ''));
        $methodRaw = N::text((string) ($values['sys:payment_method'] ?? ''));
        $method = $methodRaw !== '' ? N::toPaymentMethod($methodRaw) : null;
        if ($methodRaw !== '' && $method === null && ($paidAmount ?? 0) > 0) {
            $slug = N::matchKey($methodRaw);
            $override = (string) ($ctx['methodOverrides'][$slug] ?? '');
            if (in_array($override, ['cash', 'bank', 'other', 'skip'], true)) {
                $method = $override;
            } else {
                $gaps['methods'][$slug] ??= ['label' => $methodRaw, 'count' => 0];
                $gaps['methods'][$slug]['count'] += 1;
            }
        }

        return [
            'member_type_id' => $typeId,
            'status' => $status ?? (string) $options['status'],
            'payment_method' => $method,
            'paid_amount' => $paidAmount,
            'member_type' => $type,
        ];
    }

    /**
     * Evaluate a single data row: identity, action, references, diff.
     *
     * @param array{line:int,cells:list<string>} $row
     * @param array<int,string> $map
     * @param array<string,string> $options
     * @param array<string,array<string,mixed>> $fields
     * @param list<string> $headers
     * @param array{typeIndex:array<string,mixed>,memberIndex:array<string,mixed>,periodId:int} $ctx
     * @param array<string,mixed> $gaps
     * @return array<string,mixed>
     */
    private function evaluateRow(array $row, int $position, array $map, array $options, array $fields, array $headers, array $ctx, array &$gaps): array
    {
        $line = (int) ($row['line'] ?? ($position + 1));
        $normalized = $this->normalizeRowValues($map, $row['cells'], $fields);
        $values = $normalized['values'];
        $warnings = $normalized['warnings'];
        $errors = [];

        $firstName = (string) ($values['f:first_name'] ?? '');
        $lastName = (string) ($values['f:last_name'] ?? '');
        $dob = (string) ($values['f:birth_date'] ?? '');
        $fiscalCode = (string) ($values['f:fiscal_code'] ?? '');
        $number = N::text((string) ($values['sys:member_number'] ?? ''));
        $label = trim($firstName . ' ' . $lastName);
        if ($label === '') {
            $label = $number !== '' ? '#' . $number : (string) __('members.import.row_unnamed');
        }

        $hasAnyValue = false;
        foreach ($values as $value) {
            if (N::text((string) $value) !== '') {
                $hasAnyValue = true;
                break;
            }
        }
        if (!$hasAnyValue) {
            return [
                'line' => $line,
                'action' => 'skip',
                'reason' => (string) __('members.import.reason_empty_row'),
                'member_id' => null,
                'member_label' => $label,
                'values' => [],
                'diff' => [],
                'errors' => [],
                'warnings' => [],
                'identity' => '',
                'resolved' => [],
            ];
        }

        $this->collectNewFieldGaps($values, $map, $headers, $gaps);

        $existing = $this->findExisting($firstName, $lastName, $dob, $fiscalCode, $number, $ctx['memberIndex']);
        $action = $existing === null ? 'create' : 'update';
        if ($action === 'update' && $options['update_existing'] !== '1') {
            $action = 'skip';
            $warnings[] = (string) __('members.import.warn_exists_skipped');
        }

        $resolved = $this->resolveRowReferences($action, $values, $options, $ctx, $gaps, $errors);

        if ($action === 'create') {
            if ($firstName === '') {
                $errors['first_name'] = (string) __('members.import.err_missing_first_name');
            }
            if ($lastName === '') {
                $errors['last_name'] = (string) __('members.import.err_missing_last_name');
            }
            if ($fiscalCode === '' && $number === '') {
                $warnings[] = (string) __('members.import.warn_no_identifier');
            }
        }
        if ($ctx['periodId'] <= 0) {
            $errors['membership_period_id'] = (string) __('members.import.err_missing_period');
        }

        $identity = $this->identityKey($firstName, $lastName, $dob);
        if ($identity === '' && $fiscalCode !== '') {
            $identity = 'cf:' . $fiscalCode;
        }
        if ($identity === '' && $number !== '') {
            $identity = 'num:' . N::matchKey($number);
        }

        $diff = $action === 'update' && $existing !== null
            ? $this->buildDiff($values, $existing, $fields, $number, $resolved['member_type_id'], $resolved['member_type'])
            : [];

        if ($errors !== []) {
            $action = 'error';
        }

        return [
            'line' => $line,
            'action' => $action,
            'reason' => '',
            'member_id' => isset($existing['id']) ? (int) $existing['id'] : null,
            'member_label' => $label,
            'values' => $values,
            'diff' => $diff,
            'errors' => $errors,
            'warnings' => array_values(array_unique($warnings)),
            'identity' => $identity,
            'resolved' => [
                'member_type_id' => $resolved['member_type_id'],
                'status' => $resolved['status'],
                'member_number' => $number,
                'payment_method' => $resolved['payment_method'],
                'paid_amount' => $resolved['paid_amount'],
                'period_id' => $ctx['periodId'],
            ],
        ];
    }

    /**
     * Field-by-field preview of what an update would change.
     *
     * @param array<string,string> $values
     * @param array<string,mixed> $existing
     * @param array<string,array<string,mixed>> $fields
     * @param array<string,mixed>|null $type
     * @return array<string,array{old:string,new:string}>
     */
    private function buildDiff(array $values, array $existing, array $fields, string $number, int $typeId, ?array $type): array
    {
        $diff = [];
        $currentValues = $this->memberFieldValues((int) $existing['id']);
        foreach ($values as $target => $value) {
            $target = (string) $target;
            if (!str_starts_with($target, N::TARGET_FIELD_PREFIX)) {
                continue;
            }
            $new = N::text((string) $value);
            if ($new === '') {
                continue;
            }
            $key = substr($target, strlen(N::TARGET_FIELD_PREFIX));
            $old = N::text((string) ($currentValues[$key] ?? ''));
            if ($old === $new) {
                continue;
            }
            $label = localized($fields[$key]['label_json'] ?? '');
            $diff[$label !== '' ? $label : $key] = ['old' => $old !== '' ? $old : '—', 'new' => $new];
        }

        $oldNumber = N::text((string) ($existing['member_number'] ?? ''));
        if ($number !== '' && $oldNumber !== $number) {
            $diff[(string) __('members.import.target_member_number')] = ['old' => $oldNumber !== '' ? $oldNumber : '—', 'new' => $number];
        }
        $newBalanceAmount = N::toAmount((string) ($values['sys:balance_due'] ?? ''));
        if ($newBalanceAmount !== null) {
            $oldBalanceAmount = (float) ($existing['balance_due'] ?? 0);
            if (abs($oldBalanceAmount - $newBalanceAmount) >= 0.01) {
                $diff[(string) __('members.import.target_balance_due')] = [
                    'old' => number_format($oldBalanceAmount, 2, ',', '.'),
                    'new' => number_format($newBalanceAmount, 2, ',', '.'),
                ];
            }
        }
        if ($typeId > 0 && $typeId !== (int) ($existing['member_type_id'] ?? 0) && $type !== null) {
            $diff[(string) __('members.import.target_member_type')] = [
                'old' => (string) __('members.import.type_id', ['id' => (string) ($existing['member_type_id'] ?? '')]),
                'new' => localized($type['name_json'] ?? ''),
            ];
        }
        return $diff;
    }

    /** @return array<string,array<string,mixed>> key => definition */
    private function fieldDefinitions(): array
    {
        $out = [];
        foreach ($this->members->fieldDefinitions(false) as $def) {
            $key = (string) ($def['key'] ?? '');
            if ($key !== '') {
                $out[$key] = $def;
            }
        }
        return $out;
    }

    /** @return array{list:list<array<string,mixed>>,byKey:array<string,array<string,mixed>>,byId:array<int,array<string,mixed>>} */
    private function typeIndex(): array
    {
        $list = $this->members->types(false);
        $byKey = [];
        $byId = [];
        foreach ($list as $type) {
            $byId[(int) $type['id']] = $type;
            $labels = json_decode((string) ($type['name_json'] ?? ''), true);
            if (is_array($labels)) {
                foreach ($labels as $label) {
                    $key = N::matchKey((string) $label);
                    if ($key !== '') {
                        $byKey[$key] ??= $type;
                    }
                }
            }
        }
        return ['list' => $list, 'byKey' => $byKey, 'byId' => $byId];
    }

    /**
     * Match a CSV type label against configured member types (accent/case insensitive).
     *
     * @param array{list:list<array<string,mixed>>,byKey:array<string,array<string,mixed>>} $typeIndex
     * @return array<string,mixed>|null
     */
    private function resolveType(string $raw, array $typeIndex): ?array
    {
        $key = N::matchKey($raw);
        if ($key === '') {
            return null;
        }
        if (isset($typeIndex['byKey'][$key])) {
            return $typeIndex['byKey'][$key];
        }
        foreach ($typeIndex['byKey'] as $candidate => $type) {
            if ($candidate === '' || strlen($candidate) < 4) {
                continue;
            }
            if (str_contains($key, $candidate) || str_contains($candidate, $key)) {
                return $type;
            }
        }
        return null;
    }

    /**
     * Index of existing members for dedupe (CF → number → name + birth date).
     *
     * @return array{byId:array<int,array<string,mixed>>,byNumber:array<string,array<string,mixed>>,byCf:array<string,array<string,mixed>>,byIdentity:array<string,array<string,mixed>>}
     */
    private function memberIndex(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT m.id, m.member_number, m.status, m.member_type_id, m.membership_period_id,
                    m.balance_due, m.admitted_at, m.notes,
                    MAX(CASE WHEN d.`key` = 'fiscal_code' THEN v.value END) AS fiscal_code,
                    MAX(CASE WHEN d.`key` = 'first_name' THEN v.value END) AS first_name,
                    MAX(CASE WHEN d.`key` = 'last_name' THEN v.value END) AS last_name,
                    MAX(CASE WHEN d.`key` = 'birth_date' THEN v.value END) AS birth_date
             FROM members m
             LEFT JOIN member_field_values v ON v.member_id = m.id
             LEFT JOIN member_field_definitions d ON d.id = v.field_definition_id
             GROUP BY m.id"
        );
        $byId = [];
        $byNumber = [];
        $byCf = [];
        $byIdentity = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $byId[$id] = $row;
            $numberKey = N::matchKey((string) ($row['member_number'] ?? ''));
            if ($numberKey !== '') {
                $byNumber[$numberKey] ??= $row;
            }
            $cf = N::toFiscalCode((string) ($row['fiscal_code'] ?? ''));
            if ($cf !== '') {
                $byCf[$cf] ??= $row;
            }
            $identity = $this->identityKey(
                (string) ($row['first_name'] ?? ''),
                (string) ($row['last_name'] ?? ''),
                (string) ($row['birth_date'] ?? '')
            );
            if ($identity !== '') {
                $byIdentity[$identity] ??= $row;
            }
        }
        return ['byId' => $byId, 'byNumber' => $byNumber, 'byCf' => $byCf, 'byIdentity' => $byIdentity];
    }

    /** @return array<string,string> */
    private function memberFieldValues(int $memberId): array
    {
        if (isset($this->fieldValueCache[$memberId])) {
            return $this->fieldValueCache[$memberId];
        }
        $rows = $this->db->fetchAll(
            'SELECT d.`key`, v.`value` FROM member_field_values v
             INNER JOIN member_field_definitions d ON d.id = v.field_definition_id
             WHERE v.member_id = :id',
            ['id' => $memberId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['key']] = (string) ($row['value'] ?? '');
        }
        $this->fieldValueCache[$memberId] = $out;
        return $out;
    }

    private function identityKey(string $first, string $last, string $dob): string
    {
        if ($first === '' || $last === '' || $dob === '') {
            return '';
        }
        return 'nm:' . N::matchKey($first) . '|' . N::matchKey($last) . '|' . $dob;
    }

    /**
     * @param array{byId:array<int,array<string,mixed>>,byNumber:array<string,array<string,mixed>>,byCf:array<string,array<string,mixed>>,byIdentity:array<string,array<string,mixed>>} $index
     * @return array<string,mixed>|null
     */
    private function findExisting(string $first, string $last, string $dob, string $fiscalCode, string $number, array $index): ?array
    {
        if ($fiscalCode !== '' && isset($index['byCf'][$fiscalCode])) {
            return $index['byCf'][$fiscalCode];
        }
        $numberKey = N::matchKey($number);
        if ($numberKey !== '' && isset($index['byNumber'][$numberKey])) {
            return $index['byNumber'][$numberKey];
        }
        $identity = $this->identityKey($first, $last, $dob);
        return $identity !== '' ? ($index['byIdentity'][$identity] ?? null) : null;
    }

    /**
     * Import the analyzed rows, creating the references confirmed by the operator.
     *
     * @param array<string,mixed> $resolutions
     * @return array{ok:bool,error?:string,report?:array<string,mixed>}
     */
    public function commit(array $resolutions, string $ip): array
    {
        $state = $this->state();
        $table = $this->table();
        if ($state === null || empty($table['ok'])) {
            return ['ok' => false, 'error' => 'members.import.error_no_file'];
        }
        $map = $this->mappedTargets();
        $options = $this->options();
        $fields = $this->fieldDefinitions();
        $preview = $this->computeRows($table, $map, $options, $fields);
        $prepared = $this->prepareReferences($resolutions, $preview['gaps'], $options, $fields);
        if (empty($prepared['ok'])) {
            return ['ok' => false, 'error' => (string) ($prepared['error'] ?? 'members.import.err_references')];
        }
        $options['period_id'] = (string) $prepared['period_id'];
        // New profile fields created in the references step become real field targets.
        $fields = $this->fieldDefinitions();
        foreach ($map as $index => $target) {
            if (!str_starts_with((string) $target, N::TARGET_NEW_PREFIX)) {
                continue;
            }
            $key = substr((string) $target, strlen(N::TARGET_NEW_PREFIX));
            if (isset($fields[$key])) {
                $map[$index] = N::TARGET_FIELD_PREFIX . $key;
            }
        }
        $computed = $this->computeRows($table, $map, $options, $fields, $prepared);

        // Rows whose type was explicitly ignored by the operator are skipped.
        foreach ($computed['rows'] as $index => $row) {
            $typeRaw = N::text((string) ($row['values']['sys:member_type_id'] ?? ''));
            if ($typeRaw !== '' && in_array(N::matchKey($typeRaw), $prepared['type_skips'], true)
                && in_array($row['action'], ['create', 'update'], true)) {
                $computed['rows'][$index]['action'] = 'skip';
                $computed['rows'][$index]['reason'] = (string) __('members.import.reason_type_skipped');
            }
        }

        $runId = $this->createRun($state, $map, $options);
        $this->prepareNumbers($computed['rows']);
        $report = [
            'run_id' => $runId,
            'total' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
            'created_fields' => $prepared['fields_created'],
            'created_types' => $prepared['types_created'],
        ];

        $batch = [];
        foreach ($computed['rows'] as $row) {
            $report['total'] += 1;
            if (!in_array($row['action'], ['create', 'update'], true)) {
                $report[$row['action'] === 'error' ? 'failed' : 'skipped'] += 1;
                $this->storeRow($runId, $row, (int) ($row['member_id'] ?? 0), null, null, $row['action'] === 'error' ? 'failed' : 'skipped');
                if ($row['action'] === 'error' && count($report['errors']) < 100) {
                    $report['errors'][] = [
                        'line' => (int) $row['line'],
                        'member' => (string) $row['member_label'],
                        'message' => implode(' · ', array_values($row['errors'])),
                    ];
                }
                continue;
            }
            $batch[] = $row;
            if (count($batch) >= self::BATCH_SIZE) {
                $this->writeBatch($batch, $fields, $options, $runId, $report, $ip);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->writeBatch($batch, $fields, $options, $runId, $report, $ip);
        }

        $this->finishRun($runId, $report);
        $this->rememberMappingProfile($table['headers'], $map);
        $_SESSION[self::REPORT_KEY] = $report;
        $this->audit->log('members.imported', 'member_import_run', (string) $runId, null, [
            'source' => (string) ($state['source_name'] ?? ''),
            'total' => $report['total'],
            'created' => $report['created'],
            'updated' => $report['updated'],
            'skipped' => $report['skipped'],
            'failed' => $report['failed'],
        ], $ip);

        return ['ok' => true, 'report' => $report];
    }

    /** @return array<string,mixed> */
    public function lastReport(): array
    {
        $report = $_SESSION[self::REPORT_KEY] ?? [];
        return is_array($report) ? $report : [];
    }

    /**
     * Create the references confirmed in step 3 (types, profile fields, period).
     *
     * @param array<string,mixed> $resolutions
     * @param array<string,mixed> $gaps
     * @param array<string,string> $options
     * @return array{ok:bool,error?:string,type_overrides:array<string,int>,type_skips:list<string>,period_id:int,fields_created:list<string>,types_created:list<string>}
     */
    private function prepareReferences(array $resolutions, array $gaps, array $options): array
    {
        $typeOverrides = [];
        $typeSkips = [];
        $typesCreated = [];
        $typeResolutions = is_array($resolutions['types'] ?? null) ? $resolutions['types'] : [];
        foreach ($gaps['types'] as $slug => $gap) {
            $request = is_array($typeResolutions[$slug] ?? null) ? $typeResolutions[$slug] : [];
            $action = (string) ($request['action'] ?? 'create');
            if (str_starts_with($action, 'map:')) {
                $request['type_id'] = substr($action, 4);
                $action = 'map';
            }
            if ($action === 'skip') {
                $typeSkips[] = (string) $slug;
                continue;
            }
            if ($action === 'map') {
                $typeId = (int) ($request['type_id'] ?? 0);
                if ($typeId <= 0 || !$this->db->fetch('SELECT id FROM member_types WHERE id = :id', ['id' => $typeId])) {
                    return $this->failure((string) __('members.import.err_type_map', ['type' => (string) $gap['label']]));
                }
                $typeOverrides[(string) $slug] = $typeId;
                continue;
            }
            $nameIt = trim((string) ($request['name_it'] ?? $gap['suggested_it'] ?? $gap['label']));
            $priceRaw = trim((string) ($request['price'] ?? ''));
            if ($nameIt === '') {
                return $this->failure((string) __('members.import.err_type_name'));
            }
            // Accetta 0 / 0,00 (gratis), 10,50 e 10.50.
            $price = $priceRaw === '' ? null : N::toAmount($priceRaw);
            if ($price === null || $price < 0) {
                return $this->failure((string) __('members.import.err_type_price', ['type' => $nameIt]));
            }
            $nameDe = trim((string) ($request['name_de'] ?? ''));
            $nameEn = trim((string) ($request['name_en'] ?? ''));
            $maxSort = (int) ($this->db->fetch('SELECT COALESCE(MAX(sort_order), 0) AS m FROM member_types')['m'] ?? 0);
            $typeId = $this->db->insert('member_types', [
                'name_json' => json_encode([
                    'it' => $nameIt,
                    'de' => $nameDe !== '' ? $nameDe : $nameIt,
                    'en' => $nameEn !== '' ? $nameEn : $nameIt,
                ], JSON_UNESCAPED_UNICODE),
                'price' => round($price, 2),
                'is_active' => 1,
                'sort_order' => $maxSort + 10,
            ]);
            $typeOverrides[(string) $slug] = $typeId;
            $typesCreated[] = $nameIt;
        }

        $fieldsCreated = [];
        $fieldResolutions = is_array($resolutions['fields'] ?? null) ? $resolutions['fields'] : [];
        $steps = [];
        foreach ($this->members->formSteps() as $step) {
            $stepKey = (string) ($step['key'] ?? '');
            if ($stepKey !== '') {
                $steps[] = $stepKey;
            }
        }
        foreach ($gaps['fields'] as $slug => $gap) {
            $request = is_array($fieldResolutions[$slug] ?? null) ? $fieldResolutions[$slug] : [];
            if ((string) ($request['action'] ?? 'create') === 'skip') {
                continue;
            }
            if ($this->db->fetch('SELECT id FROM member_field_definitions WHERE `key` = :k', ['k' => (string) $slug])) {
                continue;
            }
            $labelIt = trim((string) ($request['label_it'] ?? $gap['label']));
            if ($labelIt === '') {
                continue;
            }
            $typeRaw = trim((string) ($request['field_type'] ?? 'text'));
            $type = MemberFieldTypes::isValid($typeRaw) ? MemberFieldTypes::resolve($typeRaw, (string) $slug) : MemberFieldTypes::TEXT;
            $type = MemberFieldTypes::lockedTypeForKey((string) $slug) ?? $type;
            $step = trim((string) ($request['form_step'] ?? ''));
            if ($step === '' || !in_array($step, $steps, true)) {
                $step = $steps[0] ?? 'profile';
            }
            $labelDe = trim((string) ($request['label_de'] ?? ''));
            $labelEn = trim((string) ($request['label_en'] ?? ''));
            $maxSort = (int) ($this->db->fetch('SELECT COALESCE(MAX(sort_order), 0) AS m FROM member_field_definitions')['m'] ?? 0);
            $this->db->insert('member_field_definitions', [
                'key' => (string) $slug,
                'field_type' => $type,
                'label_json' => json_encode([
                    'it' => $labelIt,
                    'de' => $labelDe !== '' ? $labelDe : $labelIt,
                    'en' => $labelEn !== '' ? $labelEn : $labelIt,
                ], JSON_UNESCAPED_UNICODE),
                'is_required' => 0,
                'validation_rule' => MemberFieldTypes::validationRule($type, false, (string) $slug),
                'is_enabled' => 1,
                'sort_order' => $maxSort + 10,
                'form_step' => $step,
            ]);
            $fieldsCreated[] = $labelIt;
        }

        $periodId = (int) ($options['period_id'] ?? 0);
        if ($periodId > 0 && !$this->db->fetch('SELECT id FROM membership_periods WHERE id = :id', ['id' => $periodId])) {
            $periodId = 0;
        }
        if ($periodId <= 0) {
            $periodRequest = is_array($resolutions['period'] ?? null) ? $resolutions['period'] : [];
            $starts = trim((string) ($periodRequest['starts_on'] ?? ''));
            $ends = trim((string) ($periodRequest['ends_on'] ?? ''));
            if ($starts === '' || $ends === '' || $starts > $ends) {
                return $this->failure((string) __('members.import.err_missing_period'));
            }
            $result = $this->members->persistPeriodsConfig(['starts_on' => $starts, 'ends_on' => $ends]);
            if (empty($result['ok'])) {
                return $this->failure((string) __('members.import.err_missing_period'));
            }
            $found = $this->db->fetch(
                'SELECT id FROM membership_periods WHERE starts_on = :s AND ends_on = :e ORDER BY id DESC LIMIT 1',
                ['s' => $starts, 'e' => $ends]
            );
            $periodId = (int) ($found['id'] ?? 0);
            if ($periodId <= 0) {
                return $this->failure((string) __('members.import.err_missing_period'));
            }
        }

        return [
            'ok' => true,
            'type_overrides' => $typeOverrides,
            'type_skips' => $typeSkips,
            'status_overrides' => $this->statusOverrides($resolutions),
            'method_overrides' => $this->methodOverrides($resolutions),
            'period_id' => $periodId,
            'fields_created' => $fieldsCreated,
            'types_created' => $typesCreated,
        ];
    }

    /**
     * @param array<string,mixed> $resolutions
     * @return array<string,string>
     */
    private function statusOverrides(array $resolutions): array
    {
        $out = [];
        $raw = is_array($resolutions['statuses'] ?? null) ? $resolutions['statuses'] : [];
        foreach ($raw as $slug => $row) {
            $status = is_array($row) ? (string) ($row['status'] ?? '') : '';
            if (in_array($status, MemberService::memberStatuses(), true)) {
                $out[(string) $slug] = $status;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $resolutions
     * @return array<string,string>
     */
    private function methodOverrides(array $resolutions): array
    {
        $out = [];
        $raw = is_array($resolutions['methods'] ?? null) ? $resolutions['methods'] : [];
        foreach ($raw as $slug => $row) {
            $action = is_array($row) ? (string) ($row['action'] ?? '') : '';
            if (in_array($action, ['cash', 'bank', 'other', 'skip'], true)) {
                $out[(string) $slug] = $action;
            }
        }
        return $out;
    }

    /**
     * @return array{ok:false,error:string,type_overrides:array<string,int>,type_skips:list<string>,status_overrides:array<string,string>,method_overrides:array<string,string>,period_id:int,fields_created:list<string>,types_created:list<string>}
     */
    private function failure(string $error): array
    {
        return [
            'ok' => false,
            'error' => $error,
            'type_overrides' => [],
            'type_skips' => [],
            'status_overrides' => [],
            'method_overrides' => [],
            'period_id' => 0,
            'fields_created' => [],
            'types_created' => [],
        ];
    }

    /**
     * Write a chunk of rows inside a transaction.
     *
     * Ogni riga è protetta da un SAVEPOINT: un errore su una riga non fa fallire
     * le altre righe del blocco e il motivo reale finisce nel report.
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string,array<string,mixed>> $fields
     * @param array<string,string> $options
     * @param array<string,mixed> $report
     */
    private function writeBatch(array $rows, array $fields, array $options, int $runId, array &$report, string $ip): void
    {
        /** @var list<array<string,mixed>> $records */
        $records = [];
        $this->db->beginTransaction();
        try {
            foreach ($rows as $index => $row) {
                $this->db->query('SAVEPOINT socly_import_row');
                try {
                    $records[$index] = $this->writeRow($row, $fields, $options, $ip);
                } catch (\Throwable $e) {
                    $this->db->query('ROLLBACK TO SAVEPOINT socly_import_row');
                    $this->logRowFailure($runId, $row, $e);
                    $records[$index] = ['row' => $row, 'result' => 'failed', 'error' => $this->writeErrorReason($e)];
                }
                $this->db->query('RELEASE SAVEPOINT socly_import_row');
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            // Errore di transazione (non della singola riga): il blocco non è recuperabile.
            $this->db->rollBack();
            $this->logRowFailure($runId, ['line' => 0, 'member_label' => 'batch'], $e);
            $reason = $this->writeErrorReason($e);
            foreach ($rows as $index => $row) {
                $previous = $records[$index] ?? null;
                if (is_array($previous) && ($previous['result'] ?? '') === 'failed') {
                    continue;
                }
                $records[$index] = ['row' => $row, 'result' => 'failed', 'error' => $reason];
            }
        }

        foreach ($rows as $index => $row) {
            $record = $records[$index] ?? ['row' => $row, 'result' => 'failed', 'error' => (string) __('members.import.err_write')];
            $result = (string) ($record['result'] ?? 'failed');
            if ($result === 'failed') {
                $message = (string) ($record['error'] ?? __('members.import.err_write'));
                $report['failed'] += 1;
                $this->storeRow($runId, $record['row'], 0, null, null, 'failed', $message);
                if (count($report['errors']) < 100) {
                    $report['errors'][] = [
                        'line' => (int) ($record['row']['line'] ?? 0),
                        'member' => (string) ($record['row']['member_label'] ?? ''),
                        'message' => $message,
                    ];
                }
                continue;
            }
            $report[$result === 'created' ? 'created' : 'updated'] += 1;
            $this->storeRow(
                $runId,
                $record['row'],
                (int) ($record['member_id'] ?? 0),
                $record['card_id'] ?? null,
                $record['payment_id'] ?? null,
                $result
            );
        }
    }

    /** @param array<string,mixed> $row */
    private function logRowFailure(int $runId, array $row, \Throwable $e): void
    {
        try {
            app('logger')->error('members.import.row_failed', [
                'run' => $runId,
                'line' => (int) ($row['line'] ?? 0),
                'member' => (string) ($row['member_label'] ?? ''),
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable) {
        }
    }

    /**
     * Prenota i numeri tessera: quelli già in archivio e quelli presenti nel file.
     * Così due righe non possono ricevere lo stesso numero.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function prepareNumbers(array $rows): void
    {
        $this->existingNumbers = [];
        $this->reservedNumbers = [];
        $this->usedNumbers = [];
        $this->numberCursor = 0;

        foreach ($this->db->fetchAll('SELECT member_number FROM members') as $row) {
            $number = N::text((string) ($row['member_number'] ?? ''));
            if ($number === '') {
                continue;
            }
            $this->existingNumbers[$number] = true;
            if (preg_match('/^(\d+)$/', $number, $m) === 1) {
                $this->numberCursor = max($this->numberCursor, (int) $m[1]);
            }
        }
        foreach ($rows as $row) {
            $resolved = is_array($row['resolved'] ?? null) ? $row['resolved'] : [];
            $number = N::text((string) ($resolved['member_number'] ?? ''));
            if ($number === '') {
                continue;
            }
            $this->reservedNumbers[$number] = true;
            if (preg_match('/^(\d+)$/', $number, $m) === 1) {
                $this->numberCursor = max($this->numberCursor, (int) $m[1]);
            }
        }
    }

    /**
     * Usa il numero del file se è libero, altrimenti ne genera uno nuovo
     * (mai un numero prenotato da un'altra riga o già in archivio).
     */
    private function allocateNumber(string $candidate): string
    {
        $candidate = N::text($candidate);
        $isCandidateFree = $candidate !== ''
            && !isset($this->existingNumbers[$candidate])
            && !isset($this->usedNumbers[$candidate]);
        if ($isCandidateFree) {
            $this->usedNumbers[$candidate] = true;
            return mb_substr($candidate, 0, 50);
        }
        $guard = 0;
        do {
            $this->numberCursor += 1;
            $number = (string) $this->numberCursor;
            $guard += 1;
        } while (
            $guard < 100000
            && (isset($this->existingNumbers[$number])
                || isset($this->reservedNumbers[$number])
                || isset($this->usedNumbers[$number]))
        );
        $this->usedNumbers[$number] = true;
        return $number;
    }

    /** Messaggio leggibile per un errore di scrittura. */
    private function writeErrorReason(\Throwable $e): string
    {
        $message = $e->getMessage();
        if (str_contains($message, 'uq_member_number')) {
            return (string) __('members.import.err_write_duplicate_number');
        }
        if (str_contains($message, 'uq_card_number')) {
            return (string) __('members.import.err_write_duplicate_card');
        }
        if (preg_match("/Duplicate entry '([^']+)' for key '([^']+)'/", $message, $m) === 1) {
            return (string) __('members.import.err_write_duplicate', ['value' => $m[1], 'key' => $m[2]]);
        }
        if (stripos($message, 'foreign key') !== false) {
            return (string) __('members.import.err_write_reference');
        }
        return (string) __('members.import.err_write');
    }

    /**
     * Persist a single analyzed row (member + fields + card + payment + audit).
     *
     * @param array<string,mixed> $row
     * @param array<string,array<string,mixed>> $fields
     * @param array<string,string> $options
     * @return array{row:array<string,mixed>,result:string,member_id:int,card_id:?int,payment_id:?int}
     */
    private function writeRow(array $row, array $fields, array $options, string $ip): array
    {
        $values = is_array($row['values'] ?? null) ? $row['values'] : [];
        $resolved = is_array($row['resolved'] ?? null) ? $row['resolved'] : [];
        $action = (string) ($row['action'] ?? 'create');
        $memberId = (int) ($row['member_id'] ?? 0);
        $before = null;
        if ($action === 'update' && $memberId > 0) {
            $before = $this->db->fetch('SELECT * FROM members WHERE id = :id', ['id' => $memberId]);
            if ($before === null) {
                $action = 'create';
                $memberId = 0;
            }
        }

        $paidAmount = (float) ($resolved['paid_amount'] ?? 0);
        $typeId = (int) ($resolved['member_type_id'] ?? 0);
        $periodId = (int) ($resolved['period_id'] ?? 0);
        $status = (string) ($resolved['status'] ?? 'active');
        $balanceValue = N::toAmount((string) ($values['sys:balance_due'] ?? ''));
        if ($balanceValue === null) {
            $balanceValue = max(0, round(
                $this->typePrice($typeId) - ($options['import_payments'] === '1' ? $paidAmount : 0),
                2
            ));
        }

        if ($action === 'create') {
            $number = $this->allocateNumber((string) ($resolved['member_number'] ?? ''));
            $admittedAt = $values['sys:admitted_at'] ?? ($status === 'active' ? date('Y-m-d') : null);
            $memberId = $this->db->insert('members', [
                'member_number' => mb_substr($number, 0, 50),
                'member_type_id' => $typeId,
                'guardian_member_id' => null,
                'membership_period_id' => $periodId,
                'status' => $status,
                'admitted_at' => $admittedAt,
                'notes' => $values['sys:notes'] ?? null,
                'balance_due' => $balanceValue,
            ]);
            $result = 'created';
        } else {
            $result = 'updated';
        }

        if ($action === 'update') {
            $patch = [];
            $number = N::text((string) ($resolved['member_number'] ?? ''));
            if ($number !== '' && $number !== (string) ($before['member_number'] ?? '')) {
                $taken = $this->db->fetch(
                    'SELECT id FROM members WHERE member_number = :n AND id <> :id',
                    ['n' => $number, 'id' => $memberId]
                );
                if ($taken === null) {
                    $patch['member_number'] = mb_substr($number, 0, 50);
                }
            }
            if ($typeId > 0) {
                $patch['member_type_id'] = $typeId;
            }
            if (array_key_exists('sys:status', $values)) {
                $patch['status'] = $status;
            }
            if (array_key_exists('sys:admitted_at', $values)) {
                $patch['admitted_at'] = $values['sys:admitted_at'];
            }
            if (array_key_exists('sys:notes', $values)) {
                $patch['notes'] = $values['sys:notes'];
            }
            if (array_key_exists('sys:balance_due', $values)) {
                $patch['balance_due'] = $balanceValue;
            }
            if ($patch !== []) {
                $this->db->update('members', $patch, 'id = :id', ['id' => $memberId]);
            }
        }

        $this->upsertFieldValues($memberId, $values, $fields, $options, $result === 'created');
        $cardId = $this->upsertCard($memberId, $values, $resolved, $options);
        $paymentId = $this->recordImportedPayment($memberId, $values, $resolved, $options);
        $this->auditMember($memberId, $before, $ip, $result);

        return [
            'row' => $row,
            'result' => $result,
            'member_id' => $memberId,
            'card_id' => $cardId,
            'payment_id' => $paymentId,
        ];
    }

    /** Price of a member type (0 when unknown). */
    private function typePrice(int $typeId): float
    {
        if (isset($this->typePriceCache[$typeId])) {
            return $this->typePriceCache[$typeId];
        }
        $row = $typeId > 0 ? $this->db->fetch('SELECT price FROM member_types WHERE id = :id', ['id' => $typeId]) : null;
        $this->typePriceCache[$typeId] = (float) ($row['price'] ?? 0);
        return $this->typePriceCache[$typeId];
    }

    /**
     * Write profile field values honouring the chosen update strategy.
     *
     * @param array<string,string> $values
     * @param array<string,array<string,mixed>> $fields
     * @param array<string,string> $options
     */
    private function upsertFieldValues(int $memberId, array $values, array $fields, array $options, bool $isNew): void
    {
        $strategy = (string) ($options['update_strategy'] ?? 'fill_empty');
        $incoming = [];
        foreach ($values as $target => $value) {
            $target = (string) $target;
            if (!str_starts_with($target, N::TARGET_FIELD_PREFIX)) {
                continue;
            }
            $key = substr($target, strlen(N::TARGET_FIELD_PREFIX));
            if (isset($fields[$key])) {
                $incoming[$key] = (string) $value;
            }
        }
        if ($options['accept_consents'] === '1') {
            // Consensi dichiarati già raccolti: valgono per i nuovi soci e
            // completano quelli mancanti sui soci aggiornati (mai sovrascritti).
            foreach (['privacy_ack', 'statute_ack'] as $consent) {
                if (isset($fields[$consent])) {
                    $incoming[$consent] = '1';
                }
            }
        }
        if (isset($fields['preferred_language']) && !isset($incoming['preferred_language'])) {
            $incoming['preferred_language'] = (string) config('app.locale', 'it');
        }
        if ($incoming === []) {
            return;
        }

        $current = $this->memberFieldValues($memberId);
        foreach ($incoming as $key => $value) {
            if (!$isNew && $strategy === 'keep') {
                continue;
            }
            if (!$isNew && $strategy === 'fill_empty' && N::text((string) ($current[$key] ?? '')) !== '') {
                continue;
            }
            $fieldId = (int) ($fields[$key]['id'] ?? 0);
            if ($fieldId <= 0) {
                continue;
            }
            $existing = $this->db->fetch(
                'SELECT id FROM member_field_values WHERE member_id = :m AND field_definition_id = :f',
                ['m' => $memberId, 'f' => $fieldId]
            );
            if ($existing !== null) {
                $this->db->update('member_field_values', ['value' => $value], 'id = :id', ['id' => (int) $existing['id']]);
            } else {
                $this->db->insert('member_field_values', [
                    'member_id' => $memberId,
                    'field_definition_id' => $fieldId,
                    'value' => $value,
                ]);
            }
        }
        $this->fieldValueCache[$memberId] = $this->mergeFieldCache($current, $incoming);
    }

    /**
     * @param array<string,string> $current
     * @param array<string,string> $incoming
     * @return array<string,string>
     */
    private function mergeFieldCache(array $current, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            $current[$key] = $value;
        }
        return $current;
    }

    /**
     * Create or refresh the membership card (ID socio = numero tessera).
     *
     * @param array<string,string> $values
     * @param array<string,mixed> $resolved
     * @param array<string,string> $options
     */
    private function upsertCard(int $memberId, array $values, array $resolved, array $options): ?int
    {
        $hasCardData = array_key_exists('sys:card_qr', $values)
            || array_key_exists('sys:card_issued_on', $values)
            || array_key_exists('sys:card_active', $values);
        if (!$hasCardData && $options['create_cards'] !== '1') {
            return null;
        }
        $member = $this->db->fetch('SELECT member_number, member_type_id FROM members WHERE id = :id', ['id' => $memberId]);
        $cardNumber = N::text((string) ($member['member_number'] ?? ''));
        if ($cardNumber === '') {
            return null;
        }
        $payload = [
            'member_id' => $memberId,
            'card_number' => mb_substr($cardNumber, 0, 50),
            'member_type_id' => (int) ($member['member_type_id'] ?? 0) ?: null,
        ];
        if (array_key_exists('sys:card_qr', $values)) {
            $payload['qr_code'] = mb_substr((string) $values['sys:card_qr'], 0, 120);
        }
        if (array_key_exists('sys:card_issued_on', $values)) {
            $payload['issued_on'] = (string) $values['sys:card_issued_on'];
        }
        if (array_key_exists('sys:card_active', $values)) {
            $payload['is_active'] = $values['sys:card_active'] === '1' ? 1 : 0;
        }
        $existing = $this->db->fetch('SELECT id FROM membership_cards WHERE card_number = :n', ['n' => $payload['card_number']]);
        if ($existing !== null) {
            $this->db->update('membership_cards', $payload, 'id = :id', ['id' => (int) $existing['id']]);
            return (int) $existing['id'];
        }
        return $this->db->insert('membership_cards', $payload);
    }

    /**
     * Store the historical payment coming from the CSV (amount + last payment date).
     *
     * @param array<string,string> $values
     * @param array<string,mixed> $resolved
     * @param array<string,string> $options
     */
    private function recordImportedPayment(int $memberId, array $values, array $resolved, array $options): ?int
    {
        if ($options['import_payments'] !== '1') {
            return null;
        }
        $amount = N::toAmount((string) ($values['sys:paid_total'] ?? ''));
        if ($amount === null || $amount <= 0) {
            return null;
        }
        $method = (string) ($resolved['payment_method'] ?? '') !== ''
            ? (string) $resolved['payment_method']
            : (string) $options['default_payment_method'];
        if (!in_array($method, ['cash', 'bank', 'other'], true)) {
            $method = 'cash';
        }
        $paidAt = (string) ($values['sys:paid_at'] ?? '');
        $admittedAt = (string) ($values['sys:admitted_at'] ?? '');
        // Data del pagamento: quella del file, altrimenti la data di iscrizione (mai "oggi").
        $paymentDate = $paidAt !== ''
            ? $paidAt
            : ($admittedAt !== '' ? $admittedAt . ' 00:00:00' : date('Y-m-d H:i:s'));
        $paymentData = [
            'member_id' => $memberId,
            'amount' => $amount,
            'method' => $method,
            'type' => 'membership',
            'note' => (string) __('members.import.payment_note'),
            'created_by' => auth_user()['id'] ?? null,
            'created_at' => $paymentDate,
        ];
        // Idempotenza: reimportando lo stesso file il pagamento non viene duplicato.
        $duplicate = $this->db->fetch(
            "SELECT id FROM payments
             WHERE member_id = :member AND amount = :amount AND method = :method
               AND `type` = 'membership'
               AND DATE(created_at) = DATE(:paid_on)
             LIMIT 1",
            [
                'member' => $memberId,
                'amount' => $amount,
                'method' => $method,
                'paid_on' => $paymentDate,
            ]
        );
        if ($duplicate !== null) {
            return (int) $duplicate['id'];
        }
        $paymentId = $this->db->insert('payments', $paymentData);
        if ($options['register_treasury'] === '1') {
            // Data del movimento in prima cassa: quella del pagamento (predefinita).
            // Se il file non ha la data di pagamento si usa l'iscrizione, mai "oggi".
            $useRegistration = ($options['treasury_date_source'] ?? 'payment') === 'registration';
            $movementDate = $useRegistration && $admittedAt !== ''
                ? substr($admittedAt, 0, 10)
                : substr($paymentDate, 0, 10);
            $this->treasury->autoRegisterFromPayment(
                $paymentId,
                $memberId,
                $amount,
                $method,
                $movementDate
            );
        }
        return $paymentId;
    }

    /**
     * Audit + plugin events for an imported member.
     *
     * @param array<string,mixed>|null $before
     */
    private function auditMember(int $memberId, ?array $before, string $ip, string $result): void
    {
        $after = $this->db->fetch('SELECT * FROM members WHERE id = :id', ['id' => $memberId]);
        if ($after === null) {
            return;
        }
        if ($result === 'created') {
            $this->audit->log('member.created', 'member', (string) $memberId, null, $after, $ip);
            $this->plugins->fire('member.created', $after);
            return;
        }
        $this->audit->log('member.updated', 'member', (string) $memberId, $before, $after, $ip);
        $this->plugins->fire('member.updated', $before, $after);
    }

    /**
     * Create the import run row (traceability + report link).
     *
     * @param array<string,mixed> $state
     * @param array<int,string> $map
     * @param array<string,string> $options
     */
    private function createRun(array $state, array $map, array $options): int
    {
        $delimiter = (string) ($state['delimiter'] ?? '');
        return $this->db->insert('member_import_runs', [
            'user_id' => auth_user()['id'] ?? null,
            'source_name' => mb_substr((string) ($state['source_name'] ?? ''), 0, 255),
            'source_hash' => ($state['source_hash'] ?? null) !== null ? (string) $state['source_hash'] : null,
            'delimiter' => $delimiter !== '' ? mb_substr($delimiter, 0, 4) : null,
            'encoding' => mb_substr((string) ($state['encoding'] ?? ''), 0, 32),
            'mapping_json' => json_encode($map, JSON_UNESCAPED_UNICODE),
            'options_json' => json_encode($options, JSON_UNESCAPED_UNICODE),
            'status' => 'committed',
        ]);
    }

    /**
     * Persist one processed row of the run (report + error CSV).
     *
     * @param array<string,mixed> $row
     */
    private function storeRow(int $runId, array $row, int $memberId, ?int $cardId, ?int $paymentId, string $result, ?string $message = null): void
    {
        $action = (string) ($row['action'] ?? 'skip');
        $action = in_array($action, ['create', 'update', 'skip', 'error'], true) ? $action : 'skip';
        if ($result === 'failed') {
            $action = 'error';
        }
        $errors = is_array($row['errors'] ?? null) ? $row['errors'] : [];
        $message ??= (string) ($row['reason'] ?? '');
        if ($message === '' && $errors !== []) {
            $message = (string) reset($errors);
        }
        $this->db->insert('member_import_rows', [
            'run_id' => $runId,
            'line_no' => (int) ($row['line'] ?? 0),
            'member_id' => $memberId > 0 ? $memberId : null,
            'card_id' => $cardId,
            'payment_id' => $paymentId,
            'action' => $action,
            'result' => in_array($result, ['created', 'updated', 'skipped', 'failed'], true) ? $result : null,
            'message' => $message !== '' ? mb_substr($message, 0, 255) : null,
            'payload_json' => json_encode($row['values'] ?? [], JSON_UNESCAPED_UNICODE),
            'diff_json' => empty($row['diff']) ? null : json_encode($row['diff'], JSON_UNESCAPED_UNICODE),
            'errors_json' => $errors === [] ? null : json_encode($errors, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @param array<string,mixed> $report */
    private function finishRun(int $runId, array $report): void
    {
        $this->db->update('member_import_runs', [
            'stats_json' => json_encode([
                'total' => $report['total'] ?? 0,
                'created' => $report['created'] ?? 0,
                'updated' => $report['updated'] ?? 0,
                'skipped' => $report['skipped'] ?? 0,
                'failed' => $report['failed'] ?? 0,
                'created_types' => $report['created_types'] ?? [],
                'created_fields' => $report['created_fields'] ?? [],
            ], JSON_UNESCAPED_UNICODE),
            'status' => 'committed',
            'committed_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $runId]);
    }

    /**
     * Failed rows of a run (used by the error CSV download).
     *
     * @return list<array<string,mixed>>
     */
    public function errorRows(int $runId): array
    {
        if ($runId <= 0) {
            return [];
        }
        return $this->db->fetchAll(
            "SELECT line_no, action, result, message, payload_json, errors_json
             FROM member_import_rows
             WHERE run_id = :id AND result = 'failed'
             ORDER BY line_no ASC",
            ['id' => $runId]
        );
    }
}
