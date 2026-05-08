<?php

require __DIR__ . '/../app/bootstrap.php';

if ((getenv('BILNEX_SQL_WRITE_TEST') ?: '') !== 'rollback') {
    throw new RuntimeException('Kalici yazmayi onlemek icin BILNEX_SQL_WRITE_TEST=rollback gereklidir.');
}

$script = dirname(__DIR__, 3) . '/packages/database/scripts/sqlserver-customer-address-write-rollback-test.ps1';
if (!is_file($script)) {
    throw new RuntimeException('Rollback yazma test scripti bulunamadi.');
}

$command = [
    'powershell.exe',
    '-NoProfile',
    '-ExecutionPolicy',
    'Bypass',
    '-File',
    $script,
];

$process = proc_open($command, [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes);

if (!is_resource($process)) {
    throw new RuntimeException('Rollback yazma testi baslatilamadi.');
}

$output = stream_get_contents($pipes[1]);
$error = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

if ($exitCode !== 0) {
    throw new RuntimeException('Rollback yazma testi hatasi: ' . trim($error));
}

$result = json_decode($output, true);
if (($result['status'] ?? '') !== 'ok') {
    throw new RuntimeException('Rollback yazma testi beklenen sonucu dondurmedi.');
}

echo 'Customer + Address rollback yazma testi tamamlandi. ';
echo 'Transaction icinde dogrulandi, rollback sonrasi Customer=';
echo $result['after_rollback_customer_count'];
echo ' Address=';
echo $result['after_rollback_address_count'];
echo PHP_EOL;
