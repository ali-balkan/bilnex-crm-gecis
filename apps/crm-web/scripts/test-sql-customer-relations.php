<?php

require __DIR__ . '/../app/bootstrap.php';

init_db();

function test_scalar(string $sql, array $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

$sqlCustomerId = 987654321;
$source = 'sql-customer-relation-test';
$adminId = (int) test_scalar("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
if ($adminId <= 0) {
    throw new RuntimeException('Test icin admin kullanici bulunamadi.');
}

db()->beginTransaction();
try {
    db()->prepare("DELETE FROM interactions WHERE company_id IN (SELECT id FROM companies WHERE source = :source)")->execute([':source' => $source]);
    db()->prepare("DELETE FROM opportunities WHERE company_id IN (SELECT id FROM companies WHERE source = :source)")->execute([':source' => $source]);
    db()->prepare("DELETE FROM tasks WHERE company_id IN (SELECT id FROM companies WHERE source = :source)")->execute([':source' => $source]);
    db()->prepare("DELETE FROM companies WHERE source = :source")->execute([':source' => $source]);

    db()->prepare("
        INSERT INTO companies
            (name, sql_customer_id, account_type, account_code, status, source, responsible_user_id, created_by)
        VALUES
            (:name, :sql_customer_id, :account_type, :account_code, :status, :source, :responsible_user_id, :created_by)
    ")->execute([
        ':name' => 'SQL Customer Relation Test',
        ':sql_customer_id' => $sqlCustomerId,
        ':account_type' => 'İş Ortağı',
        ':account_code' => 'CRM-SQL-LINK-TEST',
        ':status' => 'Yeni kayıt',
        ':source' => $source,
        ':responsible_user_id' => $adminId,
        ':created_by' => $adminId,
    ]);
    $companyId = (int) db()->lastInsertId();

    $linkedSqlCustomerId = company_sql_customer_id($companyId);
    if ($linkedSqlCustomerId !== $sqlCustomerId) {
        throw new RuntimeException('Firma kartindan SQL Customer Id okunamadi.');
    }

    db()->prepare("
        INSERT INTO interactions
            (company_id, sql_customer_id, user_id, interaction_date, type, result, note)
        VALUES
            (:company_id, :sql_customer_id, :user_id, :interaction_date, :type, :result, :note)
    ")->execute([
        ':company_id' => $companyId,
        ':sql_customer_id' => $linkedSqlCustomerId,
        ':user_id' => $adminId,
        ':interaction_date' => date('Y-m-d'),
        ':type' => 'Telefon',
        ':result' => 'Olumlu',
        ':note' => 'SQL Customer relation test interaction',
    ]);

    db()->prepare("
        INSERT INTO opportunities
            (company_id, sql_customer_id, salesperson_id, product_service, estimated_amount, stage)
        VALUES
            (:company_id, :sql_customer_id, :salesperson_id, :product_service, :estimated_amount, :stage)
    ")->execute([
        ':company_id' => $companyId,
        ':sql_customer_id' => $linkedSqlCustomerId,
        ':salesperson_id' => $adminId,
        ':product_service' => 'SQL Customer relation test opportunity',
        ':estimated_amount' => 1,
        ':stage' => 'Yeni fırsat',
    ]);

    db()->prepare("
        INSERT INTO tasks
            (company_id, sql_customer_id, title, description, assigned_by, assigned_to, status)
        VALUES
            (:company_id, :sql_customer_id, :title, :description, :assigned_by, :assigned_to, :status)
    ")->execute([
        ':company_id' => $companyId,
        ':sql_customer_id' => $linkedSqlCustomerId,
        ':title' => 'SQL Customer relation test task',
        ':description' => 'SQL Customer Id task link test',
        ':assigned_by' => $adminId,
        ':assigned_to' => $adminId,
        ':status' => 'Açık',
    ]);

    $checks = [
        'interactions' => (int) test_scalar('SELECT COUNT(*) FROM interactions WHERE company_id = :company_id AND sql_customer_id = :sql_customer_id', [':company_id' => $companyId, ':sql_customer_id' => $sqlCustomerId]),
        'opportunities' => (int) test_scalar('SELECT COUNT(*) FROM opportunities WHERE company_id = :company_id AND sql_customer_id = :sql_customer_id', [':company_id' => $companyId, ':sql_customer_id' => $sqlCustomerId]),
        'tasks' => (int) test_scalar('SELECT COUNT(*) FROM tasks WHERE company_id = :company_id AND sql_customer_id = :sql_customer_id', [':company_id' => $companyId, ':sql_customer_id' => $sqlCustomerId]),
    ];

    foreach ($checks as $table => $count) {
        if ($count !== 1) {
            throw new RuntimeException("$table SQL Customer Id iliskisi dogrulanamadi.");
        }
    }

    db()->rollBack();
    echo "SQL Customer Id iliski testi tamamlandi. interactions=1 opportunities=1 tasks=1\n";
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    throw $e;
}
