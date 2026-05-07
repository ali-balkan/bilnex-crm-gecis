# CustomerTypeId Kararları

Tarih: 07.05.2026

Bu karar dokümanı sadece eşleştirme kararını kaydeder. Veritabanında herhangi bir değişiklik yapılmamıştır.

## Kesinleşen Tip Eşleştirmeleri

| CRM Kavramı | SQL Server Alanı | CustomerTypeId | Bilnex Adı |
| --- | --- | ---: | --- |
| İş ortağı | `dbo.Customer.CustomerTypeId` | 7 | İş Ortakları |
| Hedef bayi | `dbo.Customer.CustomerTypeId` | 14 | Hedef Bayi |
| Müşteri | `dbo.Customer.CustomerTypeId` | 16 | Müşteri |
| Hedef müşteri | `dbo.Customer.CustomerTypeId` | 17 | Hedef Müşteri |

## Uygulama Kuralı

- CRM'de bayi/iş ortağı olarak kesinleşmiş kayıtlar `CustomerTypeId = 7` ile yorumlanacak.
- CRM'de bayi adayı veya iş ortağı adayı olarak takip edilen kayıtlar `CustomerTypeId = 14` ile yorumlanacak.
- CRM'de aktif son kullanıcı/müşteri kayıtları `CustomerTypeId = 16` ile yorumlanacak.
- CRM'de henüz aday aşamasındaki son kullanıcı kayıtları `CustomerTypeId = 17` ile yorumlanacak.

## Notlar

- `CustomerTypeId = 18` Demo Müşteri şimdilik ana eşleştirmeye alınmadı; demo süreci ayrı karar olarak değerlendirilecek.
- Bu aşamada `dbo.Customer`, `dbo.Address` veya başka SQL Server tablosuna yazma/güncelleme yapılmadı.
- Sonraki karar maddesi: `MainCustomerId`, `StaffId`, `RepresentativeId`, `CustomerTaxType` varsayılanları.
