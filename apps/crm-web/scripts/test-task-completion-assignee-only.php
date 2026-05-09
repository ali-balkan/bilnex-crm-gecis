<?php

require __DIR__ . '/../app/bootstrap.php';

init_db();

db()->beginTransaction();
try {
    $insertUser = db()->prepare("
        INSERT INTO users (username, password_hash, full_name, role, active)
        VALUES (:username, :password_hash, :full_name, :role, 1)
    ");
    foreach ([
        ['completion_admin_test', ROLE_ADMIN, 'Completion Admin Test'],
        ['completion_sales_test', ROLE_FIELD_SALES, 'Completion Sales Test'],
    ] as [$username, $role, $name]) {
        $insertUser->execute([
            ':username' => $username,
            ':password_hash' => password_hash('T12345678!', PASSWORD_DEFAULT),
            ':full_name' => $name,
            ':role' => $role,
        ]);
    }

    $adminId = (int) db()->query("SELECT id FROM users WHERE username = 'completion_admin_test'")->fetchColumn();
    $salesId = (int) db()->query("SELECT id FROM users WHERE username = 'completion_sales_test'")->fetchColumn();
    $task = ['assigned_to' => $salesId];

    $_SESSION['user_id'] = $adminId;
    if (can_complete_task($task)) {
        throw new RuntimeException('Atanan kisi olmayan admin isi tamamlayamamali.');
    }

    $_SESSION['user_id'] = $salesId;
    if (!can_complete_task($task)) {
        throw new RuntimeException('Atanan kisi isi tamamlayabilmeli.');
    }

    db()->rollBack();
    echo "Takip tamamlama yetki testi tamamlandi.\n";
} catch (Throwable $exception) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }

    throw $exception;
}
