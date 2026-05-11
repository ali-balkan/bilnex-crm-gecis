<?php

require __DIR__ . '/../app/bootstrap.php';

$pdo = db();
$prefix = 'Assignment Notify';

function an_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
    echo '[OK] ' . $message . PHP_EOL;
}

function an_set_user(int $userId): void
{
    $_SESSION['user_id'] = $userId;
}

$pdo->prepare('DELETE FROM assignment_notifications WHERE title LIKE :prefix')->execute([':prefix' => $prefix . ' %']);
$pdo->prepare("DELETE FROM users WHERE username LIKE 'assignment_notify_%'")->execute();

try {
    $insertUser = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, active) VALUES (:username, :password_hash, :full_name, :role, 1)');
    $insertUser->execute([
        ':username' => 'assignment_notify_manager',
        ':password_hash' => password_hash('Notify123!', PASSWORD_DEFAULT),
        ':full_name' => 'Assignment Notify Manager',
        ':role' => ROLE_MANAGER,
    ]);
    $managerId = (int) $pdo->lastInsertId();

    $insertUser->execute([
        ':username' => 'assignment_notify_specialist',
        ':password_hash' => password_hash('Notify123!', PASSWORD_DEFAULT),
        ':full_name' => 'Assignment Notify Specialist',
        ':role' => ROLE_CHANNEL_SPECIALIST,
    ]);
    $specialistId = (int) $pdo->lastInsertId();

    an_set_user($managerId);
    create_assignment_notification(
        $specialistId,
        'task',
        101,
        $prefix . ' takip',
        'Yeni takip işi atandı',
        app_url('followups', ['edit_task' => 101])
    );

    $unread = unread_assignment_notifications($specialistId);
    an_assert(count($unread) === 1, 'Atanan kullanıcıya okunmamış bildirim oluşur.');
    an_assert($unread[0]['sound_key'] === 'double', 'Bildirim 2 numaralı çift uyarı sesini kullanır.');
    an_assert(str_contains((string) $unread[0]['target_url'], 'followups'), 'Bildirim hedef ekran bağlantısı taşır.');

    mark_assignment_notifications_read($specialistId, [(int) $unread[0]['id']]);
    an_assert(count(unread_assignment_notifications($specialistId)) === 0, 'Okunan bildirim tekrar gelmez.');

    an_set_user($specialistId);
    create_assignment_notification(
        $specialistId,
        'task',
        102,
        $prefix . ' kendi takibim',
        'Kendi ataması',
        app_url('followups', ['edit_task' => 102])
    );
    an_assert(count(unread_assignment_notifications($specialistId)) === 0, 'Kullanıcının kendine yaptığı atama sesli bildirim üretmez.');

    an_set_user($managerId);
    create_assignment_notification(
        $specialistId,
        'central_request',
        201,
        $prefix . ' merkez talebi',
        'Merkez talebi atandı',
        app_url('central_requests', ['id' => 201])
    );
    create_assignment_notification(
        $specialistId,
        'recurring_task',
        301,
        $prefix . ' düzenli görev',
        'Düzenli görev atandı',
        app_url('recurring_tasks', ['edit_recurring_task' => 301])
    );
    an_assert(count(unread_assignment_notifications($specialistId)) === 2, 'Merkez talebi ve düzenli görev atamaları bildirim oluşturur.');

    echo "Atama bildirim testi tamamlandi.\n";
} finally {
    $pdo->prepare('DELETE FROM assignment_notifications WHERE title LIKE :prefix')->execute([':prefix' => $prefix . ' %']);
    $pdo->prepare("DELETE FROM users WHERE username LIKE 'assignment_notify_%'")->execute();
}
