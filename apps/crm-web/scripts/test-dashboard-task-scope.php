<?php

require __DIR__ . '/../app/bootstrap.php';

init_db();

function dts_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
    echo '[OK] ' . $message . PHP_EOL;
}

function dts_set_user(int $userId): void
{
    $_SESSION['user_id'] = $userId;
}

function dts_overdue_breakdown(int $viewerId, string $today): array
{
    dts_set_user($viewerId);
    [$scopeSql, $scopeParams] = task_visibility_condition('t');
    return task_scope_breakdown(
        "t.status = 'Açık'{$scopeSql} AND t.due_date IS NOT NULL AND date(t.due_date) < :today",
        $scopeParams + [':today' => $today],
        $viewerId
    );
}

$prefix = 'DashboardTaskScope';
$userPrefix = 'dashboard_task_scope_';
$today = '2026-05-11';
$yesterday = '2026-05-10';

db()->beginTransaction();
try {
    $insertUser = db()->prepare('
        INSERT INTO users (username, password_hash, full_name, role, active)
        VALUES (:username, :password_hash, :full_name, :role, 1)
    ');
    $users = [
        'admin' => [$userPrefix . 'admin', 'Dashboard Scope Admin', ROLE_ADMIN],
        'manager' => [$userPrefix . 'manager', 'Dashboard Scope Manager', ROLE_MANAGER],
        'channel' => [$userPrefix . 'channel', 'Dashboard Scope Channel', ROLE_CHANNEL_MANAGER],
        'specialist' => [$userPrefix . 'specialist', 'Dashboard Scope Specialist', ROLE_CHANNEL_SPECIALIST],
        'sales' => [$userPrefix . 'sales', 'Dashboard Scope Sales', ROLE_FIELD_SALES],
    ];
    $userIds = [];
    foreach ($users as $key => [$username, $fullName, $role]) {
        $insertUser->execute([
            ':username' => $username,
            ':password_hash' => password_hash('DashboardScope123!', PASSWORD_DEFAULT),
            ':full_name' => $fullName,
            ':role' => $role,
        ]);
        $userIds[$key] = (int) db()->lastInsertId();
    }

    $insertTask = db()->prepare('
        INSERT INTO tasks (company_id, sql_customer_id, title, description, assigned_by, assigned_to, due_date, status)
        VALUES (NULL, NULL, :title, :description, :assigned_by, :assigned_to, :due_date, :status)
    ');
    $tasks = [
        'admin_self' => ['Admin self overdue', 'admin', 'admin'],
        'manager_self' => ['Manager self overdue', 'manager', 'manager'],
        'channel_to_manager' => ['Channel assigned to manager overdue', 'channel', 'manager'],
        'manager_to_channel' => ['Manager assigned to channel overdue', 'manager', 'channel'],
        'manager_to_specialist' => ['Manager assigned to specialist overdue', 'manager', 'specialist'],
        'channel_self' => ['Channel self overdue', 'channel', 'channel'],
        'specialist_self' => ['Specialist self overdue', 'specialist', 'specialist'],
        'sales_self' => ['Sales self overdue', 'sales', 'sales'],
    ];
    foreach ($tasks as [$title, $from, $to]) {
        $insertTask->execute([
            ':title' => $prefix . ' ' . $title,
            ':description' => 'Dashboard task scope regression',
            ':assigned_by' => $userIds[$from],
            ':assigned_to' => $userIds[$to],
            ':due_date' => $yesterday,
            ':status' => 'Açık',
        ]);
    }

    dts_assert(task_relation_info(['assigned_by' => $userIds['manager'], 'assigned_to' => $userIds['manager']], $userIds['manager'])['label'] === 'Kendi takibim', 'Kendi atadigi isi kendi takibi olarak etiketler');
    dts_assert(task_relation_info(['assigned_by' => $userIds['channel'], 'assigned_to' => $userIds['manager']], $userIds['manager'])['label'] === 'Bana atandı', 'Baskasinin yoneticiye atadigi isi bana atandi olarak etiketler');
    dts_assert(task_relation_info(['assigned_by' => $userIds['manager'], 'assigned_to' => $userIds['channel']], $userIds['manager'])['label'] === 'Ben atadım', 'Yoneticinin alt kullaniciya atadigi isi ben atadim olarak etiketler');
    dts_assert(task_relation_info(['assigned_by' => $userIds['channel'], 'assigned_to' => $userIds['channel']], $userIds['manager'])['label'] === 'Ekip işi', 'Alt kullanicinin kendi takibini ekip isi olarak etiketler');

    $managerBreakdown = dts_overdue_breakdown($userIds['manager'], $today);
    dts_assert($managerBreakdown['self'] === 1, 'Yonetici dashboard gecikende kendi takibini ayri sayar');
    dts_assert($managerBreakdown['assigned_to_me'] === 1, 'Yonetici dashboard gecikende kendisine atanan isi ayri sayar');
    dts_assert($managerBreakdown['assigned_by_me'] === 2, 'Yonetici dashboard gecikende kendi atadigi isleri ayri sayar');
    dts_assert($managerBreakdown['team'] === 3, 'Yonetici dashboard gecikende ekip islerini ayri sayar');
    dts_assert($managerBreakdown['total'] === 7, 'Yonetici dashboard kendi kapsamindaki geciken toplam isleri dogru sayar');

    $channelBreakdown = dts_overdue_breakdown($userIds['channel'], $today);
    dts_assert($channelBreakdown['self'] === 1, 'Alt yonetici dashboard gecikende kendi takibini ayri sayar');
    dts_assert($channelBreakdown['assigned_to_me'] === 1, 'Alt yonetici dashboard gecikende kendisine atanan isi ayri sayar');
    dts_assert($channelBreakdown['assigned_by_me'] === 1, 'Alt yonetici dashboard gecikende kendi atadigi isi ayri sayar');
    dts_assert($channelBreakdown['team'] === 3, 'Alt yonetici dashboard gecikende hiyerarsi kapsamindaki ekip islerini ayri sayar');
    dts_assert($channelBreakdown['total'] === 6, 'Alt yonetici dashboard kapsam toplamlarini dogru sayar');

    $specialistBreakdown = dts_overdue_breakdown($userIds['specialist'], $today);
    dts_assert($specialistBreakdown['self'] === 1, 'Kullanici dashboard gecikende kendi takibini ayri sayar');
    dts_assert($specialistBreakdown['assigned_to_me'] === 1, 'Kullanici dashboard gecikende kendisine atanan isi ayri sayar');
    dts_assert($specialistBreakdown['assigned_by_me'] === 0, 'Kullanici dashboard baskasina atanan isi saymaz');
    dts_assert($specialistBreakdown['team'] === 0, 'Kullanici dashboard ekip islerini gormez');
    dts_assert($specialistBreakdown['total'] === 2, 'Kullanici dashboard yalnizca kendi kapsamindaki gecikenleri sayar');

    db()->rollBack();
    echo "Dashboard gorev kapsami testi tamamlandi.\n";
} catch (Throwable $exception) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    throw $exception;
}
