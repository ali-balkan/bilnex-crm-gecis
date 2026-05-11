<?php

require __DIR__ . '/../app/bootstrap.php';

function central_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function central_rows(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

init_db();

$pdo = db();
$pdo->exec("DELETE FROM central_request_logs WHERE central_request_id IN (SELECT id FROM central_requests WHERE requester_name LIKE 'Central Test %')");
$pdo->exec("DELETE FROM interactions WHERE central_request_id IN (SELECT id FROM central_requests WHERE requester_name LIKE 'Central Test %')");
$pdo->exec("DELETE FROM tasks WHERE central_request_id IN (SELECT id FROM central_requests WHERE requester_name LIKE 'Central Test %')");
$pdo->exec("DELETE FROM central_requests WHERE requester_name LIKE 'Central Test %'");
$pdo->exec("DELETE FROM companies WHERE name LIKE 'Central Test %'");
$pdo->exec("DELETE FROM users WHERE username LIKE 'central_test_%'");

$insertUser = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, active) VALUES (:username, :password_hash, :full_name, :role, 1)');
$users = [
    'admin' => ROLE_ADMIN,
    'manager' => ROLE_MANAGER,
    'channel_manager' => ROLE_CHANNEL_MANAGER,
    'specialist' => ROLE_CHANNEL_SPECIALIST,
    'other_specialist' => ROLE_CHANNEL_SPECIALIST,
    'sales' => ROLE_FIELD_SALES,
];
$userIds = [];
foreach ($users as $key => $role) {
    $insertUser->execute([
        ':username' => 'central_test_' . $key,
        ':password_hash' => password_hash('CentralTest123!', PASSWORD_DEFAULT),
        ':full_name' => 'Central Test ' . $key,
        ':role' => $role,
    ]);
    $userIds[$key] = (int) $pdo->lastInsertId();
}

$pdo->prepare("INSERT INTO companies (name, account_type, phone, city, created_by) VALUES ('Central Test Cari', 'Müşteri', '05550000000', 'İstanbul', :created_by)")->execute([
    ':created_by' => $userIds['admin'],
]);
$companyId = (int) $pdo->lastInsertId();

$_SESSION['user_id'] = $userIds['admin'];
central_assert(can_create_central_request(), 'Admin merkez talebi oluşturabilmeli.');
central_assert(can_assign_central_request_to($userIds['specialist']), 'Admin uzman personele talep atayabilmeli.');

$pdo->prepare("
    INSERT INTO central_requests (
        request_type, requester_name, company_id, assigned_to, assigned_by, assigned_at,
        phone, email, city, district, product_interest, description, source, status, created_by
    ) VALUES (
        'Demo talebi', 'Central Test Firma', :company_id, :assigned_to, :assigned_by, CURRENT_TIMESTAMP,
        '05550000000', 'central@example.com', 'İstanbul', 'Kadıköy', 'ERP demo', 'Merkezden geldi',
        'Bilnex Merkez', 'İş ortağına yönlendirildi', :created_by
    )
")->execute([
    ':company_id' => $companyId,
    ':assigned_to' => $userIds['specialist'],
    ':assigned_by' => $userIds['admin'],
    ':created_by' => $userIds['admin'],
]);
$requestId = (int) $pdo->lastInsertId();
$request = central_rows('SELECT * FROM central_requests WHERE id = :id', [':id' => $requestId])[0];
create_central_request_followup_task($request);

$task = central_rows('SELECT * FROM tasks WHERE central_request_id = :id', [':id' => $requestId])[0] ?? null;
central_assert((bool) $task, 'Merkez talebi için otomatik takip oluşmalı.');
central_assert((int) $task['assigned_to'] === $userIds['specialist'], 'Otomatik takip ilgili personele atanmalı.');
central_assert($task['due_date'] === (new DateTimeImmutable('tomorrow'))->format('Y-m-d'), 'Otomatik takip termin tarihi yarın olmalı.');

central_assert(user_can_access_central_request($requestId), 'Admin tüm merkez taleplerini görmeli.');

$_SESSION['user_id'] = $userIds['manager'];
central_assert(user_can_access_central_request($requestId), 'Yönetici altındaki uzmana gelen talebi görmeli.');
central_assert(can_manage_central_request($request), 'Yönetici altındaki talebi yönetebilmeli.');
central_assert(can_assign_central_request_to($userIds['channel_manager']), 'Yönetici alt yöneticiye talep atayabilmeli.');
central_assert(!can_assign_central_request_to($userIds['admin']), 'Yönetici admin role talep atayamamalı.');

$_SESSION['user_id'] = $userIds['channel_manager'];
central_assert(user_can_access_central_request($requestId), 'Alt yönetici uzman talebini görebilmeli.');
central_assert(can_manage_central_request($request), 'Alt yönetici uzman talebini yeniden yönlendirebilmeli.');
central_assert(can_assign_central_request_to($userIds['specialist']), 'Alt yönetici kendi altındaki uzmana talep atayabilmeli.');
central_assert(!can_assign_central_request_to($userIds['manager']), 'Alt yönetici üst yöneticiye talep atayamamalı.');

$_SESSION['user_id'] = $userIds['specialist'];
central_assert(user_can_access_central_request($requestId), 'Personel kendisine atanan talebi görmeli.');
central_assert(can_update_central_request_status($request), 'Personel kendisine atanan talebin durumunu takip edebilmeli.');
central_assert(!can_assign_central_request_to($userIds['other_specialist']), 'Personel başka personele talep atayamamalı.');

$_SESSION['user_id'] = $userIds['sales'];
central_assert(!user_can_access_central_request($requestId), 'İlgisiz personel başkasına atanan talebi görmemeli.');

$pdo->exec("DELETE FROM central_request_logs WHERE central_request_id IN (SELECT id FROM central_requests WHERE requester_name LIKE 'Central Test %')");
$pdo->exec("DELETE FROM interactions WHERE central_request_id IN (SELECT id FROM central_requests WHERE requester_name LIKE 'Central Test %')");
$pdo->exec("DELETE FROM tasks WHERE central_request_id IN (SELECT id FROM central_requests WHERE requester_name LIKE 'Central Test %')");
$pdo->exec("DELETE FROM central_requests WHERE requester_name LIKE 'Central Test %'");
$pdo->exec("DELETE FROM companies WHERE name LIKE 'Central Test %'");
$pdo->exec("DELETE FROM users WHERE username LIKE 'central_test_%'");

echo "Merkez talepleri yetki ve takip testi tamamlandi.\n";
