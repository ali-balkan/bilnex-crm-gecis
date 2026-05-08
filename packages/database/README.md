# Database Paketi

CRM'in veritabanı erişim katmanı burada toplanır.

## Eklenen Katman

- `src/ReadOnlySqlServerConnection.php`: SQL Server için sadece okuma sorgularına izin veren bağlantı katmanı.
- `src/CustomerReadRepository.php`: `dbo.Customer` verisini okumak için repository.
- `scripts/sqlserver-readonly-query.ps1`: PHP'de `pdo_sqlsrv` olmayan yerel kurulumlarda .NET SqlClient ile sadece okuma sorgusu çalıştıran köprü.
- `scripts/sqlserver-customer-address-write-rollback-test.ps1`: `dbo.Customer` + `dbo.Address` yazma alanlarını transaction içinde deneyen ve sonunda rollback yapan test scripti.

Katman yalnızca `SELECT` ve `WITH` ile başlayan sorguları kabul eder. `INSERT`, `UPDATE`, `DELETE`, `ALTER`, `DROP`, `CREATE`, `MERGE`, `TRUNCATE`, `EXEC` ve `SELECT INTO` gibi yazma veya şema değiştirme riski taşıyan sorgular kod seviyesinde reddedilir.

## CRM Entegrasyonu

`apps/crm-web/app/bootstrap.php` içinde şu yardımcılar eklendi:

- `bilnex_sql_server()`
- `bilnex_customer_reader()`

SQL Server ayarları `apps/crm-web/config.php` içindeki `sql_server` bloğundan okunur. Hassas bilgiler ortam değişkenleriyle verilebilir:

- `BILNEX_SQL_SERVER`
- `BILNEX_SQL_DATABASE`
- `BILNEX_SQL_USERNAME`
- `BILNEX_SQL_PASSWORD`
- `BILNEX_SQL_TRUST_CERTIFICATE`

## Driver Notu

Canlı bağlantı için PHP tarafında `pdo_sqlsrv` eklentisi gerekir. Eklenti yoksa katman yazma koruması ve yapı olarak hazır kalır, bağlantı çağrısında açık hata verir.
Yerel testte `dotnet_bridge` açıkken PowerShell/.NET köprüsü kullanılabilir.

## Güvenlik Notu

Bilnex SQL tarafında şema veya veri değişikliği yapılmaz. Bu paket Customer entegrasyonu için okuma katmanıdır.
Yazma testi yalnızca `BILNEX_SQL_WRITE_TEST=rollback` ortam değişkeniyle çalışır ve test kaydını transaction sonunda geri alır.
