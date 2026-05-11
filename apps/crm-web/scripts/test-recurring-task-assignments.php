<?php

require __DIR__ . '/../app/bootstrap.php';

init_db();

function gpr_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
    echo '[OK] ' . $message . PHP_EOL;
}

function gpr_set_user(int $userId): void
{
    $_SESSION['user_id'] = $userId;
}

function gpr_insert_goal(int $assignedBy, int $assignedTo, string $title, string $recurrenceType, string $startDate, ?string $endDate = null): int
{
    $stmt = db()->prepare('
        INSERT INTO goals (title, description, assigned_by, assigned_to, recurrence_type, start_date, end_date, active)
        VALUES (:title, :description, :assigned_by, :assigned_to, :recurrence_type, :start_date, :end_date, 1)
    ');
    $stmt->execute([
        ':title' => $title,
        ':description' => 'Recurring task permission recurrence test',
        ':assigned_by' => $assignedBy,
        ':assigned_to' => $assignedTo,
        ':recurrence_type' => $recurrenceType,
        ':start_date' => $startDate,
        ':end_date' => $endDate,
    ]);
    return (int) db()->lastInsertId();
}

function gpr_goal(int $goalId): array
{
    $stmt = db()->prepare('SELECT * FROM goals WHERE id = :id');
    $stmt->execute([':id' => $goalId]);
    $goal = $stmt->fetch();
    if (!$goal) {
        throw new RuntimeException('Recurring task assignment not found: ' . $goalId);
    }
    return $goal;
}

function gpr_visible_goal_titles(string $prefix): array
{
    [$scopeSql, $scopeParams] = goal_visibility_condition('g');
    $stmt = db()->prepare("SELECT g.title FROM goals g WHERE g.title LIKE :prefix{$scopeSql} ORDER BY g.title");
    $stmt->execute($scopeParams + [':prefix' => $prefix . ' %']);
    return array_column($stmt->fetchAll(), 'title');
}

function gpr_occurrence_rows(int $goalId): array
{
    $stmt = db()->prepare('SELECT period_key, due_date, status FROM goal_occurrences WHERE goal_id = :goal_id ORDER BY due_date');
    $stmt->execute([':goal_id' => $goalId]);
    return $stmt->fetchAll();
}

$prefix = 'RecurringTaskPermRec';
$userPrefix = 'recurring_task_perm_rec_';

db()->beginTransaction();
try {
    $insertUser = db()->prepare('
        INSERT INTO users (username, password_hash, full_name, role, active)
        VALUES (:username, :password_hash, :full_name, :role, 1)
    ');
    $users = [
        'admin' => [$userPrefix . 'admin', 'Recurring Task Admin', ROLE_ADMIN],
        'manager' => [$userPrefix . 'manager', 'Recurring Task Manager', ROLE_MANAGER],
        'manager_peer' => [$userPrefix . 'manager_peer', 'Recurring Task Manager Peer', ROLE_MANAGER],
        'channel' => [$userPrefix . 'channel', 'Recurring Task Channel Manager', ROLE_CHANNEL_MANAGER],
        'channel_peer' => [$userPrefix . 'channel_peer', 'Recurring Task Channel Peer', ROLE_CHANNEL_MANAGER],
        'specialist' => [$userPrefix . 'specialist', 'Recurring Task Specialist', ROLE_CHANNEL_SPECIALIST],
        'sales' => [$userPrefix . 'sales', 'Recurring Task Sales', ROLE_FIELD_SALES],
    ];
    $userIds = [];
    foreach ($users as $key => [$username, $fullName, $role]) {
        $insertUser->execute([
            ':username' => $username,
            ':password_hash' => password_hash('RecurringTaskPermRec123!', PASSWORD_DEFAULT),
            ':full_name' => $fullName,
            ':role' => $role,
        ]);
        $userIds[$key] = (int) db()->lastInsertId();
    }

    gpr_set_user($userIds['admin']);
    gpr_assert(can_assign_goal_to($userIds['manager']), 'Ust yonetici alt yoneticiye duzenli gorev atayabilir');
    gpr_assert(can_assign_goal_to($userIds['specialist']), 'Ust yonetici kendi hiyerarsisindeki kullaniciya duzenli gorev atayabilir');

    gpr_set_user($userIds['manager']);
    gpr_assert(can_assign_goal_to($userIds['channel']), 'Yonetici alt yoneticiye duzenli gorev atayabilir');
    gpr_assert(can_assign_goal_to($userIds['sales']), 'Yonetici alt kullaniciya duzenli gorev atayabilir');
    gpr_assert(!can_assign_goal_to($userIds['admin']), 'Yonetici ust role duzenli gorev atayamaz');
    gpr_assert(!can_assign_goal_to($userIds['manager_peer']), 'Yonetici ayni roldeki diger yoneticiye duzenli gorev atayamaz');

    gpr_set_user($userIds['channel']);
    gpr_assert(can_assign_goal_to($userIds['specialist']), 'Alt yonetici kendi altindaki kullaniciya duzenli gorev atayabilir');
    gpr_assert(!can_assign_goal_to($userIds['manager']), 'Alt yonetici hiyerarsi disindaki yoneticiye duzenli gorev atayamaz');
    gpr_assert(!can_assign_goal_to($userIds['channel_peer']), 'Alt yonetici hiyerarsi disindaki ayni role duzenli gorev atayamaz');

    gpr_set_user($userIds['specialist']);
    gpr_assert(can_assign_goal_to($userIds['specialist']), 'Kullanici kendi duzenli gorevini takip edebilir');
    gpr_assert(!can_assign_goal_to($userIds['sales']), 'Kullanici baska kullaniciya duzenli gorev atayamaz');

    $managerToSpecialistGoalId = gpr_insert_goal($userIds['manager'], $userIds['specialist'], $prefix . ' manager to specialist', 'daily', '2026-05-09');
    $managerToSalesGoalId = gpr_insert_goal($userIds['manager'], $userIds['sales'], $prefix . ' manager to sales', 'daily', '2026-05-09');

    gpr_set_user($userIds['specialist']);
    $visibleForSpecialist = gpr_visible_goal_titles($prefix);
    gpr_assert(in_array($prefix . ' manager to specialist', $visibleForSpecialist, true), 'Kullanici kendisine atanmis duzenli gorev atama kaydini gorur');
    gpr_assert(!in_array($prefix . ' manager to sales', $visibleForSpecialist, true), 'Kullanici yalnizca kendisine atanmis duzenli gorev atama kayitlarini gorur');
    gpr_assert(!can_manage_goal(gpr_goal($managerToSpecialistGoalId)), 'Yetkisiz kullanici duzenli gorev atama kaydini guncelleyemez');
    gpr_assert(!can_manage_goal(gpr_goal($managerToSpecialistGoalId)), 'Yetkisiz kullanici duzenli gorev atama kaydini silemez veya pasiflestiremez');

    gpr_set_user($userIds['manager']);
    gpr_assert(can_manage_goal(gpr_goal($managerToSpecialistGoalId)), 'Atayan yonetici duzenli gorev atama kaydini yonetebilir');

    $dailyGoalId = gpr_insert_goal($userIds['manager'], $userIds['specialist'], $prefix . ' daily recurrence', 'daily', '2026-05-09');
    $weeklyGoalId = gpr_insert_goal($userIds['manager'], $userIds['specialist'], $prefix . ' weekly recurrence', 'weekly', '2026-04-27');
    $monthlyGoalId = gpr_insert_goal($userIds['manager'], $userIds['specialist'], $prefix . ' monthly recurrence', 'monthly', '2026-03-31');

    sync_goal_occurrences('2026-05-11');
    sync_goal_occurrences('2026-05-11');

    $dailyRows = gpr_occurrence_rows($dailyGoalId);
    gpr_assert(count($dailyRows) === 3, 'Daily duzenli gorev atama ayni periyot icin duplicate uretmeden uc gun olusturur');
    gpr_assert($dailyRows[0]['status'] === 'gecikti' && $dailyRows[2]['status'] === 'bekliyor', 'Daily duzenli gorev atama geciken ve bugunku bekleyen durumunu dogru uretir');

    $weeklyRows = gpr_occurrence_rows($weeklyGoalId);
    gpr_assert(count($weeklyRows) === 3, 'Weekly duzenli gorev atama ayni hafta icin duplicate uretmeden haftalik kayit olusturur');
    gpr_assert(array_column($weeklyRows, 'due_date') === ['2026-04-27', '2026-05-04', '2026-05-11'], 'Weekly duzenli gorev atama baslangic gununu koruyarak haftalik ilerler');

    sync_goal_occurrences('2026-05-31');
    sync_goal_occurrences('2026-05-31');
    $monthlyRows = gpr_occurrence_rows($monthlyGoalId);
    gpr_assert(count($monthlyRows) === 3, 'Monthly duzenli gorev atama ayni ay icin duplicate uretmeden aylik kayit olusturur');
    gpr_assert(array_column($monthlyRows, 'due_date') === ['2026-03-31', '2026-04-30', '2026-05-31'], 'Monthly duzenli gorev atama ay sonu gunlerini dogru hesaplar');

    db()->rollBack();
    echo "Düzenli görev atama yetki ve tekrar testi tamamlandi.\n";
} catch (Throwable $exception) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    throw $exception;
}
