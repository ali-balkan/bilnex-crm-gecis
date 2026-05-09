<?php

require __DIR__ . '/../app/bootstrap.php';

init_db();

function thf_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
    echo '[OK] ' . $message . PHP_EOL;
}

function thf_set_user(int $userId): void
{
    $_SESSION['user_id'] = $userId;
}

function thf_scope_usernames(): array
{
    return array_column(task_scope_users(), 'username');
}

function thf_visible_task_titles(string $prefix): array
{
    [$scopeSql, $scopeParams] = task_visibility_condition('t');
    $stmt = db()->prepare("SELECT t.title FROM tasks t WHERE t.title LIKE :prefix{$scopeSql} ORDER BY t.title");
    $stmt->execute($scopeParams + [':prefix' => $prefix . ' %']);
    return array_column($stmt->fetchAll(), 'title');
}

function thf_visible_role_task_titles(string $prefix, string $role): array
{
    [$scopeSql, $scopeParams] = task_visibility_condition('t');
    $params = $scopeParams + [':prefix' => $prefix . ' %'];
    $placeholders = [];
    foreach (role_database_values([$role]) as $index => $roleValue) {
        $param = ':role_filter_' . $index;
        $placeholders[] = $param;
        $params[$param] = $roleValue;
    }

    $roleSql = implode(', ', $placeholders);
    $stmt = db()->prepare("
        SELECT t.title
        FROM tasks t
        WHERE t.title LIKE :prefix{$scopeSql}
          AND EXISTS (
              SELECT 1
              FROM users task_filter_user
              WHERE task_filter_user.id IN (t.assigned_by, t.assigned_to)
                AND task_filter_user.role IN ({$roleSql})
          )
        ORDER BY t.title
    ");
    $stmt->execute($params);
    return array_column($stmt->fetchAll(), 'title');
}

$prefix = 'TaskHierarchyFilter';
$userPrefix = 'task_hierarchy_filter_';

db()->beginTransaction();
try {
    $insertUser = db()->prepare('
        INSERT INTO users (username, password_hash, full_name, role, active)
        VALUES (:username, :password_hash, :full_name, :role, 1)
    ');
    $users = [
        'admin' => [$userPrefix . 'admin', 'Task Hierarchy Admin', ROLE_ADMIN],
        'manager_a' => [$userPrefix . 'manager_a', 'Task Hierarchy Manager A', ROLE_MANAGER],
        'manager_b' => [$userPrefix . 'manager_b', 'Task Hierarchy Manager B', ROLE_MANAGER],
        'channel_a' => [$userPrefix . 'channel_a', 'Task Hierarchy Channel A', ROLE_CHANNEL_MANAGER],
        'channel_b' => [$userPrefix . 'channel_b', 'Task Hierarchy Channel B', ROLE_CHANNEL_MANAGER],
        'specialist_a' => [$userPrefix . 'specialist_a', 'Task Hierarchy Specialist A', ROLE_CHANNEL_SPECIALIST],
        'specialist_b' => [$userPrefix . 'specialist_b', 'Task Hierarchy Specialist B', ROLE_CHANNEL_SPECIALIST],
        'sales_a' => [$userPrefix . 'sales_a', 'Task Hierarchy Sales A', ROLE_FIELD_SALES],
    ];
    $userIds = [];
    foreach ($users as $key => [$username, $fullName, $role]) {
        $insertUser->execute([
            ':username' => $username,
            ':password_hash' => password_hash('TaskHierarchy123!', PASSWORD_DEFAULT),
            ':full_name' => $fullName,
            ':role' => $role,
        ]);
        $userIds[$key] = (int) db()->lastInsertId();
    }

    $insertTask = db()->prepare('
        INSERT INTO tasks (company_id, sql_customer_id, title, description, assigned_by, assigned_to, due_date, status)
        VALUES (NULL, NULL, :title, :description, :assigned_by, :assigned_to, :due_date, :status)
    ');
    $taskRows = [
        'admin_self' => ['Admin self task', 'admin', 'admin'],
        'manager_peer_self' => ['Manager peer self task', 'manager_b', 'manager_b'],
        'channel_peer_self' => ['Channel peer self task', 'channel_b', 'channel_b'],
        'specialist_self' => ['Specialist self task', 'specialist_a', 'specialist_a'],
        'specialist_peer_self' => ['Specialist peer self task', 'specialist_b', 'specialist_b'],
        'sales_self' => ['Sales self task', 'sales_a', 'sales_a'],
    ];
    foreach ($taskRows as [$title, $from, $to]) {
        $insertTask->execute([
            ':title' => $prefix . ' ' . $title,
            ':description' => 'Hierarchy filter regression',
            ':assigned_by' => $userIds[$from],
            ':assigned_to' => $userIds[$to],
            ':due_date' => date('Y-m-d', strtotime('+1 day')),
            ':status' => 'Açık',
        ]);
    }

    thf_set_user($userIds['manager_a']);
    $managerScope = thf_scope_usernames();
    thf_assert(in_array($userPrefix . 'manager_b', $managerScope, true), 'Yonetici ayni roldeki diger yoneticiyi filtrede gorur');
    thf_assert(in_array($userPrefix . 'channel_b', $managerScope, true), 'Yonetici alt roldeki kanal yoneticisini filtrede gorur');
    thf_assert(!in_array($userPrefix . 'admin', $managerScope, true), 'Yonetici admin kullanicisini filtrede gormez');
    $managerTasks = thf_visible_task_titles($prefix);
    thf_assert(in_array($prefix . ' Manager peer self task', $managerTasks, true), 'Yonetici peer yonetici takibini gorur');
    thf_assert(!in_array($prefix . ' Admin self task', $managerTasks, true), 'Yonetici admin takibini gormez');
    $managerRoleTasks = thf_visible_role_task_titles($prefix, ROLE_MANAGER);
    thf_assert(in_array($prefix . ' Manager peer self task', $managerRoleTasks, true), 'Yonetici rol filtresi peer yonetici takiplerini getirir');

    thf_set_user($userIds['channel_a']);
    $channelScope = thf_scope_usernames();
    thf_assert(in_array($userPrefix . 'channel_b', $channelScope, true), 'Bayi kanal yoneticisi ayni roldeki diger kanal yoneticisini filtrede gorur');
    thf_assert(in_array($userPrefix . 'specialist_a', $channelScope, true), 'Bayi kanal yoneticisi alt roldeki uzmani filtrede gorur');
    thf_assert(!in_array($userPrefix . 'manager_b', $channelScope, true), 'Bayi kanal yoneticisi ust roldeki yoneticiyi filtrede gormez');
    $channelTasks = thf_visible_task_titles($prefix);
    thf_assert(in_array($prefix . ' Channel peer self task', $channelTasks, true), 'Bayi kanal yoneticisi peer kanal takibini gorur');
    thf_assert(!in_array($prefix . ' Manager peer self task', $channelTasks, true), 'Bayi kanal yoneticisi ust rol takibini gormez');

    thf_set_user($userIds['specialist_a']);
    $specialistScope = thf_scope_usernames();
    thf_assert(in_array($userPrefix . 'specialist_a', $specialistScope, true), 'Uzman kendi filtresini gorur');
    thf_assert(!in_array($userPrefix . 'specialist_b', $specialistScope, true), 'Uzman peer uzmani filtrede gormez');
    $specialistTasks = thf_visible_task_titles($prefix);
    thf_assert(in_array($prefix . ' Specialist self task', $specialistTasks, true), 'Uzman kendi takibini gorur');
    thf_assert(!in_array($prefix . ' Specialist peer self task', $specialistTasks, true), 'Uzman peer uzman takibini gormez');

    db()->rollBack();
    echo "Takip hiyerarsi filtre testi tamamlandi.\n";
} catch (Throwable $exception) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    throw $exception;
}
