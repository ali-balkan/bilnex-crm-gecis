# SQL Server Veri Eşleştirme Notları

Hedef database: `BILNEXCRMDB`

## Ana Tablolar

- `dbo.Customer`: Cari/müşteri ana kartı.
- `dbo.Address`: Telefon, e-posta, adres bilgileri. `CustomerId` ile bağlanır.
- `dbo.CustomerType`: Cari/müşteri tipi.
- `dbo.CustomerRep`: Yetkili kişi veya ek iletişim kişisi için değerlendirilecek tablo.
- `dbo.User`: Bilnex CRM kullanıcıları.
- `dbo.Task` / `dbo.TaskBilnex`: Bilnex görev tabloları. Şu an doğrudan kullanım kararı verilmedi.

## Customer Alanları

| CRM Alanı | SQL Server Alanı | Not |
| --- | --- | --- |
| Firma adı | `dbo.Customer.Name1` | Ana cari adı. |
| Yetkili kişi | `dbo.Customer.Name2` veya `dbo.CustomerRep.Name` | Kesin karar verilecek. |
| Cari kodu | `dbo.Customer.Code` | Örnek: `120-CRM-13918`. |
| Cari türü | `dbo.Customer.CustomerTypeId` | İş ortağı / son kullanıcı ayrımı buradan gelecek. |
| Vergi no | `dbo.Customer.TaxNumber` | Hassas veri, testte maskelenmeli. |
| Açıklama | `dbo.Customer.Description` | 255 karakter sınırı var. |
| Aktiflik | `dbo.Customer.isActive`, `dbo.Customer.isDeleted` | Silme yerine pasifleştirme değerlendirilecek. |
| Telefon | `dbo.Address.Phone` | `CustomerId` ile bağlı. |
| E-posta | `dbo.Address.EMail` | `CustomerId` ile bağlı. |
| İl | `dbo.Address.City` | Metin alanı. |
| İlçe | `dbo.Address.Town` | Metin alanı. |
| Adres | `dbo.Address.Address1`, `dbo.Address.Address2` | Adres Customer içinde değil. |

## CustomerType Kritik Değerler

| CustomerTypeId | Ad | Not |
| --- | --- | --- |
| 7 | İş Ortakları | Ana iş ortağı tipi. |
| 14 | Hedef Bayi | Yeni bayi adayı için güçlü aday. |
| 15 | Müşteriler | Ana müşteri grubu. |
| 16 | Müşteri | Aktif son kullanıcı için güçlü aday. |
| 17 | Hedef Müşteri | Yeni son kullanıcı adayı için güçlü aday. |
| 18 | Demo Müşteri | Demo süreçleri için değerlendirilecek. |

## Karar Bekleyen Alanlar

- Yeni iş ortağı adayı `7` mi, `14` mü açılacak?
- Yeni son kullanıcı adayı `16`, `17` veya `18` mi olacak?
- `MainCustomerId` varsayılanı ne olacak?
- `StaffId` ve `RepresentativeId` hangi kullanıcıya bağlanacak?
- Yetkili kişi `Name2` alanına mı, `CustomerRep` tablosuna mı yazılacak?
- Telefon/e-posta sadece `Address` tablosunda mı tutulacak, yoksa `CustomerRep` de kullanılacak mı?
