<?php

require __DIR__ . '/../app/bootstrap.php';

$ids = db()->query("SELECT id FROM companies WHERE source = 'Smoke test'")->fetchAll(PDO::FETCH_COLUMN);
if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    db()->prepare("DELETE FROM interactions WHERE company_id IN ({$placeholders})")->execute($ids);
    db()->prepare("DELETE FROM opportunities WHERE company_id IN ({$placeholders})")->execute($ids);
    db()->prepare("DELETE FROM tasks WHERE company_id IN ({$placeholders})")->execute($ids);
}
db()->exec("DELETE FROM companies WHERE source = 'Smoke test'");

echo 'test_companies=' . db()->query("SELECT COUNT(*) FROM companies WHERE source = 'Test verisi'")->fetchColumn() . PHP_EOL;
echo 'smoke_companies=' . db()->query("SELECT COUNT(*) FROM companies WHERE source = 'Smoke test'")->fetchColumn() . PHP_EOL;
