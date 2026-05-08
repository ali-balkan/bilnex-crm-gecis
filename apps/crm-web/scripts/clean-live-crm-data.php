<?php

declare(strict_types=1);

$dbPath = $argv[1] ?? null;
if ($dbPath === null || $dbPath === '') {
    fwrite(STDERR, "Usage: php clean-live-crm-data.php <sqlite-db-path>\n");
    exit(1);
}

if (!is_file($dbPath)) {
    fwrite(STDERR, "Database not found: {$dbPath}\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');

$tables = ['tasks', 'opportunities', 'interactions', 'companies'];
$before = [];
$after = [];

foreach (array_merge(['users'], $tables) as $table) {
    $before[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
}

$pdo->beginTransaction();
try {
    foreach ($tables as $table) {
        $pdo->exec("DELETE FROM {$table}");
    }

    $sequenceNames = implode(',', array_map(static fn($table) => $pdo->quote($table), $tables));
    $pdo->exec("DELETE FROM sqlite_sequence WHERE name IN ({$sequenceNames})");

    $pdo->commit();
} catch (Throwable $exception) {
    $pdo->rollBack();
    throw $exception;
}

foreach (array_merge(['users'], $tables) as $table) {
    $after[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
}

echo json_encode([
    'database' => realpath($dbPath),
    'before' => $before,
    'after' => $after,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
