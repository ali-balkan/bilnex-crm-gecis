<?php

require __DIR__ . '/../app/bootstrap.php';

$pdo = db();
$prefix = 'RoleVis';
$source = 'Role visibility test';

function rv_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
    echo '[OK] ' . $message . PHP_EOL;
}

function rv_cleanup(PDO $pdo, string $prefix, string $source): void
{
    $pdo->prepare('DELETE FROM tasks WHERE title LIKE :prefix')->execute([':prefix' => $prefix . ' %']);
    $pdo->prepare('DELETE FROM opportunities WHERE product_service LIKE :prefix')->execute([':prefix' => $prefix . ' %']);
    $pdo->prepare('DELETE FROM companies WHERE source = :source')->execute([':source' => $source]);
    $pdo->prepare("DELETE FROM users WHERE username LIKE 'rolevis_%'")->execute();
}

function rv_rows(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function rv_visible_task_titles(string $prefix): array
{
    [$scopeSql, $scopeParams] = task_visibility_condition('t');
    $rows = rv_rows(
        "SELECT t.title FROM tasks t WHERE t.title LIKE :prefix{$scopeSql} ORDER BY t.title",
        $scopeParams + [':prefix' => $prefix . ' %']
    );
    return array_column($rows, 'title');
}

function rv_visible_opportunity_products(string $prefix): array
{
    [$scopeSql, $scopeParams] = opportunity_visibility_condition('o');
    $rows = rv_rows(
        "SELECT o.product_service FROM opportunities o WHERE o.product_service LIKE :prefix{$scopeSql} ORDER BY o.product_service",
        $scopeParams + [':prefix' => $prefix . ' %']
    );
    return array_column($rows, 'product_service');
}

function rv_set_user(int $userId): void
{
    $_SESSION['user_id'] = $userId;
}

$users = [
    'admin' => ['rolevis_admin', 'RoleVis Admin', ROLE_ADMIN],
    'manager' => ['rolevis_manager', 'RoleVis Yonetici', ROLE_MANAGER],
    'channel_manager' => ['rolevis_channel_manager', 'RoleVis Kanal Yoneticisi', ROLE_CHANNEL_MANAGER],
    'specialist' => ['rolevis_specialist', 'RoleVis Kanal Uzmani', ROLE_CHANNEL_SPECIALIST],
    'sales' => ['rolevis_sales', 'RoleVis Saha Satis', ROLE_FIELD_SALES],
];

rv_cleanup($pdo, $prefix, $source);

try {
    $pdo->beginTransaction();

    $userIds = [];
    $insertUser = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, active) VALUES (:username, :password_hash, :full_name, :role, 1)');
    foreach ($users as $key => [$username, $fullName, $role]) {
        $insertUser->execute([
            ':username' => $username,
            ':password_hash' => password_hash('RoleVis123!', PASSWORD_DEFAULT),
            ':full_name' => $fullName,
            ':role' => $role,
        ]);
        $userIds[$key] = (int) $pdo->lastInsertId();
    }

    $companyIds = [];
    $insertCompany = $pdo->prepare('
        INSERT INTO companies (name, account_type, status, source, responsible_user_id, created_by)
        VALUES (:name, :account_type, :status, :source, :responsible_user_id, :created_by)
    ');
    foreach ($userIds as $key => $userId) {
        $insertCompany->execute([
            ':name' => $prefix . ' Company ' . $key,
            ':account_type' => 'Hedef Bayi',
            ':status' => 'Yeni kayıt',
            ':source' => $source,
            ':responsible_user_id' => $userId,
            ':created_by' => $userId,
        ]);
        $companyIds[$key] = (int) $pdo->lastInsertId();
    }

    $insertTask = $pdo->prepare('
        INSERT INTO tasks (company_id, title, description, assigned_by, assigned_to, due_date, status)
        VALUES (:company_id, :title, :description, :assigned_by, :assigned_to, :due_date, :status)
    ');

    foreach ($userIds as $key => $userId) {
        $insertTask->execute([
            ':company_id' => $companyIds[$key],
            ':title' => $prefix . ' TASK sentinel ' . $key,
            ':description' => 'Sentinel role visibility task',
            ':assigned_by' => $userId,
            ':assigned_to' => $userId,
            ':due_date' => date('Y-m-d', strtotime('+1 day')),
            ':status' => 'Açık',
        ]);
    }

    $assignments = [
        'admin' => ['manager', 'channel_manager', 'specialist'],
        'manager' => ['channel_manager', 'specialist', 'sales'],
        'channel_manager' => ['specialist', 'sales', 'manager'],
        'specialist' => ['sales', 'channel_manager', 'admin'],
        'sales' => ['specialist', 'manager', 'admin'],
    ];
    foreach ($assignments as $from => $targets) {
        foreach ($targets as $index => $to) {
            $insertTask->execute([
                ':company_id' => $companyIds[$from],
                ':title' => sprintf('%s TASK %s to %s #%d', $prefix, $from, $to, $index + 1),
                ':description' => 'Generated role visibility assignment',
                ':assigned_by' => $userIds[$from],
                ':assigned_to' => $userIds[$to],
                ':due_date' => date('Y-m-d', strtotime('+' . ($index + 2) . ' days')),
                ':status' => 'Açık',
            ]);
        }
    }

    $insertOpportunity = $pdo->prepare('
        INSERT INTO opportunities (company_id, salesperson_id, product_service, estimated_amount, stage, expected_close_date, note)
        VALUES (:company_id, :salesperson_id, :product_service, :estimated_amount, :stage, :expected_close_date, :note)
    ');
    foreach ($userIds as $key => $userId) {
        for ($i = 1; $i <= 3; $i++) {
            $insertOpportunity->execute([
                ':company_id' => $companyIds[$key],
                ':salesperson_id' => $userId,
                ':product_service' => sprintf('%s OPP %s #%d', $prefix, $key, $i),
                ':estimated_amount' => 10000 + ($i * 1000),
                ':stage' => $i === 3 ? 'Teklif verildi' : 'Yeni fırsat',
                ':expected_close_date' => date('Y-m-d', strtotime('+' . ($i * 5) . ' days')),
                ':note' => 'Generated role visibility opportunity',
            ]);
        }
    }

    $pdo->commit();

    $allTaskCount = (int) rv_rows('SELECT COUNT(*) total FROM tasks WHERE title LIKE :prefix', [':prefix' => $prefix . ' %'])[0]['total'];
    $allOppCount = (int) rv_rows('SELECT COUNT(*) total FROM opportunities WHERE product_service LIKE :prefix', [':prefix' => $prefix . ' %'])[0]['total'];

    rv_set_user($userIds['admin']);
    $adminTasks = rv_visible_task_titles($prefix);
    $adminOpps = rv_visible_opportunity_products($prefix);
    rv_assert(count($adminTasks) === $allTaskCount, 'Admin sees all task records');
    rv_assert(count($adminOpps) === $allOppCount, 'Admin sees all opportunity records');

    rv_set_user($userIds['manager']);
    $managerTasks = rv_visible_task_titles($prefix);
    $managerOpps = rv_visible_opportunity_products($prefix);
    rv_assert(!in_array($prefix . ' TASK sentinel admin', $managerTasks, true), 'Yonetici cannot see admin-only task');
    rv_assert(in_array($prefix . ' TASK sentinel manager', $managerTasks, true), 'Yonetici sees own task');
    rv_assert(!in_array($prefix . ' OPP admin #1', $managerOpps, true), 'Yonetici cannot see admin opportunity');
    rv_assert(in_array($prefix . ' OPP channel_manager #1', $managerOpps, true), 'Yonetici sees channel manager opportunity');
    rv_assert(in_array($prefix . ' OPP specialist #1', $managerOpps, true), 'Yonetici sees specialist opportunity');
    rv_assert(in_array($prefix . ' OPP sales #1', $managerOpps, true), 'Yonetici sees sales opportunity');

    rv_set_user($userIds['channel_manager']);
    $channelTasks = rv_visible_task_titles($prefix);
    $channelOpps = rv_visible_opportunity_products($prefix);
    rv_assert(!in_array($prefix . ' TASK sentinel manager', $channelTasks, true), 'Bayi Kanal Yoneticisi cannot see manager-only task');
    rv_assert(in_array($prefix . ' TASK sentinel channel_manager', $channelTasks, true), 'Bayi Kanal Yoneticisi sees own task');
    rv_assert(!in_array($prefix . ' OPP manager #1', $channelOpps, true), 'Bayi Kanal Yoneticisi cannot see manager opportunity');
    rv_assert(in_array($prefix . ' OPP specialist #1', $channelOpps, true), 'Bayi Kanal Yoneticisi sees specialist opportunity');
    rv_assert(in_array($prefix . ' OPP sales #1', $channelOpps, true), 'Bayi Kanal Yoneticisi sees sales opportunity');

    rv_set_user($userIds['specialist']);
    $specialistTasks = rv_visible_task_titles($prefix);
    $specialistOpps = rv_visible_opportunity_products($prefix);
    rv_assert(in_array($prefix . ' TASK sentinel specialist', $specialistTasks, true), 'Bayi Kanal Uzmani sees own task');
    rv_assert(!in_array($prefix . ' TASK sentinel sales', $specialistTasks, true), 'Bayi Kanal Uzmani cannot see sales-only task');
    rv_assert(count($specialistOpps) === 3 && in_array($prefix . ' OPP specialist #1', $specialistOpps, true), 'Bayi Kanal Uzmani sees only own opportunities');

    rv_set_user($userIds['sales']);
    $salesTasks = rv_visible_task_titles($prefix);
    $salesOpps = rv_visible_opportunity_products($prefix);
    rv_assert(in_array($prefix . ' TASK sentinel sales', $salesTasks, true), 'Saha Satis sees own task');
    rv_assert(!in_array($prefix . ' TASK sentinel specialist', $salesTasks, true), 'Saha Satis cannot see specialist-only task');
    rv_assert(count($salesOpps) === 3 && in_array($prefix . ' OPP sales #1', $salesOpps, true), 'Saha Satis sees only own opportunities');

    echo 'Role visibility test completed.' . PHP_EOL;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    rv_cleanup($pdo, $prefix, $source);
}
