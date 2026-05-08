<?php

final class CustomerWriteRepository
{
    private ?PDO $pdo = null;

    public function __construct(private array $config)
    {
    }

    public function createCustomer(array $data): array
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
            $code = $this->nextCustomerCode($pdo);
            $cityCode = $this->resolveCityCode($pdo, (string) ($data['city'] ?? ''));
            $districtCode = $this->resolveDistrictCode($pdo, $cityCode, (string) ($data['district'] ?? ''));
            $customerTypeId = max(1, (int) ($data['customer_type_id'] ?? 16));
            $taxType = $this->inferCustomerTaxType((string) ($data['name'] ?? ''), (string) ($data['tax_no'] ?? ''));

            $stmt = $pdo->prepare('
                INSERT INTO dbo.Customer
                    (CustomerTypeId, MainCustomerId, CustomerTaxType, Name1, Name2, TaxOffice, TaxNumber, Description, StaffId, CreatedDate, CreatedUserId, isActive, isDeleted, DeletedDate, GroupId, RegionId, CategoryId, Code, isDemoRecord, RepresentativeId)
                OUTPUT INSERTED.Id
                VALUES
                    (:CustomerTypeId, :MainCustomerId, :CustomerTaxType, :Name1, :Name2, :TaxOffice, :TaxNumber, :Description, :StaffId, GETDATE(), :CreatedUserId, :isActive, :isDeleted, NULL, NULL, NULL, NULL, :Code, :isDemoRecord, NULL)
            ');
            $stmt->execute([
                ':CustomerTypeId' => $customerTypeId,
                ':MainCustomerId' => 1,
                ':CustomerTaxType' => $taxType,
                ':Name1' => $this->clip((string) ($data['name'] ?? ''), 100),
                ':Name2' => $this->nullableClip((string) ($data['contact_person'] ?? ''), 100),
                ':TaxOffice' => null,
                ':TaxNumber' => $this->nullableClip((string) ($data['tax_no'] ?? ''), 250),
                ':Description' => $this->nullableClip((string) ($data['description'] ?? ''), 255),
                ':StaffId' => 0,
                ':CreatedUserId' => -1,
                ':isActive' => 1,
                ':isDeleted' => 0,
                ':Code' => $code,
                ':isDemoRecord' => 0,
            ]);
            $customerId = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare('
                INSERT INTO dbo.Address
                    (Guid, Address1, Address2, Country, City, Town, PostCode, Phone, EMail, Web, CustomerId, BranchName, CreatedDate, CreatedUserId, isActive, isDeleted, DeletedDate, isEInvoice)
                VALUES
                    (CONVERT(uniqueidentifier, :Guid), :Address1, NULL, :Country, :City, :Town, NULL, :Phone, :EMail, NULL, :CustomerId, :BranchName, GETDATE(), :CreatedUserId, :isActive, :isDeleted, NULL, :isEInvoice)
            ');
            $stmt->execute([
                ':Guid' => $this->newGuid(),
                ':Address1' => $this->nullableClip((string) ($data['address'] ?? ''), 100),
                ':Country' => 'TR',
                ':City' => $this->nullableClip($cityCode, 25),
                ':Town' => $this->nullableClip($districtCode, 25),
                ':Phone' => $this->nullableClip((string) ($data['phone'] ?? ''), 250),
                ':EMail' => $this->nullableClip((string) ($data['email'] ?? ''), 250),
                ':CustomerId' => $customerId,
                ':BranchName' => 'Merkez',
                ':CreatedUserId' => -1,
                ':isActive' => 1,
                ':isDeleted' => 0,
                ':isEInvoice' => 0,
            ]);

            $pdo->commit();
            return ['id' => $customerId, 'code' => $code];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function updateCustomer(int $id, array $data): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Guncellenecek SQL Customer Id gecersiz.');
        }

        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.Customer WHERE Id = :Id AND ISNULL(isDeleted, 0) = 0');
            $existsStmt->execute([':Id' => $id]);
            if ((int) $existsStmt->fetchColumn() < 1) {
                throw new RuntimeException('SQL Customer kaydi bulunamadi veya silinmis.');
            }

            $cityCode = $this->resolveCityCode($pdo, (string) ($data['city'] ?? ''));
            $districtCode = $this->resolveDistrictCode($pdo, $cityCode, (string) ($data['district'] ?? ''));
            $customerTypeId = max(1, (int) ($data['customer_type_id'] ?? 16));
            $taxType = $this->inferCustomerTaxType((string) ($data['name'] ?? ''), (string) ($data['tax_no'] ?? ''));

            $stmt = $pdo->prepare('
                UPDATE dbo.Customer
                SET
                    CustomerTypeId = :CustomerTypeId,
                    CustomerTaxType = :CustomerTaxType,
                    Name1 = :Name1,
                    Name2 = :Name2,
                    TaxNumber = :TaxNumber,
                    Description = :Description
                WHERE Id = :Id AND ISNULL(isDeleted, 0) = 0
            ');
            $stmt->execute([
                ':CustomerTypeId' => $customerTypeId,
                ':CustomerTaxType' => $taxType,
                ':Name1' => $this->clip((string) ($data['name'] ?? ''), 100),
                ':Name2' => $this->nullableClip((string) ($data['contact_person'] ?? ''), 100),
                ':TaxNumber' => $this->nullableClip((string) ($data['tax_no'] ?? ''), 250),
                ':Description' => $this->nullableClip((string) ($data['description'] ?? ''), 255),
                ':Id' => $id,
            ]);

            $addressIdStmt = $pdo->prepare('
                SELECT TOP 1 Id
                FROM dbo.Address
                WHERE CustomerId = :CustomerId AND ISNULL(isDeleted, 0) = 0
                ORDER BY CASE WHEN ISNULL(isActive, 0) = 1 THEN 0 ELSE 1 END, Id
            ');
            $addressIdStmt->execute([':CustomerId' => $id]);
            $addressId = (int) ($addressIdStmt->fetchColumn() ?: 0);

            $addressData = [
                ':Address1' => $this->nullableClip((string) ($data['address'] ?? ''), 100),
                ':Country' => 'TR',
                ':City' => $this->nullableClip($cityCode, 25),
                ':Town' => $this->nullableClip($districtCode, 25),
                ':Phone' => $this->nullableClip((string) ($data['phone'] ?? ''), 250),
                ':EMail' => $this->nullableClip((string) ($data['email'] ?? ''), 250),
            ];

            if ($addressId > 0) {
                $stmt = $pdo->prepare('
                    UPDATE dbo.Address
                    SET
                        Address1 = :Address1,
                        Country = :Country,
                        City = :City,
                        Town = :Town,
                        Phone = :Phone,
                        EMail = :EMail,
                        isActive = 1,
                        isDeleted = 0,
                        DeletedDate = NULL
                    WHERE Id = :AddressId
                ');
                $stmt->execute($addressData + [':AddressId' => $addressId]);
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO dbo.Address
                        (Guid, Address1, Address2, Country, City, Town, PostCode, Phone, EMail, Web, CustomerId, BranchName, CreatedDate, CreatedUserId, isActive, isDeleted, DeletedDate, isEInvoice)
                    VALUES
                        (CONVERT(uniqueidentifier, :Guid), :Address1, NULL, :Country, :City, :Town, NULL, :Phone, :EMail, NULL, :CustomerId, :BranchName, GETDATE(), :CreatedUserId, :isActive, :isDeleted, NULL, :isEInvoice)
                ');
                $stmt->execute($addressData + [
                    ':Guid' => $this->newGuid(),
                    ':CustomerId' => $id,
                    ':BranchName' => 'Merkez',
                    ':CreatedUserId' => -1,
                    ':isActive' => 1,
                    ':isDeleted' => 0,
                    ':isEInvoice' => 0,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        if (!extension_loaded('pdo_sqlsrv')) {
            throw new RuntimeException('pdo_sqlsrv PHP eklentisi yuklu degil. SQL Server yazma icin driver gerekir.');
        }

        $database = (string) ($this->config['database'] ?? '');
        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');
        $trustCertificate = !empty($this->config['trust_server_certificate']) ? 'yes' : 'no';
        $loginTimeout = max(1, min((int) ($this->config['login_timeout'] ?? 5), 30));
        $lastException = null;

        foreach ($this->serverCandidates() as $server) {
            $dsn = sprintf(
                'sqlsrv:Server=%s;Database=%s;TrustServerCertificate=%s;LoginTimeout=%d',
                $server,
                $database,
                $trustCertificate,
                $loginTimeout
            );

            try {
                $this->pdo = new PDO($dsn, $username, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                return $this->pdo;
            } catch (PDOException $exception) {
                $lastException = $exception;
            }
        }

        throw new RuntimeException('SQL Server yazma baglantisi kurulamadi.', 0, $lastException);
    }

    private function serverCandidates(): array
    {
        $servers = $this->config['server_candidates'] ?? [$this->config['server'] ?? ''];
        if (!is_array($servers)) {
            $servers = [$servers];
        }

        $result = [];
        foreach ($servers as $server) {
            $server = trim((string) $server);
            if ($server !== '' && !in_array($server, $result, true)) {
                $result[] = $server;
            }
        }

        return $result ?: [''];
    }

    private function nextCustomerCode(PDO $pdo): string
    {
        $stmt = $pdo->query("
            SELECT MAX(TRY_CONVERT(int, RIGHT(Code, CHARINDEX('-', REVERSE(Code) + '-') - 1))) AS max_suffix
            FROM dbo.Customer WITH (UPDLOCK, HOLDLOCK)
            WHERE Code LIKE '120-CRM-%'
        ");
        $suffix = (int) ($stmt->fetchColumn() ?: 0);
        return '120-CRM-' . ($suffix + 1);
    }

    private function resolveCityCode(PDO $pdo, string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $stmt = $pdo->prepare('SELECT TOP 1 Code FROM dbo.City WHERE Code = :code_value OR Name = :name_value ORDER BY CASE WHEN Code = :order_value THEN 0 ELSE 1 END');
        $stmt->execute([':code_value' => $value, ':name_value' => $value, ':order_value' => $value]);
        return (string) ($stmt->fetchColumn() ?: $value);
    }

    private function resolveDistrictCode(PDO $pdo, string $cityCode, string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if ($cityCode !== '') {
            $stmt = $pdo->prepare('SELECT TOP 1 Code FROM dbo.District WHERE CityCode = :city AND (Code = :code_value OR Name = :name_value) ORDER BY CASE WHEN Code = :order_value THEN 0 ELSE 1 END');
            $stmt->execute([':city' => $cityCode, ':code_value' => $value, ':name_value' => $value, ':order_value' => $value]);
            $code = $stmt->fetchColumn();
            if ($code) {
                return (string) $code;
            }
        }

        $stmt = $pdo->prepare('SELECT TOP 1 Code FROM dbo.District WHERE Code = :code_value OR Name = :name_value ORDER BY CASE WHEN Code = :order_value THEN 0 ELSE 1 END');
        $stmt->execute([':code_value' => $value, ':name_value' => $value, ':order_value' => $value]);
        return (string) ($stmt->fetchColumn() ?: $value);
    }

    private function inferCustomerTaxType(string $name, string $taxNo): int
    {
        $normalized = strtoupper($name . ' ' . $taxNo);
        if (preg_match('/\\b(LTD|LIMITED|ANONIM|A\\.S|AŞ|SANAYI|TICARET|SIRKET|ŞIRKET|ŞİRKET)\\b/iu', $normalized)) {
            return 1;
        }

        $digits = preg_replace('/\\D+/', '', $taxNo) ?? '';
        return strlen($digits) === 10 ? 1 : 0;
    }

    private function nullableClip(string $value, int $length): ?string
    {
        $value = $this->clip($value, $length);
        return $value === '' ? null : $value;
    }

    private function clip(string $value, int $length): string
    {
        $value = trim($value);
        return substr($value, 0, $length);
    }

    private function newGuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
