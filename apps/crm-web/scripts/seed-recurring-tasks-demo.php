<?php

require __DIR__ . '/../app/bootstrap.php';

init_db();

function seed_goal_user(string $username, string $fullName, string $role): int
{
    $stmt = db()->prepare('SELECT id FROM users WHERE username = :username');
    $stmt->execute([':username' => $username]);
    $existingId = (int) ($stmt->fetchColumn() ?: 0);
    if ($existingId > 0) {
        db()->prepare('UPDATE users SET full_name = :full_name, role = :role, active = 1 WHERE id = :id')->execute([
            ':full_name' => $fullName,
            ':role' => $role,
            ':id' => $existingId,
        ]);
        return $existingId;
    }

    db()->prepare('
        INSERT INTO users (username, password_hash, full_name, role, active)
        VALUES (:username, :password_hash, :full_name, :role, 1)
    ')->execute([
        ':username' => $username,
        ':password_hash' => password_hash('RecurringTaskDemo123!', PASSWORD_DEFAULT),
        ':full_name' => $fullName,
        ':role' => $role,
    ]);
    return (int) db()->lastInsertId();
}

function seed_goal(int $assignedBy, int $assignedTo, string $title, string $description, string $recurrenceType, string $startDate): void
{
    $stmt = db()->prepare('SELECT id FROM goals WHERE title = :title AND assigned_to = :assigned_to');
    $stmt->execute([
        ':title' => $title,
        ':assigned_to' => $assignedTo,
    ]);
    $existingId = (int) ($stmt->fetchColumn() ?: 0);
    if ($existingId > 0) {
        db()->prepare('
            UPDATE goals
            SET description = :description, assigned_by = :assigned_by, recurrence_type = :recurrence_type,
                start_date = :start_date, active = 1, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ')->execute([
            ':description' => $description,
            ':assigned_by' => $assignedBy,
            ':recurrence_type' => $recurrenceType,
            ':start_date' => $startDate,
            ':id' => $existingId,
        ]);
        refresh_goal_occurrences($existingId);
        return;
    }

    db()->prepare('
        INSERT INTO goals (title, description, assigned_by, assigned_to, recurrence_type, start_date, active)
        VALUES (:title, :description, :assigned_by, :assigned_to, :recurrence_type, :start_date, 1)
    ')->execute([
        ':title' => $title,
        ':description' => $description,
        ':assigned_by' => $assignedBy,
        ':assigned_to' => $assignedTo,
        ':recurrence_type' => $recurrenceType,
        ':start_date' => $startDate,
    ]);
    refresh_goal_occurrences((int) db()->lastInsertId());
}

$managerId = seed_goal_user('duzenli_gorev_demo_yonetici', 'Düzenli Görev Demo Yönetici', ROLE_MANAGER);
$channelId = seed_goal_user('duzenli_gorev_demo_kanal', 'Düzenli Görev Demo Kanal Yöneticisi', ROLE_CHANNEL_MANAGER);
$specialistId = seed_goal_user('duzenli_gorev_demo_uzman', 'Düzenli Görev Demo Bayi Kanal Uzmanı', ROLE_CHANNEL_SPECIALIST);
$salesId = seed_goal_user('duzenli_gorev_demo_saha', 'Düzenli Görev Demo Saha Satış', ROLE_FIELD_SALES);

$today = date('Y-m-d');
$monday = (new DateTimeImmutable($today))->modify('monday this week')->format('Y-m-d');
$monthStart = date('Y-m-01');

seed_goal($managerId, $channelId, 'Haftalık kanal durum kontrolü', 'Kanal ekibinin açık işleri ve fırsatları kontrol edilecek.', 'weekly', $monday);
seed_goal($managerId, $specialistId, 'Günlük bayi arama planı', 'Her gün atanmış bayi adayları aranacak ve görüşme notu girilecek.', 'daily', $today);
seed_goal($channelId, $salesId, 'Aylık saha müşteri raporu', 'Ay sonunda saha satış müşteri raporu güncellenecek.', 'monthly', $monthStart);

sync_goal_occurrences($today);

echo "Düzenli görev atama demo verileri hazir.\n";
echo "Kullanicilar: duzenli_gorev_demo_yonetici, duzenli_gorev_demo_kanal, duzenli_gorev_demo_uzman, duzenli_gorev_demo_saha\n";
echo "Sifre: RecurringTaskDemo123!\n";
