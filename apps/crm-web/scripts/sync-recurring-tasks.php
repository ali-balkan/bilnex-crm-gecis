<?php

require __DIR__ . '/../app/bootstrap.php';

init_db();
sync_goal_occurrences($argv[1] ?? null);

echo "Düzenli görev atama tekrarları senkronize edildi.\n";
