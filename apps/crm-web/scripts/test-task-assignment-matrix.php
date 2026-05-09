<?php

require __DIR__ . '/../app/bootstrap.php';

function tam_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

init_db();

$pdo = db();
$pdo->exec('PRAGMA foreign_keys = OFF');
$pdo->exec('DROP TABLE IF EXISTS tasks');
$pdo->exec("
    CREATE TABLE tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        company_id INTEGER NOT NULL,
        sql_customer_id INTEGER,
        title TEXT NOT NULL,
        description TEXT,
        assigned_by INTEGER,
        assigned_to INTEGER,
        due_date TEXT,
        status TEXT NOT NULL DEFAULT 'Açık',
        completion_note TEXT,
        completed_at TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
    )
");
$pdo->exec('PRAGMA foreign_keys = ON');

init_db();

$companyColumn = null;
foreach ($pdo->query('PRAGMA table_info(tasks)')->fetchAll() as $column) {
    if (($column['name'] ?? '') === 'company_id') {
        $companyColumn = $column;
        break;
    }
}
tam_assert($companyColumn !== null && (int) ($companyColumn['notnull'] ?? 1) === 0, 'tasks.company_id nullable olmali.');

$pdo->prepare("DELETE FROM users WHERE username LIKE 'assign_matrix_%'")->execute();

$roles = [
    'admin' => ROLE_ADMIN,
    'manager' => ROLE_MANAGER,
    'channel_manager' => ROLE_CHANNEL_MANAGER,
    'specialist' => ROLE_CHANNEL_SPECIALIST,
    'sales' => ROLE_FIELD_SALES,
];

$insertUser = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, active) VALUES (:username, :password_hash, :full_name, :role, 1)');
$userIds = [];
foreach ($roles as $key => $role) {
    $insertUser->execute([
        ':username' => 'assign_matrix_' . $key,
        ':password_hash' => password_hash('AssignMatrix123!', PASSWORD_DEFAULT),
        ':full_name' => 'Assign Matrix ' . $key,
        ':role' => $role,
    ]);
    $userIds[$key] = (int) $pdo->lastInsertId();
}

$insertTask = $pdo->prepare('
    INSERT INTO tasks (company_id, sql_customer_id, title, description, assigned_by, assigned_to, due_date, status)
    VALUES (:company_id, :sql_customer_id, :title, :description, :assigned_by, :assigned_to, :due_date, :status)
');

foreach ($userIds as $from => $fromId) {
    foreach ($userIds as $to => $toId) {
        $insertTask->execute([
            ':company_id' => null,
            ':sql_customer_id' => null,
            ':title' => sprintf('Assign Matrix %s to %s', $from, $to),
            ':description' => 'No company assignment matrix test',
            ':assigned_by' => $fromId,
            ':assigned_to' => $toId,
            ':due_date' => date('Y-m-d', strtotime('+1 day')),
            ':status' => 'Açık',
        ]);
        $taskId = (int) $pdo->lastInsertId();

        $_SESSION['user_id'] = $fromId;
        tam_assert(user_can_access_task($taskId), "{$from} kendi atadigi isi gorebilmeli.");

        $_SESSION['user_id'] = $toId;
        tam_assert(user_can_access_task($taskId), "{$to} kendisine atanan isi gorebilmeli.");
        tam_assert(can_complete_task(['assigned_to' => $toId]), "{$to} kendisine atanan isi tamamlayabilmeli.");

        if ($fromId !== $toId) {
            $_SESSION['user_id'] = $fromId;
            tam_assert(!can_complete_task(['assigned_to' => $toId]), "{$from} baskasina atadigi isi tamamlayamamali.");
        }
    }
}

$pdo->prepare("DELETE FROM tasks WHERE title LIKE 'Assign Matrix %'")->execute();
$pdo->prepare("DELETE FROM users WHERE username LIKE 'assign_matrix_%'")->execute();

echo "Takip atama matrisi testi tamamlandi.\n";
