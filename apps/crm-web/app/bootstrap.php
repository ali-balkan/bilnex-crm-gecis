<?php

$config = require __DIR__ . '/../config.php';
$databasePackagePaths = [
    dirname(__DIR__, 3) . '/packages/database/src',
    dirname(__DIR__) . '/packages/database/src',
];
foreach ($databasePackagePaths as $databasePackage) {
    if (is_file($databasePackage . '/ReadOnlySqlServerConnection.php')) {
        require_once $databasePackage . '/ReadOnlySqlServerConnection.php';
    }
    if (is_file($databasePackage . '/CustomerReadRepository.php')) {
        require_once $databasePackage . '/CustomerReadRepository.php';
    }
    if (is_file($databasePackage . '/CustomerWriteRepository.php')) {
        require_once $databasePackage . '/CustomerWriteRepository.php';
    }
}
date_default_timezone_set($config['timezone']);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

const ROLE_ADMIN = 'admin';
const ROLE_MANAGER = 'yonetici';
const ROLE_CHANNEL_MANAGER = 'bayi_kanal_yoneticisi';
const ROLE_CHANNEL_SPECIALIST = 'bayi_kanal_uzmani';
const ROLE_FIELD_SALES = 'saha_satis';
const ROLE_CHANNEL = ROLE_CHANNEL_SPECIALIST;
const ROLE_SALES = ROLE_FIELD_SALES;
const ROLE_LEGACY_CHANNEL = 'bayi_kanal';
const ROLE_LEGACY_SALES = 'satis';

function app_config(?string $key = null)
{
    global $config;
    return $key === null ? $config : $config[$key];
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbPath = app_config('db_path');
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
}

function bilnex_sql_server(): ReadOnlySqlServerConnection
{
    static $connection = null;
    if ($connection instanceof ReadOnlySqlServerConnection) {
        return $connection;
    }

    $connection = new ReadOnlySqlServerConnection(app_config('sql_server'));
    return $connection;
}

function bilnex_customer_reader(): CustomerReadRepository
{
    static $repository = null;
    if ($repository instanceof CustomerReadRepository) {
        return $repository;
    }

    $repository = new CustomerReadRepository(bilnex_sql_server());
    return $repository;
}

function bilnex_customer_writer(): CustomerWriteRepository
{
    static $repository = null;
    if ($repository instanceof CustomerWriteRepository) {
        return $repository;
    }

    $repository = new CustomerWriteRepository(app_config('sql_server'));
    return $repository;
}

function company_source(): string
{
    return app_config('company_source') === 'sqlserver' ? 'sqlserver' : 'sqlite';
}

function sql_customer_type_label(int $customerTypeId): string
{
    return company_account_type_by_sql_id($customerTypeId) ?? 'Müşteri';
}

function sql_customer_rows_for_company_list(int $limit = 250, ?int $customerTypeId = null, int $offset = 0, string $query = ''): array
{
    $items = bilnex_customer_reader()->findActiveCustomersPage($limit, $offset, $customerTypeId, $query);

    return array_map(static function (array $row): array {
        return sql_customer_row_to_company_row($row);
    }, $items);
}

function bilnex_contact_crypto_material(): ?array
{
    static $material = null;
    if ($material !== null) {
        return $material ?: null;
    }

    $envKey = trim((string) getenv('BILNEX_CONTACT_AES_KEY_HEX'));
    $envIv = trim((string) getenv('BILNEX_CONTACT_AES_IV_HEX'));
    if ($envKey !== '' && $envIv !== '') {
        $material = ['key_hex' => $envKey, 'iv_hex' => $envIv];
        return $material;
    }

    if (company_source() === 'sqlserver') {
        $material = bilnex_customer_reader()->contactCryptoMaterial() ?: false;
        return $material ?: null;
    }

    $material = false;
    return null;
}

function bilnex_decrypt_contact_value(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '' || !function_exists('openssl_decrypt')) {
        return $value;
    }

    $material = bilnex_contact_crypto_material();
    if (!$material) {
        return $value;
    }

    $key = hex2bin((string) $material['key_hex']);
    $iv = hex2bin((string) $material['iv_hex']);
    if ($key === false || $iv === false) {
        return $value;
    }

    $base64 = strtr($value, '-_', '+/');
    $padding = strlen($base64) % 4;
    if ($padding > 0) {
        $base64 .= str_repeat('=', 4 - $padding);
    }

    $cipherBytes = base64_decode($base64, true);
    if ($cipherBytes === false) {
        return $value;
    }

    $plain = openssl_decrypt($cipherBytes, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return is_string($plain) && trim($plain) !== '' ? trim($plain) : $value;
}

function phone_digits(?string $value): string
{
    return preg_replace('/\D+/', '', (string) $value) ?? '';
}

function valid_display_phone(?string $value): string
{
    $value = trim((string) $value);
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (strlen($digits) < 7 || strlen($digits) > 15) {
        return '';
    }

    return preg_match('/^\+?[0-9\s().-]+$/', $value) ? $value : '';
}

function display_phone(?string $value): string
{
    $phone = valid_display_phone($value);
    if ($phone !== '') {
        return $phone;
    }

    return valid_display_phone(bilnex_decrypt_contact_value($value));
}

function display_tax_no(?string $value): string
{
    $digits = phone_digits($value);
    if (!in_array(strlen($digits), [10, 11], true)) {
        $digits = phone_digits(bilnex_decrypt_contact_value($value));
    }

    return in_array(strlen($digits), [10, 11], true) ? $digits : '';
}

function sql_customer_row_to_company_row(array $row): array
{
    $address = trim(implode(' ', array_filter([
        trim((string) ($row['Address1'] ?? '')),
        trim((string) ($row['Address2'] ?? '')),
    ])));

    return [
        'id' => (int) $row['Id'],
        'sql_customer_id' => (int) $row['Id'],
        'account_code' => $row['Code'] ?? '',
        'name' => $row['Name1'] ?? '',
        'account_type' => sql_customer_type_label((int) ($row['CustomerTypeId'] ?? 0)),
        'contact_person' => $row['Name2'] ?? '',
        'phone' => display_phone($row['Phone'] ?? ''),
        'email' => $row['Email'] ?? '',
        'city' => $row['City'] ?? '',
        'district' => $row['District'] ?? '',
        'address' => $address,
        'tax_no' => display_tax_no($row['TaxNumber'] ?? ''),
        'status' => !empty($row['isActive']) ? 'Aktif' : 'Pasif',
        'responsible_name' => '',
        'next_followup_date' => '',
        'source' => 'SQL Server Customer',
        'created_at' => $row['CreatedDate'] ?? '',
        'updated_at' => $row['CreatedDate'] ?? '',
    ];
}

function sql_customer_lookup_label(array $row): string
{
    $code = trim((string) ($row['account_code'] ?? $row['Code'] ?? ''));
    $name = trim((string) ($row['name'] ?? $row['Name1'] ?? ''));
    $type = trim((string) ($row['account_type'] ?? ''));
    $prefix = $code !== '' ? $code . ' - ' : '';
    return 'SQL #' . (int) ($row['sql_customer_id'] ?? $row['id'] ?? $row['Id'] ?? 0) . ' | ' . $prefix . $name . ($type !== '' ? ' - ' . $type : '');
}

function ensure_local_company_for_sql_customer(int $sqlCustomerId, ?int $userId = null): ?int
{
    if ($sqlCustomerId <= 0) {
        return null;
    }

    $rawCustomer = bilnex_customer_reader()->findById($sqlCustomerId);
    if (!$rawCustomer) {
        return null;
    }

    $customer = sql_customer_row_to_company_row($rawCustomer);
    $name = trim((string) $customer['name']);
    if ($name === '') {
        $name = 'SQL Customer #' . $sqlCustomerId;
    }

    $existingId = (int) scalar('SELECT id FROM companies WHERE sql_customer_id = :sql_customer_id ORDER BY id LIMIT 1', [
        ':sql_customer_id' => $sqlCustomerId,
    ]);
    $data = [
        ':sql_customer_id' => $sqlCustomerId,
        ':name' => $name,
        ':account_type' => normalize_company_account_type($customer['account_type']),
        ':account_code' => trim((string) $customer['account_code']),
        ':contact_person' => trim((string) $customer['contact_person']),
        ':tax_no' => trim((string) $customer['tax_no']),
        ':status' => $customer['status'] === 'Pasif' ? 'Pasif' : 'Aktif',
        ':source' => 'SQL Server Customer',
        ':responsible_user_id' => $userId,
    ];

    if ($existingId > 0) {
        $data[':id'] = $existingId;
        db()->prepare('UPDATE companies SET name = :name, account_type = :account_type, account_code = :account_code, contact_person = :contact_person, tax_no = :tax_no, status = :status, source = :source, responsible_user_id = COALESCE(responsible_user_id, :responsible_user_id), updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute($data);
        return $existingId;
    }

    $data[':created_by'] = $userId;
    db()->prepare('INSERT INTO companies (sql_customer_id, name, account_type, account_code, contact_person, tax_no, status, source, responsible_user_id, created_by) VALUES (:sql_customer_id, :name, :account_type, :account_code, :contact_person, :tax_no, :status, :source, :responsible_user_id, :created_by)')->execute($data);
    return (int) db()->lastInsertId();
}

function company_sql_customer_id(int $companyId): ?int
{
    if ($companyId <= 0) {
        return null;
    }

    $stmt = db()->prepare('SELECT sql_customer_id FROM companies WHERE id = :id');
    $stmt->execute([':id' => $companyId]);
    $value = $stmt->fetchColumn();
    if ($value === null || $value === '') {
        return null;
    }

    return (int) $value;
}

function init_db(): void
{
    $pdo = db();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            full_name TEXT NOT NULL,
            role TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS companies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sql_customer_id INTEGER,
            name TEXT NOT NULL,
            account_code TEXT,
            account_type TEXT NOT NULL DEFAULT 'İş Ortağı',
            contact_person TEXT,
            phone TEXT,
            email TEXT,
            city TEXT,
            district TEXT,
            address TEXT,
            tax_no TEXT,
            balance_amount REAL NOT NULL DEFAULT 0,
            balance_side TEXT,
            status TEXT NOT NULL DEFAULT 'Yeni kayıt',
            source TEXT,
            responsible_user_id INTEGER,
            next_followup_date TEXT,
            description TEXT,
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS interactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL,
            sql_customer_id INTEGER,
            user_id INTEGER,
            interaction_date TEXT NOT NULL,
            type TEXT NOT NULL,
            result TEXT NOT NULL,
            note TEXT,
            next_followup_date TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS opportunities (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL,
            sql_customer_id INTEGER,
            salesperson_id INTEGER,
            product_service TEXT NOT NULL,
            estimated_amount REAL NOT NULL DEFAULT 0,
            stage TEXT NOT NULL DEFAULT 'Yeni fırsat',
            expected_close_date TEXT,
            note TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
            FOREIGN KEY (salesperson_id) REFERENCES users(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER,
            sql_customer_id INTEGER,
            title TEXT NOT NULL,
            description TEXT,
            assigned_by INTEGER,
            assigned_to INTEGER,
            due_date TEXT,
            status TEXT NOT NULL DEFAULT 'Açık',
            completion_note TEXT,
            completed_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
        );
    ");

    $companyColumns = $pdo->query('PRAGMA table_info(companies)')->fetchAll();
    $companyColumnNames = array_map(fn($column) => $column['name'], $companyColumns);
    if (!in_array('account_code', $companyColumnNames, true)) {
        $pdo->exec("ALTER TABLE companies ADD COLUMN account_code TEXT");
    }
    if (!in_array('sql_customer_id', $companyColumnNames, true)) {
        $pdo->exec("ALTER TABLE companies ADD COLUMN sql_customer_id INTEGER");
    }
    if (!in_array('tax_no', $companyColumnNames, true)) {
        $pdo->exec("ALTER TABLE companies ADD COLUMN tax_no TEXT");
    }
    if (!in_array('balance_amount', $companyColumnNames, true)) {
        $pdo->exec("ALTER TABLE companies ADD COLUMN balance_amount REAL NOT NULL DEFAULT 0");
    }
    if (!in_array('balance_side', $companyColumnNames, true)) {
        $pdo->exec("ALTER TABLE companies ADD COLUMN balance_side TEXT");
    }
    if (!in_array('account_type', $companyColumnNames, true)) {
        $pdo->exec("ALTER TABLE companies ADD COLUMN account_type TEXT NOT NULL DEFAULT 'İş Ortağı'");
    }
    $pdo->exec("UPDATE companies SET account_type = 'İş Ortağı' WHERE account_type IS NULL OR account_type = ''");

    $taskColumns = $pdo->query('PRAGMA table_info(tasks)')->fetchAll();
    $taskColumnNames = array_map(fn($column) => $column['name'], $taskColumns);
    if (!in_array('company_id', $taskColumnNames, true)) {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN company_id INTEGER");
    }
    if (!in_array('sql_customer_id', $taskColumnNames, true)) {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN sql_customer_id INTEGER");
    }
    if (!in_array('completion_note', $taskColumnNames, true)) {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN completion_note TEXT");
    }

    $interactionColumns = $pdo->query('PRAGMA table_info(interactions)')->fetchAll();
    $interactionColumnNames = array_map(fn($column) => $column['name'], $interactionColumns);
    if (!in_array('sql_customer_id', $interactionColumnNames, true)) {
        $pdo->exec("ALTER TABLE interactions ADD COLUMN sql_customer_id INTEGER");
    }

    $opportunityColumns = $pdo->query('PRAGMA table_info(opportunities)')->fetchAll();
    $opportunityColumnNames = array_map(fn($column) => $column['name'], $opportunityColumns);
    if (!in_array('sql_customer_id', $opportunityColumnNames, true)) {
        $pdo->exec("ALTER TABLE opportunities ADD COLUMN sql_customer_id INTEGER");
    }

    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count === 0) {
        $admin = app_config('initial_admin');
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role) VALUES (:username, :password_hash, :full_name, :role)');
        $stmt->execute([
            ':username' => $admin['username'],
            ':password_hash' => password_hash($admin['password'], PASSWORD_DEFAULT),
            ':full_name' => $admin['full_name'],
            ':role' => ROLE_ADMIN,
        ]);
    }

    $pdo->exec("UPDATE users SET role = '" . ROLE_CHANNEL_SPECIALIST . "' WHERE role = '" . ROLE_LEGACY_CHANNEL . "'");
    $pdo->exec("UPDATE users SET role = '" . ROLE_FIELD_SALES . "' WHERE role = '" . ROLE_LEGACY_SALES . "'");
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function app_url(string $page = 'dashboard', array $params = []): string
{
    $base = rtrim(app_config('base_url'), '/') . '/index.php';
    $query = array_merge(['page' => $page], $params);
    return $base . '?' . http_build_query($query);
}

function redirect_to(string $page = 'dashboard', array $params = []): void
{
    header('Location: ' . app_url($page, $params));
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('Oturum doğrulama hatası.');
    }
}

function flash(?string $message = null, string $type = 'success'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $user = null;
    if ($user !== null && (int) ($user['id'] ?? 0) === (int) $_SESSION['user_id']) {
        return $user;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = :id AND active = 1');
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    return $user;
}

function require_login(): void
{
    if (!current_user()) {
        redirect_to('login');
    }
}

function normalize_role(?string $role): string
{
    $role = strtolower(trim((string) $role));
    return [
        ROLE_LEGACY_CHANNEL => ROLE_CHANNEL_SPECIALIST,
        ROLE_LEGACY_SALES => ROLE_FIELD_SALES,
        'bayi kanal uzmanı / yöneticisi' => ROLE_CHANNEL_SPECIALIST,
        'satışçı / saha satış' => ROLE_FIELD_SALES,
    ][$role] ?? $role;
}

function role_label(string $role): string
{
    $role = normalize_role($role);
    return [
        ROLE_ADMIN => 'Admin',
        ROLE_MANAGER => 'Yönetici',
        ROLE_CHANNEL_MANAGER => 'Bayi Kanal Yöneticisi',
        ROLE_CHANNEL_SPECIALIST => 'Bayi Kanal Uzmanı',
        ROLE_FIELD_SALES => 'Saha Satış',
    ][$role] ?? $role;
}

function role_options(): array
{
    return [
        ROLE_ADMIN => 'Admin',
        ROLE_MANAGER => 'Yönetici',
        ROLE_CHANNEL_MANAGER => 'Bayi Kanal Yöneticisi',
        ROLE_CHANNEL_SPECIALIST => 'Bayi Kanal Uzmanı',
        ROLE_FIELD_SALES => 'Saha Satış',
    ];
}

function company_statuses(): array
{
    return ['Yeni kayıt', 'Görüşüldü', 'Teklif bekliyor', 'Takipte', 'Aktif bayi', 'Olumsuz', 'Ulaşılamadı'];
}

function company_account_types(): array
{
    return array_values(company_account_type_sql_ids());
}

function company_account_type_sql_ids(): array
{
    return [
        1 => 'Bilnex',
        2 => 'Yönetici',
        3 => 'Satış',
        4 => 'Destek',
        5 => 'Yazılım',
        6 => 'Muhasebe',
        7 => 'İş Ortakları',
        8 => 'Distribütör',
        9 => 'Dağıtıcı',
        10 => 'Bölge Bayisi',
        11 => 'Çözüm Ortağı',
        12 => 'Yetkili Satıcı',
        13 => 'Satış Noktası',
        14 => 'Hedef Bayi',
        15 => 'Müşteriler',
        16 => 'Müşteri',
        17 => 'Hedef Müşteri',
        18 => 'Demo Müşteri',
    ];
}

function company_account_type_by_sql_id(int $customerTypeId): ?string
{
    $types = company_account_type_sql_ids();
    return $types[$customerTypeId] ?? null;
}

function company_account_type_sql_id(?string $value): ?int
{
    $normalized = normalize_company_account_type($value);
    $id = array_search($normalized, company_account_type_sql_ids(), true);
    return $id === false ? null : (int) $id;
}

function normalize_company_account_type(?string $value): string
{
    $value = trim((string) $value);
    if (in_array($value, company_account_types(), true)) {
        return $value;
    }

    $key = strtolower($value);
    $key = strtr($key, [
        'ı' => 'i',
        'İ' => 'i',
        'ş' => 's',
        'Ş' => 's',
        'ğ' => 'g',
        'Ğ' => 'g',
        'ü' => 'u',
        'Ü' => 'u',
        'ö' => 'o',
        'Ö' => 'o',
        'ç' => 'c',
        'Ç' => 'c',
    ]);
    $key = preg_replace('/[^a-z0-9]+/', '', $key) ?? '';

    $aliases = [
        'bilnex' => 'Bilnex',
        'yonetici' => 'Yönetici',
        'satis' => 'Satış',
        'destek' => 'Destek',
        'yazilim' => 'Yazılım',
        'muhasebe' => 'Muhasebe',
        'isortagi' => 'İş Ortakları',
        'isortaklari' => 'İş Ortakları',
        'bayi' => 'İş Ortakları',
        'distributor' => 'Distribütör',
        'dagitici' => 'Dağıtıcı',
        'bolgebayisi' => 'Bölge Bayisi',
        'cozumortagi' => 'Çözüm Ortağı',
        'yetkilisatici' => 'Yetkili Satıcı',
        'satisnoktasi' => 'Satış Noktası',
        'hedefbayi' => 'Hedef Bayi',
        'musteriler' => 'Müşteriler',
        'sonkullanici' => 'Müşteri',
        'sonkullaniciadayi' => 'Hedef Müşteri',
        'musteri' => 'Müşteri',
        'nihaituketici' => 'Müşteri',
        'hedefmusteri' => 'Hedef Müşteri',
        'demomusteri' => 'Demo Müşteri',
    ];

    return $aliases[$key] ?? 'Müşteri';
}

function interaction_types(): array
{
    return ['Telefon', 'WhatsApp', 'E-posta', 'Online toplantı', 'Yüz yüze görüşme', 'Saha ziyareti'];
}

function interaction_results(): array
{
    return ['Olumlu', 'Olumsuz', 'Tekrar aranacak', 'Teklif istiyor', 'Demo istiyor', 'Ulaşılamadı', 'Kararsız'];
}

function opportunity_stages(): array
{
    return ['Yeni fırsat', 'Görüşme yapılıyor', 'Teklif verildi', 'Sözleşme bekleniyor', 'Kazanıldı', 'Kaybedildi'];
}

function task_statuses(): array
{
    return ['Açık', 'Tamamlandı'];
}

function can_manage_users(): bool
{
    return normalize_role(current_user()['role'] ?? null) === ROLE_ADMIN;
}

function can_view_all(): bool
{
    $role = normalize_role(current_user()['role'] ?? null);
    return in_array($role, [ROLE_ADMIN, ROLE_MANAGER, ROLE_CHANNEL_MANAGER], true);
}

function task_visible_roles_for_current_user(): ?array
{
    $role = normalize_role(current_user()['role'] ?? null);
    if ($role === ROLE_ADMIN) {
        return null;
    }
    if ($role === ROLE_MANAGER) {
        return [ROLE_CHANNEL_MANAGER, ROLE_CHANNEL_SPECIALIST, ROLE_FIELD_SALES];
    }
    if ($role === ROLE_CHANNEL_MANAGER) {
        return [ROLE_CHANNEL_SPECIALIST, ROLE_FIELD_SALES];
    }
    return [];
}

function role_database_values(array $roles): array
{
    $values = $roles;
    if (in_array(ROLE_CHANNEL_SPECIALIST, $roles, true)) {
        $values[] = ROLE_LEGACY_CHANNEL;
    }
    if (in_array(ROLE_FIELD_SALES, $roles, true)) {
        $values[] = ROLE_LEGACY_SALES;
    }
    return array_values(array_unique($values));
}

function task_visibility_condition(string $alias = 't'): array
{
    $roles = task_visible_roles_for_current_user();
    if ($roles === null) {
        return ['', []];
    }

    $userId = (int) (current_user()['id'] ?? 0);
    if ($userId <= 0) {
        return [' AND 1 = 0', []];
    }

    $prefix = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 't';
    $assignedByParam = ':task_scope_assigned_by_' . $prefix;
    $assignedToParam = ':task_scope_assigned_to_' . $prefix;
    $params = [
        $assignedByParam => $userId,
        $assignedToParam => $userId,
    ];
    $conditions = [
        "{$alias}.assigned_by = {$assignedByParam}",
        "{$alias}.assigned_to = {$assignedToParam}",
    ];

    $roleValues = role_database_values($roles);
    if ($roleValues) {
        $placeholders = [];
        foreach ($roleValues as $index => $roleValue) {
            $param = ':task_scope_role_' . $prefix . '_' . $index;
            $placeholders[] = $param;
            $params[$param] = $roleValue;
        }
        $conditions[] = "EXISTS (SELECT 1 FROM users task_scope_user WHERE task_scope_user.id IN ({$alias}.assigned_by, {$alias}.assigned_to) AND task_scope_user.role IN (" . implode(', ', $placeholders) . '))';
    }

    return [' AND (' . implode(' OR ', $conditions) . ')', $params];
}

function can_complete_task(array $task): bool
{
    $userId = (int) (current_user()['id'] ?? 0);
    return normalize_role(current_user()['role'] ?? null) === ROLE_ADMIN
        || (int) ($task['assigned_to'] ?? 0) === $userId;
}

function active_users(): array
{
    return db()->query('SELECT id, full_name, username, role FROM users WHERE active = 1 ORDER BY full_name')->fetchAll();
}

function user_can_access_company(int $companyId): bool
{
    $stmt = db()->prepare('SELECT responsible_user_id, created_by FROM companies WHERE id = :id');
    $stmt->execute([':id' => $companyId]);
    $company = $stmt->fetch();
    if (!$company) {
        return false;
    }
    if (can_view_all()) {
        return true;
    }

    $userId = (int) (current_user()['id'] ?? 0);
    return $userId > 0
        && (
            (int) ($company['responsible_user_id'] ?? 0) === $userId
            || (int) ($company['created_by'] ?? 0) === $userId
        );
}

function owned_company_condition(string $alias = 'c'): array
{
    if (can_view_all()) {
        return ['', []];
    }

    $userId = (int) (current_user()['id'] ?? 0);
    if ($userId <= 0) {
        return [' AND 1 = 0', []];
    }

    $prefix = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 'c';
    $param = ':scope_user_id_' . $prefix;

    return [
        " AND ({$alias}.responsible_user_id = {$param} OR {$alias}.created_by = {$param})",
        [$param => $userId],
    ];
}

function selected($actual, $expected): string
{
    return (string) $actual === (string) $expected ? ' selected' : '';
}

function checked($actual): string
{
    return $actual ? ' checked' : '';
}

function date_filter_range(array $input): array
{
    $filter = $input['date_filter'] ?? '';
    $today = new DateTimeImmutable('today');
    if ($filter === 'today') {
        return [$today->format('Y-m-d'), $today->format('Y-m-d')];
    }
    if ($filter === 'week') {
        $start = $today->modify('monday this week');
        return [$start->format('Y-m-d'), $start->modify('+6 days')->format('Y-m-d')];
    }
    if ($filter === 'month') {
        return [$today->format('Y-m-01'), $today->format('Y-m-t')];
    }
    if ($filter === 'custom') {
        return [$input['date_from'] ?? '', $input['date_to'] ?? ''];
    }
    return ['', ''];
}

function apply_date_filter(string &$where, array &$params, string $column, array $input): void
{
    [$from, $to] = date_filter_range($input);
    if ($from !== '') {
        $where .= " AND date({$column}) >= :date_from";
        $params[':date_from'] = $from;
    }
    if ($to !== '') {
        $where .= " AND date({$column}) <= :date_to";
        $params[':date_to'] = $to;
    }
}

function require_company_access(int $companyId): void
{
    if (!user_can_access_company($companyId)) {
        http_response_code(403);
        exit('Bu kayda erişim yetkiniz yok.');
    }
}

init_db();
