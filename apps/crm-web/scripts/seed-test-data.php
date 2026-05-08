<?php

require __DIR__ . '/../app/bootstrap.php';

$pdo = db();

$users = [
    [
        'username' => 'test_admin',
        'password' => 'Test123!admin',
        'full_name' => 'Test Admin',
        'role' => ROLE_ADMIN,
    ],
    [
        'username' => 'test_yonetici',
        'password' => 'Test123!yonetici',
        'full_name' => 'Test Yönetici',
        'role' => ROLE_MANAGER,
    ],
    [
        'username' => 'test_kanal',
        'password' => 'Test123!kanal',
        'full_name' => 'Test Bayi Kanal Uzmanı',
        'role' => ROLE_CHANNEL,
    ],
    [
        'username' => 'test_satis',
        'password' => 'Test123!satis',
        'full_name' => 'Test Saha Satış',
        'role' => ROLE_SALES,
    ],
];

$pdo->beginTransaction();

$createdUsers = [];
$userStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username');
$insertUser = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, active) VALUES (:username, :password_hash, :full_name, :role, 1)');
$updateUser = $pdo->prepare('UPDATE users SET password_hash = :password_hash, full_name = :full_name, role = :role, active = 1 WHERE username = :username');

foreach ($users as $user) {
    $userStmt->execute([':username' => $user['username']]);
    $id = $userStmt->fetchColumn();
    $data = [
        ':username' => $user['username'],
        ':password_hash' => password_hash($user['password'], PASSWORD_DEFAULT),
        ':full_name' => $user['full_name'],
        ':role' => $user['role'],
    ];
    if ($id) {
        $updateUser->execute($data);
    } else {
        $insertUser->execute($data);
        $id = $pdo->lastInsertId();
    }
    $createdUsers[$user['username']] = (int) $id;
}

$companyIds = $pdo->query("SELECT id FROM companies WHERE source = 'Test verisi'")->fetchAll(PDO::FETCH_COLUMN);
if ($companyIds) {
    $placeholders = implode(',', array_fill(0, count($companyIds), '?'));
    $pdo->prepare("DELETE FROM companies WHERE id IN ({$placeholders})")->execute($companyIds);
}

$statuses = company_statuses();
$accountTypes = company_account_types();
$types = interaction_types();
$results = interaction_results();
$stages = opportunity_stages();
$cities = ['İstanbul', 'Ankara', 'İzmir', 'Bursa', 'Antalya'];
$districts = ['Kadıköy', 'Çankaya', 'Konak', 'Nilüfer', 'Muratpaşa'];
$products = ['Bilnex Ticari', 'Bilnex Muhasebe', 'Mobilnex', 'Bilnex B2B', 'E-Dönüşüm Paketi'];

$insertCompany = $pdo->prepare('
    INSERT INTO companies
        (name, account_type, contact_person, phone, email, city, district, address, status, source, responsible_user_id, next_followup_date, description, created_by)
    VALUES
        (:name, :account_type, :contact_person, :phone, :email, :city, :district, :address, :status, :source, :responsible_user_id, :next_followup_date, :description, :created_by)
');
$insertInteraction = $pdo->prepare('
    INSERT INTO interactions
        (company_id, user_id, interaction_date, type, result, note, next_followup_date)
    VALUES
        (:company_id, :user_id, :interaction_date, :type, :result, :note, :next_followup_date)
');
$insertOpportunity = $pdo->prepare('
    INSERT INTO opportunities
        (company_id, salesperson_id, product_service, estimated_amount, stage, expected_close_date, note)
    VALUES
        (:company_id, :salesperson_id, :product_service, :estimated_amount, :stage, :expected_close_date, :note)
');

$today = new DateTimeImmutable('today');
$counter = 1;
foreach ($users as $user) {
    $userId = $createdUsers[$user['username']];
    for ($i = 1; $i <= 5; $i++) {
        $followDate = $today->modify(($i - 3) . ' days')->format('Y-m-d');
        $companyName = sprintf('Test %s Firma %02d', $user['full_name'], $i);
        $insertCompany->execute([
            ':name' => $companyName,
            ':account_type' => $accountTypes[($counter - 1) % count($accountTypes)],
            ':contact_person' => 'Yetkili ' . $counter,
            ':phone' => '05' . str_pad((string) (300000000 + $counter), 9, '0', STR_PAD_LEFT),
            ':email' => 'firma' . $counter . '@ornek.test',
            ':city' => $cities[($i - 1) % count($cities)],
            ':district' => $districts[($i - 1) % count($districts)],
            ':address' => 'Test adresi no ' . $counter,
            ':status' => $statuses[($counter - 1) % count($statuses)],
            ':source' => 'Test verisi',
            ':responsible_user_id' => $userId,
            ':next_followup_date' => $followDate,
            ':description' => 'Yetki ve rapor testleri için oluşturulan örnek firma kaydı.',
            ':created_by' => $userId,
        ]);
        $companyId = (int) $pdo->lastInsertId();
        $insertInteraction->execute([
            ':company_id' => $companyId,
            ':user_id' => $userId,
            ':interaction_date' => $today->modify('-' . ($i - 1) . ' days')->format('Y-m-d'),
            ':type' => $types[($counter - 1) % count($types)],
            ':result' => $results[($counter - 1) % count($results)],
            ':note' => 'Örnek görüşme notu ' . $counter,
            ':next_followup_date' => $followDate,
        ]);
        $insertOpportunity->execute([
            ':company_id' => $companyId,
            ':salesperson_id' => $userId,
            ':product_service' => $products[($i - 1) % count($products)],
            ':estimated_amount' => 15000 + ($counter * 1250),
            ':stage' => $stages[($counter - 1) % count($stages)],
            ':expected_close_date' => $today->modify('+' . ($i * 3) . ' days')->format('Y-m-d'),
            ':note' => 'Örnek satış fırsatı ' . $counter,
        ]);
        $counter++;
    }
}

$pdo->commit();

echo "Test verisi oluşturuldu.\n";
foreach ($users as $user) {
    echo $user['username'] . ' / ' . $user['password'] . ' / ' . role_label($user['role']) . "\n";
}
echo "Toplam test firma: 20\n";
