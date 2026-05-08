<?php

require __DIR__ . '/../app/bootstrap.php';

init_db();

function test_scalar(string $sql, array $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

$source = 'mobile-permission-report-test';

db()->beginTransaction();
try {
    db()->prepare("DELETE FROM companies WHERE source = :source")->execute([':source' => $source]);
    db()->prepare("DELETE FROM users WHERE username IN ('mob_admin_test', 'mob_manager_test', 'mob_sales_test', 'mob_other_test')")->execute();

    $insertUser = db()->prepare("INSERT INTO users (username, password_hash, full_name, role, active) VALUES (:username, :password_hash, :full_name, :role, 1)");
    foreach ([
        ['mob_admin_test', ROLE_ADMIN, 'Mobil Admin Test'],
        ['mob_manager_test', ROLE_MANAGER, 'Mobil Yönetici Test'],
        ['mob_sales_test', ROLE_SALES, 'Mobil Satış Test'],
        ['mob_other_test', ROLE_SALES, 'Mobil Diğer Test'],
    ] as [$username, $role, $name]) {
        $insertUser->execute([
            ':username' => $username,
            ':password_hash' => password_hash('Test123!', PASSWORD_DEFAULT),
            ':full_name' => $name,
            ':role' => $role,
        ]);
    }

    $adminId = (int) test_scalar("SELECT id FROM users WHERE username = 'mob_admin_test'");
    $managerId = (int) test_scalar("SELECT id FROM users WHERE username = 'mob_manager_test'");
    $salesId = (int) test_scalar("SELECT id FROM users WHERE username = 'mob_sales_test'");
    $otherId = (int) test_scalar("SELECT id FROM users WHERE username = 'mob_other_test'");

    $_SESSION['user_id'] = $adminId;
    if (!can_view_all()) {
        throw new RuntimeException('Admin tum kayitlari goremiyor.');
    }
    $_SESSION['user_id'] = $managerId;
    if (!can_view_all()) {
        throw new RuntimeException('Yonetici tum kayitlari goremiyor.');
    }
    $_SESSION['user_id'] = $salesId;
    if (can_view_all()) {
        throw new RuntimeException('Satis kullanicisi tum kayit yetkisi almamali.');
    }

    $insertCompany = db()->prepare("
        INSERT INTO companies (name, status, source, responsible_user_id, created_by, created_at)
        VALUES (:name, :status, :source, :responsible_user_id, :created_by, CURRENT_TIMESTAMP)
    ");
    $insertCompany->execute([
        ':name' => 'Sorumlu olduğum test firma',
        ':status' => 'Yeni kayıt',
        ':source' => $source,
        ':responsible_user_id' => $salesId,
        ':created_by' => $otherId,
    ]);
    $insertCompany->execute([
        ':name' => 'Oluşturduğum test firma',
        ':status' => 'Yeni kayıt',
        ':source' => $source,
        ':responsible_user_id' => $otherId,
        ':created_by' => $salesId,
    ]);
    $insertCompany->execute([
        ':name' => 'Başkasının test firma',
        ':status' => 'Yeni kayıt',
        ':source' => $source,
        ':responsible_user_id' => $otherId,
        ':created_by' => $otherId,
    ]);

    $salesScoped = (int) test_scalar("
        SELECT COUNT(*)
        FROM companies c
        WHERE c.source = :source
          AND (c.responsible_user_id = :uid OR c.created_by = :uid)
    ", [':source' => $source, ':uid' => $salesId]);
    $adminScoped = (int) test_scalar("SELECT COUNT(*) FROM companies c WHERE c.source = :source", [':source' => $source]);

    if ($salesScoped !== 2) {
        throw new RuntimeException('Kullanici rapor kapsami beklenen 2 kaydi vermedi.');
    }
    if ($adminScoped !== 3) {
        throw new RuntimeException('Admin rapor kapsami beklenen 3 kaydi vermedi.');
    }

    $css = file_get_contents(__DIR__ . '/../assets/app.css');
    foreach (['@media (max-width: 760px)', '@media (max-width: 420px)', 'grid-template-columns: 1fr', 'min-height: 44px'] as $needle) {
        if (!str_contains($css, $needle)) {
            throw new RuntimeException('Mobil CSS kontrolu eksik: ' . $needle);
        }
    }

    db()->rollBack();
    echo "Mobil/yetki/rapor testi tamamlandi. sales_scope=2 admin_scope=3\n";
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    throw $e;
} finally {
    unset($_SESSION['user_id']);
}
