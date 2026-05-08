<?php

final class CustomerReadRepository
{
    private ?string $lastError = null;

    public function __construct(private ReadOnlySqlServerConnection $connection)
    {
    }

    public function findActiveCustomers(int $limit = 100, ?int $customerTypeId = null): array
    {
        return $this->findActiveCustomersPage($limit, 0, $customerTypeId);
    }

    public function findActiveCustomersPage(int $limit = 100, int $offset = 0, ?int $customerTypeId = null, string $query = ''): array
    {
        $limit = max(1, min($limit, 500));
        $offset = max(0, $offset);
        $customerTypeId = $customerTypeId !== null ? max(1, $customerTypeId) : null;
        $typeWhere = $customerTypeId !== null ? " AND c.CustomerTypeId = {$customerTypeId}" : '';
        $params = [];
        $searchWhere = '';
        $query = trim($query);
        if ($query !== '') {
            $searchWhere = ' AND (c.Name1 LIKE :query_name OR c.Name2 LIKE :query_contact OR c.Code LIKE :query_code OR c.TaxNumber LIKE :query_tax)';
            $params[':query_name'] = '%' . $query . '%';
            $params[':query_contact'] = '%' . $query . '%';
            $params[':query_code'] = '%' . $query . '%';
            $params[':query_tax'] = '%' . $query . '%';
        }

        return $this->safeFetchAll("
            SELECT
                c.Id,
                c.CustomerTypeId,
                c.MainCustomerId,
                c.CustomerTaxType,
                c.Name1,
                c.Name2,
                c.TaxOffice,
                c.TaxNumber,
                c.Description,
                c.StaffId,
                c.CreatedDate,
                c.CreatedUserId,
                c.isActive,
                c.isDeleted,
                c.GroupId,
                c.RegionId,
                c.CategoryId,
                c.Code,
                c.RepresentativeId
            FROM dbo.Customer c
            WHERE ISNULL(c.isDeleted, 0) = 0{$typeWhere}{$searchWhere}
            ORDER BY c.Id DESC
            OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY
        ", $params);
    }

    public function countActiveCustomers(?int $customerTypeId = null, string $query = ''): int
    {
        $customerTypeId = $customerTypeId !== null ? max(1, $customerTypeId) : null;
        $typeWhere = $customerTypeId !== null ? " AND c.CustomerTypeId = {$customerTypeId}" : '';
        $params = [];
        $searchWhere = '';
        $query = trim($query);
        if ($query !== '') {
            $searchWhere = ' AND (c.Name1 LIKE :query_name OR c.Name2 LIKE :query_contact OR c.Code LIKE :query_code OR c.TaxNumber LIKE :query_tax)';
            $params[':query_name'] = '%' . $query . '%';
            $params[':query_contact'] = '%' . $query . '%';
            $params[':query_code'] = '%' . $query . '%';
            $params[':query_tax'] = '%' . $query . '%';
        }
        $row = $this->safeFetchOne("
            SELECT COUNT(*) AS total
            FROM dbo.Customer c
            WHERE ISNULL(c.isDeleted, 0) = 0{$typeWhere}{$searchWhere}
        ", $params);

        return (int) ($row['total'] ?? 0);
    }

    public function countActiveCustomersByType(): array
    {
        return $this->safeFetchAll("
            SELECT
                c.CustomerTypeId,
                ct.Name AS CustomerTypeName,
                COUNT(*) AS total
            FROM dbo.Customer c
            LEFT JOIN dbo.CustomerType ct ON ct.CustomerTypeId = c.CustomerTypeId
            WHERE ISNULL(c.isDeleted, 0) = 0
            GROUP BY c.CustomerTypeId, ct.Name
            ORDER BY total DESC
        ");
    }

    public function findById(int $id): ?array
    {
        return $this->safeFetchOne("
            SELECT
                c.Id,
                c.CustomerTypeId,
                c.MainCustomerId,
                c.CustomerTaxType,
                c.Name1,
                c.Name2,
                c.TaxOffice,
                c.TaxNumber,
                c.Description,
                c.StaffId,
                c.CreatedDate,
                c.CreatedUserId,
                c.isActive,
                c.isDeleted,
                c.GroupId,
                c.RegionId,
                c.CategoryId,
                c.Code,
                c.RepresentativeId
            FROM dbo.Customer c
            WHERE c.Id = :id AND ISNULL(c.isDeleted, 0) = 0
        ", [':id' => $id]);
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function clearLastError(): void
    {
        $this->lastError = null;
    }

    private function safeFetchAll(string $sql, array $params = []): array
    {
        try {
            $this->lastError = null;
            return $this->connection->fetchAll($sql, $params);
        } catch (Throwable $exception) {
            $this->lastError = $exception->getMessage();
            error_log('[Bilnex CRM] SQL Server Customer read failed: ' . $exception->getMessage());
            return [];
        }
    }

    private function safeFetchOne(string $sql, array $params = []): ?array
    {
        try {
            $this->lastError = null;
            return $this->connection->fetchOne($sql, $params);
        } catch (Throwable $exception) {
            $this->lastError = $exception->getMessage();
            error_log('[Bilnex CRM] SQL Server Customer read failed: ' . $exception->getMessage());
            return null;
        }
    }
}
