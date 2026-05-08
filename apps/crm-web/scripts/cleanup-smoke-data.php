<?php

require __DIR__ . '/../app/bootstrap.php';

db()->exec("DELETE FROM companies WHERE source = 'Smoke test'");

echo 'test_companies=' . db()->query("SELECT COUNT(*) FROM companies WHERE source = 'Test verisi'")->fetchColumn() . PHP_EOL;
echo 'smoke_companies=' . db()->query("SELECT COUNT(*) FROM companies WHERE source = 'Smoke test'")->fetchColumn() . PHP_EOL;
