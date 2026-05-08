<?php

$isLocalHost = isset($_SERVER['HTTP_HOST']) && preg_match('/^(127\.0\.0\.1|localhost)(:\d+)?$/', $_SERVER['HTTP_HOST']);
$baseUrl = getenv('CRM_BASE_URL');
if ($baseUrl === false) {
    $baseUrl = $isLocalHost ? '' : '/bilnexcrm';
}

$sqlServerLocalConfig = __DIR__ . '/data/sqlserver.local.php';
$sqlServerLocal = is_file($sqlServerLocalConfig) ? require $sqlServerLocalConfig : [];

return [
    'app_name' => 'Bilnex İş Ortakları CRM',
    'base_url' => $baseUrl,
    'timezone' => 'Europe/Istanbul',
    'db_path' => __DIR__ . '/data/crm.sqlite',
    'company_source' => getenv('CRM_COMPANY_SOURCE') ?: 'sqlserver',
    'sql_server' => [
        'server' => getenv('BILNEX_SQL_SERVER') ?: ($sqlServerLocal['server'] ?? 'localhost\\BILNEXSQLCRM'),
        'database' => getenv('BILNEX_SQL_DATABASE') ?: ($sqlServerLocal['database'] ?? 'BILNEX_CRMDB'),
        'username' => getenv('BILNEX_SQL_USERNAME') ?: ($sqlServerLocal['username'] ?? ''),
        'password' => getenv('BILNEX_SQL_PASSWORD') ?: ($sqlServerLocal['password'] ?? ''),
        'trust_server_certificate' => (getenv('BILNEX_SQL_TRUST_CERTIFICATE') ?: '1') === '1',
        'dotnet_bridge' => (getenv('BILNEX_SQL_DOTNET_BRIDGE') ?: '1') === '1',
        'read_only' => true,
    ],
    'initial_admin' => [
        'username' => 'superadmin',
        'password' => 'BlnxCRM!2026',
        'full_name' => 'Super Admin',
    ],
];
