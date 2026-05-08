# CRM Web Uygulaması

Bu klasör mevcut PHP CRM uygulamasının monorepo içindeki uygulama alanıdır.
Çalışan CRM dosyaları davranış değiştirilmeden bu klasöre kopyalandı.

## Yerel Çalıştırma

```powershell
cd apps\crm-web
.\start-local.ps1
```

Varsayılan yerel adres:

```text
http://127.0.0.1:8001/index.php?page=login
```

## SQL Server Customer Okuma Modu

Bayi/Firma ekranını SQL Server `dbo.Customer` kaynağından sadece okuma modunda test etmek için ortam değişkenleri verilmelidir:

```powershell
$env:CRM_COMPANY_SOURCE = "sqlserver"
$env:BILNEX_SQL_SERVER = "localhost\BILNEXSQLCRM"
$env:BILNEX_SQL_DATABASE = "BILNEXCRMDB"
$env:BILNEX_SQL_USERNAME = "..."
$env:BILNEX_SQL_PASSWORD = "..."
.\start-local.ps1
```

Bu modda Bayi/Firma listesi SQL Server Customer kayıtlarını gösterir ve satır aksiyonları sadece okuma olarak kalır.

## Customer + Address Yazma Testi

Kalıcı kayıt bırakmayan rollback testi:

```powershell
$env:BILNEX_SQL_WRITE_TEST = "rollback"
php .\scripts\test-customer-address-write-rollback.php
```

Test, transaction içinde `dbo.Customer` ve `dbo.Address` kaydı oluşturur, ilişkiyi doğrular ve sonunda rollback yapar.

## SQL Customer Id İlişkisi

CRM tarafında firma kartına `sql_customer_id` alanı eklendi. Bu değer:

- görüşme kayıtlarına,
- satış fırsatlarına,
- opsiyonel ilgili firma seçilen görevlere

otomatik taşınır.

Kontrol testi:

```powershell
php .\scripts\test-sql-customer-relations.php
```

Test, geçici bir firma kartı oluşturur, aynı SQL Customer Id ile görüşme/fırsat/görev kaydı oluşturur ve transaction rollback ile kalıcı test verisi bırakmaz.

## Kapsam

- Dashboard
- Kullanıcılar
- Bayi / Firma
- Takip listesi
- Satış fırsatları
- Raporlar

## Not

Bu adım Bilnex SQL veritabanında değişiklik yapmaz. Mevcut SQLite tabanlı CRM davranışını monorepo uygulama klasöründe korumak için yapılmıştır.
