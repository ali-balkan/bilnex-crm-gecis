# Customer Varsayılan Alan Kararları

Tarih: 07.05.2026

Bu karar dokümanı sadece CRM'in Bilnex SQL Server yapısına uygun çalışması için varsayılan alan kararlarını kaydeder. Veritabanında herhangi bir değişiklik yapılmamıştır.

## Okuma Bulguları

`BILNEXCRMDB.dbo.Customer` üzerinde yalnızca okuma sorguları çalıştırıldı.

- `MainCustomerId` en yaygın değer: `1`
- `StaffId` kayıtların neredeyse tamamında: `0`
- `RepresentativeId` kayıtların neredeyse tamamında: `NULL`
- `CustomerTaxType` hem `0` hem `1` olarak kullanılıyor; örneklerde şahıs ağırlıklı kayıtlarda `0`, şirket/kurum ağırlıklı kayıtlarda `1` görülüyor.

## Kesinleşen Varsayılanlar

| Alan | Varsayılan / Kural | Not |
| --- | --- | --- |
| `MainCustomerId` | `1` | Yeni bağımsız CRM kayıtlarında ana müşteri bağlantısı olarak kullanılacak. |
| `StaffId` | `0` | Bilnex mevcut kullanımına uygun varsayılan. |
| `RepresentativeId` | `NULL` | Temsilci atanmadıysa boş bırakılacak. |
| `CustomerTaxType` | Kural bazlı | Şahıs ise `0`, kurum/şirket ise `1`. Emin olunamayan kayıtta varsayılan `0`. |

## CustomerTaxType Kuralı

- Ünvan veya vergi bilgisi şirket/kurum olduğunu gösteriyorsa `CustomerTaxType = 1`.
- Kişi adı/şahıs benzeri kayıtlarda `CustomerTaxType = 0`.
- Belirsiz veya eksik bilgili kayıtta güvenli varsayılan `0`.

## Uygulama Notu

- Bu kararlar yazma aşamasında kullanılacak varsayılanlardır.
- Bu aşamada `dbo.Customer`, `dbo.Address` veya başka SQL Server tablosuna yazma/güncelleme yapılmadı.
- Canlı yazma açılmadan önce test kaydı ile Bilnex uygulamasında görünüm kontrolü yapılacak.
