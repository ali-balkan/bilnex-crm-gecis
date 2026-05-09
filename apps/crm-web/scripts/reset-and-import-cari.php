<?php

require __DIR__ . '/../app/bootstrap.php';

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Kullanim: php scripts/reset-and-import-cari.php \"C:\\path\\Cari.xlsx\"\n");
    exit(1);
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "PHP zip eklentisi aktif degil.\n");
    exit(1);
}

function import_xlsx_cell_column(string $reference): int
{
    preg_match('/^[A-Z]+/i', $reference, $match);
    $letters = strtoupper($match[0] ?? 'A');
    $number = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $number = ($number * 26) + (ord($letters[$i]) - 64);
    }
    return max(0, $number - 1);
}

function import_xlsx_text_nodes(SimpleXMLElement $node): string
{
    $parts = [];
    foreach ($node->xpath('.//*[local-name()="t"]') ?: [] as $text) {
        $parts[] = (string) $text;
    }
    return implode('', $parts);
}

function read_xlsx_rows_for_reset(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Excel dosyasi acilamadi.');
    }

    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sharedRoot = simplexml_load_string($sharedXml);
        if ($sharedRoot instanceof SimpleXMLElement) {
            foreach ($sharedRoot->xpath('//*[local-name()="si"]') ?: [] as $item) {
                $shared[] = import_xlsx_text_nodes($item);
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('Excel dosyasinda ilk sayfa bulunamadi.');
    }

    $sheet = simplexml_load_string($sheetXml);
    if (!$sheet instanceof SimpleXMLElement) {
        throw new RuntimeException('Excel sayfasi okunamadi.');
    }

    $rows = [];
    foreach ($sheet->xpath('//*[local-name()="row"]') ?: [] as $rowNode) {
        $row = [];
        foreach ($rowNode->xpath('./*[local-name()="c"]') ?: [] as $cell) {
            $attrs = $cell->attributes();
            $index = import_xlsx_cell_column((string) ($attrs['r'] ?? 'A'));
            $type = (string) ($attrs['t'] ?? '');
            $valueNode = $cell->xpath('./*[local-name()="v"]')[0] ?? null;
            $value = $valueNode !== null ? (string) $valueNode : '';
            if ($type === 's') {
                $value = $shared[(int) $value] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = import_xlsx_text_nodes($cell);
            }
            $row[$index] = trim($value);
        }
        if ($row) {
            ksort($row);
            $rows[] = $row;
        }
    }

    return $rows;
}

function normalize_header_for_reset(string $value): string
{
    $value = trim($value);
    $value = strtr($value, [
        'Ç' => 'c', 'Ğ' => 'g', 'İ' => 'i', 'I' => 'i', 'Ö' => 'o', 'Ş' => 's', 'Ü' => 'u',
        'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'i' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
    ]);
    $value = strtolower($value);
    return preg_replace('/[^a-z0-9]+/', '', $value) ?? $value;
}

function account_type_from_code(string $code): string
{
    $code = strtoupper($code);
    return (str_contains($code, 'M') || str_contains($code, 'W')) ? 'Son Kullanıcı' : 'İş Ortağı';
}

function numeric_balance($value): float
{
    $value = trim((string) $value);
    if ($value === '') {
        return 0.0;
    }
    $value = str_replace([' ', ','], ['', '.'], $value);
    return is_numeric($value) ? (float) $value : 0.0;
}

$rows = read_xlsx_rows_for_reset($path);
if (count($rows) < 2) {
    throw new RuntimeException('Excel dosyasinda aktarilacak satir bulunamadi.');
}

$headers = array_values($rows[0]);
$indexes = [];
foreach ($headers as $index => $header) {
    $indexes[normalize_header_for_reset((string) $header)] = $index;
}

$required = ['kodu', 'adi'];
foreach ($required as $header) {
    if (!array_key_exists($header, $indexes)) {
        throw new RuntimeException('Zorunlu baslik eksik: ' . $header);
    }
}

$pdo = db();
$superadmin = $pdo->query("SELECT id FROM users WHERE username = 'superadmin' ORDER BY id LIMIT 1")->fetchColumn();
if (!$superadmin) {
    $admin = app_config('initial_admin');
    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, active) VALUES (:username, :password_hash, :full_name, :role, 1)');
    $stmt->execute([
        ':username' => 'superadmin',
        ':password_hash' => password_hash($admin['password'], PASSWORD_DEFAULT),
        ':full_name' => 'Super Admin',
        ':role' => ROLE_ADMIN,
    ]);
    $superadmin = (int) $pdo->lastInsertId();
}
$superadmin = (int) $superadmin;

$insert = $pdo->prepare('
    INSERT INTO companies
        (name, account_type, account_code, contact_person, phone, email, city, district, address, tax_no, balance_amount, balance_side, status, source, responsible_user_id, next_followup_date, description, created_by)
    VALUES
        (:name, :account_type, :account_code, :contact_person, :phone, :email, :city, :district, :address, :tax_no, :balance_amount, :balance_side, :status, :source, :responsible_user_id, NULL, :description, :created_by)
');

$protectedRecords = [
    'takip' => (int) $pdo->query('SELECT COUNT(*) FROM tasks')->fetchColumn(),
    'gorusme' => (int) $pdo->query('SELECT COUNT(*) FROM interactions')->fetchColumn(),
    'satis_firsati' => (int) $pdo->query('SELECT COUNT(*) FROM opportunities')->fetchColumn(),
];
$protectedTotal = array_sum($protectedRecords);
if ($protectedTotal > 0) {
    throw new RuntimeException('Takip, gorusme veya satis firsati kaydi varken reset/import calistirilamaz. Bu kayitlar silinmeyecek sekilde korunur.');
}

$pdo->beginTransaction();
try {
    $pdo->exec('DELETE FROM companies');
    $deleteUsers = $pdo->prepare('DELETE FROM users WHERE id <> :superadmin_id');
    $deleteUsers->execute([':superadmin_id' => $superadmin]);
    $activateAdmin = $pdo->prepare("UPDATE users SET username = 'superadmin', full_name = 'Super Admin', role = :role, active = 1 WHERE id = :id");
    $activateAdmin->execute([':role' => ROLE_ADMIN, ':id' => $superadmin]);

    $imported = 0;
    $skipped = 0;
    foreach (array_slice($rows, 1) as $row) {
        $code = trim((string) ($row[$indexes['kodu']] ?? ''));
        $name = trim((string) ($row[$indexes['adi']] ?? ''));
        if ($name === '') {
            $skipped++;
            continue;
        }

        $cinsi = trim((string) ($row[$indexes['cinsi'] ?? -1] ?? ''));
        $insert->execute([
            ':name' => $name,
            ':account_type' => account_type_from_code($code),
            ':account_code' => $code,
            ':contact_person' => '',
            ':phone' => trim((string) ($row[$indexes['telefon1'] ?? -1] ?? '')),
            ':email' => '',
            ':city' => '',
            ':district' => '',
            ':address' => trim((string) ($row[$indexes['adres1'] ?? -1] ?? '')),
            ':tax_no' => trim((string) ($row[$indexes['vergino'] ?? -1] ?? '')),
            ':balance_amount' => numeric_balance($row[$indexes['bakiye'] ?? -1] ?? 0),
            ':balance_side' => trim((string) ($row[$indexes['ba'] ?? -1] ?? '')),
            ':status' => 'Yeni kayıt',
            ':source' => 'Bilnex cari aktarımı 06.05.2026',
            ':responsible_user_id' => $superadmin,
            ':description' => $cinsi !== '' ? 'Cinsi: ' . $cinsi : '',
            ':created_by' => $superadmin,
        ]);
        $imported++;
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

$counts = [
    'users' => $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'companies' => $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn(),
    'interactions' => $pdo->query('SELECT COUNT(*) FROM interactions')->fetchColumn(),
    'opportunities' => $pdo->query('SELECT COUNT(*) FROM opportunities')->fetchColumn(),
    'is_ortagi' => $pdo->query("SELECT COUNT(*) FROM companies WHERE account_type = 'İş Ortağı'")->fetchColumn(),
    'son_kullanici' => $pdo->query("SELECT COUNT(*) FROM companies WHERE account_type = 'Son Kullanıcı'")->fetchColumn(),
];

echo "Aktarim tamamlandi.\n";
echo "Import edilen: {$imported}\n";
echo "Atlanan: {$skipped}\n";
foreach ($counts as $key => $value) {
    echo $key . '=' . $value . "\n";
}
