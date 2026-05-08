<?php

require __DIR__ . '/../app/bootstrap.php';

if (company_source() !== 'sqlserver') {
    throw new RuntimeException('Bu test icin CRM_COMPANY_SOURCE=sqlserver olmalidir.');
}

$rows = sql_customer_rows_for_company_list(5);
if (count($rows) === 0) {
    throw new RuntimeException('SQL Server Customer kaynagindan kayit okunamadi.');
}

foreach ($rows as $row) {
    foreach (['id', 'account_code', 'name', 'account_type', 'phone', 'city', 'district', 'status', 'source'] as $key) {
        if (!array_key_exists($key, $row)) {
            throw new RuntimeException("Bayi/Firma satirinda eksik alan: $key");
        }
    }
    if ($row['source'] !== 'SQL Server Customer') {
        throw new RuntimeException('Bayi/Firma satiri SQL Server Customer kaynagi olarak isaretlenmedi.');
    }
}

echo 'Bayi/Firma SQL Server Customer liste testi tamamlandi. Okunan kayit: ' . count($rows) . PHP_EOL;
