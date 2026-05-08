<?php

require __DIR__ . '/../app/bootstrap.php';

ReadOnlySqlServerConnection::assertReadOnlySql('SELECT TOP 1 * FROM dbo.Customer');
ReadOnlySqlServerConnection::assertReadOnlySql('WITH customers AS (SELECT Id FROM dbo.Customer) SELECT * FROM customers');

$blocked = [
    'INSERT INTO dbo.Customer (Name1) VALUES (N_Test)',
    'UPDATE dbo.Customer SET Name1 = N_Test',
    'DELETE FROM dbo.Customer WHERE Id = 1',
    'ALTER TABLE dbo.Customer ADD CrmTest int NULL',
    'SELECT * INTO dbo.CustomerBackup FROM dbo.Customer',
];

foreach ($blocked as $sql) {
    try {
        ReadOnlySqlServerConnection::assertReadOnlySql($sql);
        throw new RuntimeException('Yazma sorgusu engellenmedi: ' . $sql);
    } catch (InvalidArgumentException) {
        // Expected.
    }
}

echo "SQL Server read-only katman testi tamamlandi.\n";
