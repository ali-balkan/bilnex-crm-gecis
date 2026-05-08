$ErrorActionPreference = "Stop"

$server = $env:BILNEX_SQL_SERVER
if (-not $server) { $server = "localhost\BILNEXSQLCRM" }
$database = $env:BILNEX_SQL_DATABASE
if (-not $database) { $database = "BILNEXCRMDB" }
$username = $env:BILNEX_SQL_USERNAME
$password = $env:BILNEX_SQL_PASSWORD

if (-not $username -or -not $password) {
    throw "BILNEX_SQL_USERNAME ve BILNEX_SQL_PASSWORD ortam degiskenleri gereklidir."
}

if ($env:BILNEX_SQL_WRITE_TEST -ne "rollback") {
    throw "Kalici yazmayi onlemek icin BILNEX_SQL_WRITE_TEST=rollback gereklidir."
}

$connectionString = "Server=$server;Database=$database;User ID=$username;Password=$password;Encrypt=False;TrustServerCertificate=True;Connection Timeout=10;"
$connection = [System.Data.SqlClient.SqlConnection]::new($connectionString)
$connection.Open()

$transaction = $connection.BeginTransaction()
$customerId = -1 * [Math]::Abs([int](Get-Date -Format "HHmmssfff"))
$addressId = $customerId
$code = "CRM-TST-" + [Math]::Abs($customerId)
$name = "CRM Rollback Test Cari " + (Get-Date -Format "yyyyMMddHHmmss")
$addressGuid = [Guid]::NewGuid()

function New-Command([string]$Sql) {
    $cmd = $connection.CreateCommand()
    $cmd.Transaction = $transaction
    $cmd.CommandText = $Sql
    return $cmd
}

try {
    $cmd = New-Command @"
SET IDENTITY_INSERT dbo.Customer ON;
INSERT INTO dbo.Customer
    (Id, CustomerTypeId, MainCustomerId, CustomerTaxType, Name1, Name2, TaxOffice, TaxNumber, Description, StaffId, CreatedDate, CreatedUserId, isActive, isDeleted, GroupId, RegionId, CategoryId, Code, isDemoRecord, RepresentativeId)
VALUES
    (@Id, @CustomerTypeId, @MainCustomerId, @CustomerTaxType, @Name1, @Name2, @TaxOffice, @TaxNumber, @Description, @StaffId, GETDATE(), @CreatedUserId, @isActive, @isDeleted, @GroupId, @RegionId, @CategoryId, @Code, @isDemoRecord, @RepresentativeId);
SET IDENTITY_INSERT dbo.Customer OFF;
"@
    [void]$cmd.Parameters.AddWithValue("@Id", $customerId)
    [void]$cmd.Parameters.AddWithValue("@CustomerTypeId", 14)
    [void]$cmd.Parameters.AddWithValue("@MainCustomerId", 1)
    [void]$cmd.Parameters.AddWithValue("@CustomerTaxType", 1)
    [void]$cmd.Parameters.AddWithValue("@Name1", $name)
    [void]$cmd.Parameters.AddWithValue("@Name2", "Rollback Yetkili")
    [void]$cmd.Parameters.AddWithValue("@TaxOffice", "TEST")
    [void]$cmd.Parameters.AddWithValue("@TaxNumber", "1111111111")
    [void]$cmd.Parameters.AddWithValue("@Description", "CRM Customer + Address rollback yazma testi")
    [void]$cmd.Parameters.AddWithValue("@StaffId", 0)
    [void]$cmd.Parameters.AddWithValue("@CreatedUserId", 0)
    [void]$cmd.Parameters.AddWithValue("@isActive", 1)
    [void]$cmd.Parameters.AddWithValue("@isDeleted", 0)
    [void]$cmd.Parameters.AddWithValue("@GroupId", [DBNull]::Value)
    [void]$cmd.Parameters.AddWithValue("@RegionId", [DBNull]::Value)
    [void]$cmd.Parameters.AddWithValue("@CategoryId", [DBNull]::Value)
    [void]$cmd.Parameters.AddWithValue("@Code", $code)
    [void]$cmd.Parameters.AddWithValue("@isDemoRecord", 0)
    [void]$cmd.Parameters.AddWithValue("@RepresentativeId", [DBNull]::Value)
    [void]$cmd.ExecuteNonQuery()

    $cmd = New-Command @"
SET IDENTITY_INSERT dbo.Address ON;
INSERT INTO dbo.Address
    (Id, Guid, Address1, Address2, Country, City, Town, PostCode, Phone, EMail, Web, CustomerId, BranchName, CreatedDate, CreatedUserId, isActive, isDeleted, DeletedDate, isEInvoice)
VALUES
    (@Id, @Guid, @Address1, @Address2, @Country, @City, @Town, @PostCode, @Phone, @EMail, @Web, @CustomerId, @BranchName, GETDATE(), @CreatedUserId, @isActive, @isDeleted, @DeletedDate, @isEInvoice);
SET IDENTITY_INSERT dbo.Address OFF;
"@
    [void]$cmd.Parameters.AddWithValue("@Id", $addressId)
    [void]$cmd.Parameters.AddWithValue("@Guid", $addressGuid)
    [void]$cmd.Parameters.AddWithValue("@Address1", "Rollback test adresi")
    [void]$cmd.Parameters.AddWithValue("@Address2", [DBNull]::Value)
    [void]$cmd.Parameters.AddWithValue("@Country", "Türkiye")
    [void]$cmd.Parameters.AddWithValue("@City", "İstanbul")
    [void]$cmd.Parameters.AddWithValue("@Town", "Kadıköy")
    [void]$cmd.Parameters.AddWithValue("@PostCode", "34000")
    [void]$cmd.Parameters.AddWithValue("@Phone", "02120000000")
    [void]$cmd.Parameters.AddWithValue("@EMail", "rollback-test@example.test")
    [void]$cmd.Parameters.AddWithValue("@Web", [DBNull]::Value)
    [void]$cmd.Parameters.AddWithValue("@CustomerId", $customerId)
    [void]$cmd.Parameters.AddWithValue("@BranchName", "Merkez")
    [void]$cmd.Parameters.AddWithValue("@CreatedUserId", 0)
    [void]$cmd.Parameters.AddWithValue("@isActive", 1)
    [void]$cmd.Parameters.AddWithValue("@isDeleted", 0)
    [void]$cmd.Parameters.AddWithValue("@DeletedDate", [DBNull]::Value)
    [void]$cmd.Parameters.AddWithValue("@isEInvoice", 0)
    [void]$cmd.ExecuteNonQuery()

    $cmd = New-Command "SELECT COUNT(*) FROM dbo.Customer c JOIN dbo.Address a ON a.CustomerId = c.Id WHERE c.Id = @Id AND c.Code = @Code;"
    [void]$cmd.Parameters.AddWithValue("@Id", $customerId)
    [void]$cmd.Parameters.AddWithValue("@Code", $code)
    $insideCount = [int]$cmd.ExecuteScalar()
    if ($insideCount -ne 1) {
        throw "Transaction icinde Customer + Address dogrulanamadi."
    }

    $transaction.Rollback()

    $cmd = $connection.CreateCommand()
    $cmd.CommandText = "SELECT COUNT(*) FROM dbo.Customer WHERE Id = @Id OR Code = @Code;"
    [void]$cmd.Parameters.AddWithValue("@Id", $customerId)
    [void]$cmd.Parameters.AddWithValue("@Code", $code)
    $afterCustomerCount = [int]$cmd.ExecuteScalar()

    $cmd = $connection.CreateCommand()
    $cmd.CommandText = "SELECT COUNT(*) FROM dbo.Address WHERE Id = @Id OR CustomerId = @CustomerId;"
    [void]$cmd.Parameters.AddWithValue("@Id", $addressId)
    [void]$cmd.Parameters.AddWithValue("@CustomerId", $customerId)
    $afterAddressCount = [int]$cmd.ExecuteScalar()

    if ($afterCustomerCount -ne 0 -or $afterAddressCount -ne 0) {
        throw "Rollback sonrasi test kaydi kaldi. Customer=$afterCustomerCount Address=$afterAddressCount"
    }

    [pscustomobject]@{
        status = "ok"
        customer_id = $customerId
        address_id = $addressId
        code = $code
        inside_transaction_count = $insideCount
        after_rollback_customer_count = $afterCustomerCount
        after_rollback_address_count = $afterAddressCount
    } | ConvertTo-Json -Compress
} catch {
    try {
        $transaction.Rollback()
    } catch {
    }
    throw
} finally {
    $connection.Close()
}
