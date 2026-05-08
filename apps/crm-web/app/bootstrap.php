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
const ROLE_CHANNEL = 'bayi_kanal';
const ROLE_SALES = 'satis';

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
        return [
            'id' => (int) $row['Id'],
            'account_code' => $row['Code'] ?? '',
            'name' => $row['Name1'] ?? '',
            'account_type' => sql_customer_type_label((int) ($row['CustomerTypeId'] ?? 0)),
            'contact_person' => $row['Name2'] ?? '',
            'phone' => '',
            'email' => '',
            'city' => '',
            'district' => '',
            'address' => '',
            'status' => !empty($row['isActive']) ? 'Aktif' : 'Pasif',
            'responsible_name' => '',
            'next_followup_date' => '',
            'source' => 'SQL Server Customer',
            'created_at' => $row['CreatedDate'] ?? '',
            'updated_at' => $row['CreatedDate'] ?? '',
        ];
    }, $items);
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
    return strtolower(trim((string) $role));
}

function role_label(string $role): string
{
    $role = normalize_role($role);
    return [
        ROLE_ADMIN => 'Admin',
        ROLE_MANAGER => 'Yönetici',
        ROLE_CHANNEL => 'Bayi Kanal Uzmanı / Yöneticisi',
        ROLE_SALES => 'Satışçı / Saha Satış',
    ][$role] ?? $role;
}

function role_options(): array
{
    return [
        ROLE_ADMIN => 'Admin',
        ROLE_MANAGER => 'Yönetici',
        ROLE_CHANNEL => 'Bayi Kanal Uzmanı / Yöneticisi',
        ROLE_SALES => 'Satışçı / Saha Satış',
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
    return in_array($role, [ROLE_ADMIN, ROLE_MANAGER], true);
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
