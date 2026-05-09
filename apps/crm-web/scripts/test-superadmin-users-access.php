<?php

require __DIR__ . '/../app/bootstrap.php';

init_db();

$testUsername = 'admin_test_hidden';

db()->beginTransaction();
try {
    db()->prepare("
        INSERT INTO users (username, password_hash, full_name, role, active)
        VALUES (:username, :password_hash, :full_name, :role, 1)
    ")->execute([
        ':username' => $testUsername,
        ':password_hash' => password_hash('T12345678!', PASSWORD_DEFAULT),
        ':full_name' => 'Admin Hidden Test',
        ':role' => ROLE_ADMIN,
    ]);

    $superAdminId = (int) db()->query("SELECT id FROM users WHERE username = 'superadmin'")->fetchColumn();
    $otherAdminId = (int) db()->query("SELECT id FROM users WHERE username = 'admin_test_hidden'")->fetchColumn();

    $_SESSION['user_id'] = $superAdminId;
    if (!can_manage_users()) {
        throw new RuntimeException('Superadmin Kullanıcılar bolumunu gorebilmeli.');
    }

    $_SESSION['user_id'] = $otherAdminId;
    if (can_manage_users()) {
        throw new RuntimeException('Superadmin disindaki admin Kullanıcılar bolumunu gormemeli.');
    }

    db()->rollBack();
    echo "Superadmin kullanici yonetimi yetki testi tamamlandi.\n";
} catch (Throwable $exception) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }

    throw $exception;
}
