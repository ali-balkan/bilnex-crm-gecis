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
            $searchWhere = ' AND (c.Name1 LIKE :query_name OR c.Name2 LIKE :query_contact OR c.Code LIKE :query_code OR c.TaxNumber LIKE :query_tax OR addr.Phone LIKE :query_phone OR addr.EMail LIKE :query_email OR addr.CityName LIKE :query_city OR addr.CityCode LIKE :query_city_code OR addr.DistrictName LIKE :query_district OR addr.TownCode LIKE :query_town_code)';
            $params[':query_name'] = '%' . $query . '%';
            $params[':query_contact'] = '%' . $query . '%';
            $params[':query_code'] = '%' . $query . '%';
            $params[':query_tax'] = '%' . $query . '%';
            $params[':query_phone'] = '%' . $query . '%';
            $params[':query_email'] = '%' . $query . '%';
            $params[':query_city'] = '%' . $query . '%';
            $params[':query_city_code'] = '%' . $query . '%';
            $params[':query_district'] = '%' . $query . '%';
            $params[':query_town_code'] = '%' . $query . '%';
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
                c.RepresentativeId,
                addr.Phone,
                addr.EMail AS Email,
                COALESCE(addr.CityName, addr.CityCode) AS City,
                COALESCE(addr.DistrictName, addr.TownCode) AS District,
                addr.Address1,
                addr.Address2
            FROM dbo.Customer c
            {$this->addressApplySql()}
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
        $addressApply = '';
        $query = trim($query);
        if ($query !== '') {
            $addressApply = $this->addressApplySql();
            $searchWhere = ' AND (c.Name1 LIKE :query_name OR c.Name2 LIKE :query_contact OR c.Code LIKE :query_code OR c.TaxNumber LIKE :query_tax OR addr.Phone LIKE :query_phone OR addr.EMail LIKE :query_email OR addr.CityName LIKE :query_city OR addr.CityCode LIKE :query_city_code OR addr.DistrictName LIKE :query_district OR addr.TownCode LIKE :query_town_code)';
            $params[':query_name'] = '%' . $query . '%';
            $params[':query_contact'] = '%' . $query . '%';
            $params[':query_code'] = '%' . $query . '%';
            $params[':query_tax'] = '%' . $query . '%';
            $params[':query_phone'] = '%' . $query . '%';
            $params[':query_email'] = '%' . $query . '%';
            $params[':query_city'] = '%' . $query . '%';
            $params[':query_city_code'] = '%' . $query . '%';
            $params[':query_district'] = '%' . $query . '%';
            $params[':query_town_code'] = '%' . $query . '%';
        }
        $row = $this->safeFetchOne("
            SELECT COUNT(*) AS total
            FROM dbo.Customer c
            {$addressApply}
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
                c.RepresentativeId,
                addr.Phone,
                addr.EMail AS Email,
                COALESCE(addr.CityName, addr.CityCode) AS City,
                COALESCE(addr.DistrictName, addr.TownCode) AS District,
                addr.Address1,
                addr.Address2
            FROM dbo.Customer c
            {$this->addressApplySql()}
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

    public function contactCryptoMaterial(): ?array
    {
        static $material = null;
        if ($material !== null) {
            return $material ?: null;
        }

        $previousError = $this->lastError;
        $row = $this->safeFetchOne("SELECT OBJECT_DEFINITION(OBJECT_ID('dbo.fn_DecryptAes256')) AS DefinitionText");
        $this->lastError = $previousError;

        $definition = (string) ($row['DefinitionText'] ?? '');
        if (
            preg_match('/@Key\s+VARBINARY\(\d+\)\s*=\s*0x([0-9a-f]+)/i', $definition, $keyMatch)
            && preg_match('/@IV\s+VARBINARY\(\d+\)\s*=\s*0x([0-9a-f]+)/i', $definition, $ivMatch)
        ) {
            $material = [
                'key_hex' => $keyMatch[1],
                'iv_hex' => $ivMatch[1],
            ];
            return $material;
        }

        $material = false;
        return null;
    }

    private function addressApplySql(): string
    {
        return "
            OUTER APPLY (
                SELECT TOP 1
                    a.Phone,
                    a.EMail,
                    a.City AS CityCode,
                    a.Town AS TownCode,
                    a.Address1,
                    a.Address2,
                    city.Name AS CityName,
                    district.Name AS DistrictName
                FROM dbo.Address a
                LEFT JOIN dbo.City city ON city.Code = a.City
                LEFT JOIN dbo.District district ON district.CityCode = a.City AND district.Code = a.Town
                WHERE a.CustomerId = c.Id AND ISNULL(a.isDeleted, 0) = 0
                ORDER BY CASE WHEN ISNULL(a.isActive, 0) = 1 THEN 0 ELSE 1 END, a.Id
            ) addr
        ";
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
