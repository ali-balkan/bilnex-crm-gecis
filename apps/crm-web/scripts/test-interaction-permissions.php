<?php

putenv('CRM_COMPANY_SOURCE=sqlserver');

require __DIR__ . '/../app/bootstrap.php';

$pdo = db();
$prefix = 'Interaction Permission';
$source = 'interaction permission test';

function ip_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
    echo '[OK] ' . $message . PHP_EOL;
}

function ip_cleanup(PDO $pdo, string $prefix, string $source): void
{
    $pdo->prepare('DELETE FROM central_request_logs WHERE central_request_id IN (SELECT id FROM central_requests WHERE requester_name LIKE :prefix)')->execute([':prefix' => $prefix . ' %']);
    $pdo->prepare('DELETE FROM interactions WHERE note LIKE :prefix')->execute([':prefix' => $prefix . ' %']);
    $pdo->prepare('DELETE FROM tasks WHERE title LIKE :prefix')->execute([':prefix' => $prefix . ' %']);
    $pdo->prepare('DELETE FROM central_requests WHERE requester_name LIKE :prefix')->execute([':prefix' => $prefix . ' %']);
    $pdo->prepare('DELETE FROM companies WHERE source = :source')->execute([':source' => $source]);
    $pdo->prepare("DELETE FROM users WHERE username LIKE 'interaction_perm_%'")->execute();
}

function ip_set_user(int $userId): void
{
    $_SESSION['user_id'] = $userId;
}

function ip_visible_notes(string $prefix): array
{
    [$scopeSql, $scopeParams] = interaction_visibility_condition('i');
    $stmt = db()->prepare("SELECT i.note FROM interactions i WHERE i.note LIKE :prefix{$scopeSql} ORDER BY i.note");
    $stmt->execute($scopeParams + [':prefix' => $prefix . ' %']);
    return array_column($stmt->fetchAll(), 'note');
}

function ip_scope_user_names(): array
{
    return array_column(interaction_scope_users(), 'username');
}

ip_cleanup($pdo, $prefix, $source);

try {
    $pdo->beginTransaction();

    $users = [
        'admin' => ['interaction_perm_admin', 'Interaction Admin', ROLE_ADMIN],
        'manager' => ['interaction_perm_manager', 'Interaction Manager', ROLE_MANAGER],
        'channel' => ['interaction_perm_channel', 'Interaction Channel Manager', ROLE_CHANNEL_MANAGER],
        'specialist' => ['interaction_perm_specialist', 'Interaction Specialist', ROLE_CHANNEL_SPECIALIST],
        'sales' => ['interaction_perm_sales', 'Interaction Sales', ROLE_FIELD_SALES],
    ];

    $userIds = [];
    $insertUser = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, active) VALUES (:username, :password_hash, :full_name, :role, 1)');
    foreach ($users as $key => [$username, $fullName, $role]) {
        $insertUser->execute([
            ':username' => $username,
            ':password_hash' => password_hash('Interaction123!', PASSWORD_DEFAULT),
            ':full_name' => $fullName,
            ':role' => $role,
        ]);
        $userIds[$key] = (int) $pdo->lastInsertId();
    }

    $companyIds = [];
    $insertCompany = $pdo->prepare('
        INSERT INTO companies (sql_customer_id, name, account_type, status, source, responsible_user_id, created_by)
        VALUES (:sql_customer_id, :name, :account_type, :status, :source, :responsible_user_id, :created_by)
    ');
    foreach ($userIds as $key => $userId) {
        $insertCompany->execute([
            ':sql_customer_id' => 880000 + count($companyIds) + 1,
            ':name' => $prefix . ' Company ' . $key,
            ':account_type' => 'Müşteri',
            ':status' => 'Aktif',
            ':source' => $source,
            ':responsible_user_id' => $userId,
            ':created_by' => $userId,
        ]);
        $companyIds[$key] = (int) $pdo->lastInsertId();
    }

    $insertCompany->execute([
        ':sql_customer_id' => null,
        ':name' => $prefix . ' Central Process Company',
        ':account_type' => 'Müşteri',
        ':status' => 'Aktif',
        ':source' => $source,
        ':responsible_user_id' => $userIds['admin'],
        ':created_by' => $userIds['admin'],
    ]);
    $centralCompanyId = (int) $pdo->lastInsertId();

    $insertCompany->execute([
        ':sql_customer_id' => null,
        ':name' => $prefix . ' Task Process Company',
        ':account_type' => 'Müşteri',
        ':status' => 'Aktif',
        ':source' => $source,
        ':responsible_user_id' => $userIds['manager'],
        ':created_by' => $userIds['manager'],
    ]);
    $taskCompanyId = (int) $pdo->lastInsertId();

    $insertInteraction = $pdo->prepare('
        INSERT INTO interactions (company_id, sql_customer_id, central_request_id, user_id, interaction_date, type, result, note)
        VALUES (:company_id, :sql_customer_id, :central_request_id, :user_id, :interaction_date, :type, :result, :note)
    ');
    foreach ($userIds as $key => $userId) {
        $insertInteraction->execute([
            ':company_id' => $companyIds[$key],
            ':sql_customer_id' => 880000 + array_search($key, array_keys($userIds), true) + 1,
            ':central_request_id' => null,
            ':user_id' => $userId,
            ':interaction_date' => '2026-05-11',
            ':type' => 'Telefon',
            ':result' => 'Olumlu',
            ':note' => $prefix . ' note ' . $key,
        ]);
    }

    $pdo->prepare('
        INSERT INTO central_requests (
            request_type, requester_name, company_id, assigned_to, assigned_by, assigned_at,
            phone, email, city, district, product_interest, description, source, status, created_by
        ) VALUES (
            :request_type, :requester_name, :company_id, :assigned_to, :assigned_by, CURRENT_TIMESTAMP,
            :phone, :email, :city, :district, :product_interest, :description, :source, :status, :created_by
        )
    ')->execute([
        ':request_type' => 'Demo talebi',
        ':requester_name' => $prefix . ' central request',
        ':company_id' => $centralCompanyId,
        ':assigned_to' => $userIds['specialist'],
        ':assigned_by' => $userIds['channel'],
        ':phone' => '05550000000',
        ':email' => 'central@example.com',
        ':city' => 'İstanbul',
        ':district' => 'Merkez',
        ':product_interest' => 'Demo',
        ':description' => 'Central process assignment',
        ':source' => 'Bilnex Merkez',
        ':status' => 'İş ortağına yönlendirildi',
        ':created_by' => $userIds['admin'],
    ]);
    $centralRequestId = (int) $pdo->lastInsertId();

    $insertInteraction->execute([
        ':company_id' => $centralCompanyId,
        ':sql_customer_id' => null,
        ':central_request_id' => $centralRequestId,
        ':user_id' => $userIds['admin'],
        ':interaction_date' => '2026-05-11',
        ':type' => 'Telefon',
        ':result' => 'Ulaşıldı',
        ':note' => $prefix . ' central assigned note',
    ]);

    $pdo->prepare('
        INSERT INTO tasks (company_id, title, description, assigned_by, assigned_to, due_date, status)
        VALUES (:company_id, :title, :description, :assigned_by, :assigned_to, :due_date, :status)
    ')->execute([
        ':company_id' => $taskCompanyId,
        ':title' => $prefix . ' task assignment',
        ':description' => 'Assigned process access',
        ':assigned_by' => $userIds['manager'],
        ':assigned_to' => $userIds['specialist'],
        ':due_date' => '2026-05-12',
        ':status' => 'Açık',
    ]);

    $pdo->commit();

    ip_set_user($userIds['channel']);
    ip_assert(!user_can_access_company($companyIds['manager']), 'Bayi kanal yöneticisi üst yönetici sorumluluğundaki yerel cariye doğrudan erişemez.');
    ip_assert(can_record_interaction_for_company($companyIds['manager'], 880002), 'Bayi kanal yöneticisi SQL listesinden seçilen cari için görüşme kaydı girebilir.');
    ip_assert(!can_record_interaction_for_company($companyIds['manager'], 0), 'SQL müşteri kimliği olmadan kapsam dışı cariye görüşme kaydı giremez.');

    $channelNotes = ip_visible_notes($prefix);
    ip_assert(in_array($prefix . ' note channel', $channelNotes, true), 'Bayi kanal yöneticisi kendi görüşmesini görür.');
    ip_assert(in_array($prefix . ' note specialist', $channelNotes, true), 'Bayi kanal yöneticisi alt uzman görüşmesini görür.');
    ip_assert(in_array($prefix . ' note sales', $channelNotes, true), 'Bayi kanal yöneticisi saha satış görüşmesini görür.');
    ip_assert(!in_array($prefix . ' note manager', $channelNotes, true), 'Bayi kanal yöneticisi üst yönetici görüşmesini görmez.');
    ip_assert(!in_array($prefix . ' note admin', $channelNotes, true), 'Bayi kanal yöneticisi admin görüşmesini görmez.');

    $channelScopeUsers = ip_scope_user_names();
    ip_assert(in_array('interaction_perm_channel', $channelScopeUsers, true), 'Bayi kanal yöneticisi kullanıcı filtresinde kendisini görür.');
    ip_assert(in_array('interaction_perm_specialist', $channelScopeUsers, true), 'Bayi kanal yöneticisi kullanıcı filtresinde alt uzmanı görür.');
    ip_assert(!in_array('interaction_perm_manager', $channelScopeUsers, true), 'Bayi kanal yöneticisi kullanıcı filtresinde üst yöneticiyi görmez.');

    ip_set_user($userIds['specialist']);
    $specialistNotes = ip_visible_notes($prefix);
    ip_assert(in_array($prefix . ' note specialist', $specialistNotes, true), 'Uzman kendi görüşmesini görür.');
    ip_assert(in_array($prefix . ' central assigned note', $specialistNotes, true), 'Uzman atandığı merkez talebine bağlı görüşmeyi görür ve düzenleme kapsamına alır.');
    ip_assert(!in_array($prefix . ' note sales', $specialistNotes, true), 'Uzman ilgisiz satış görüşmesini görmez.');
    ip_assert(!user_can_access_company($centralCompanyId), 'Uzman merkez talebi carisine doğrudan sahip değildir.');
    ip_assert(can_record_interaction_for_company($centralCompanyId, 0), 'Uzman atandığı merkez talebi carisine görüşme kaydı girebilir.');
    ip_assert(can_record_interaction_for_company($taskCompanyId, 0), 'Uzman kendisine atanan takip işinin carisine görüşme kaydı girebilir.');

    ip_set_user($userIds['sales']);
    $salesNotes = ip_visible_notes($prefix);
    ip_assert(!in_array($prefix . ' central assigned note', $salesNotes, true), 'İlgisiz saha satış merkez talebi görüşmesini görmez.');
    ip_assert(!can_record_interaction_for_company($centralCompanyId, 0), 'İlgisiz saha satış merkez talebi carisine görüşme kaydı giremez.');
    ip_assert(!can_record_interaction_for_company($taskCompanyId, 0), 'İlgisiz saha satış takip atanmış cariye görüşme kaydı giremez.');

    ip_set_user($userIds['manager']);
    $managerNotes = ip_visible_notes($prefix);
    ip_assert(in_array($prefix . ' central assigned note', $managerNotes, true), 'Yönetici hiyerarşisindeki merkez talebi görüşmesini görür.');

    ip_set_user($userIds['admin']);
    ip_assert(count(ip_visible_notes($prefix)) === count($users) + 1, 'Admin tüm görüşmeleri görür.');

    echo "Görüşme yetki testi tamamlandi.\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ip_cleanup($pdo, $prefix, $source);
}
