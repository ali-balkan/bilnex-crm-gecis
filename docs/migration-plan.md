# Bilnex CRM Geçiş Planı

Bu repo, mevcut SQLite tabanlı CRM'in monorepo yapıya alınması ve SQL Server üzerindeki `BILNEXCRMDB` tablolarıyla çalışması için açıldı.

## Hedef

- Mevcut CRM davranışını bozmadan monorepo yapıya taşımak.
- Bayi/Firma kayıtlarını SQLite `companies` tablosu yerine SQL Server `dbo.Customer` ve `dbo.Address` tablolarına bağlamak.
- Cari türlerini `dbo.CustomerType` üzerinden yönetmek.
- Geliştirmeyi GitHub üzerinden ortak yürütmek.

## 3 Günlük Plan

### 1. Gün - 07.05.2026

- Yerel ve canlı yedeklerin alınması.
- GitHub reposunun hazırlanması.
- Karar bekleyen veri alanlarının netleştirilmesi.
- Monorepo klasör yapısının hazırlanması.
- Mevcut CRM'in davranış değişmeden yeni yapıya taşınması.

### 2. Gün - 08.05.2026

- SQL Server bağlantı katmanının eklenmesi.
- `Customer`, `Address`, `CustomerType` için repository yapısının kurulması.
- Bayi/Firma ekranının önce sadece okuma modunda SQL Server verisiyle denenmesi.

### 3. Gün - 09.05.2026

- Test ortamında `Customer` + `Address` yazma akışının denenmesi.
- Satış fırsatı, görev ve görüşme kayıtlarının SQL Customer Id ile ilişkilendirilmesi.
- Mobil, yetki, rapor ve canlı geçiş kontrollerinin yapılması.

## Çalışma İlkesi

- Mevcut canlı CRM'e doğrudan riskli değişiklik yapılmayacak.
- Önce yedek, sonra ayrı yapı, sonra okuma modu, en son kontrollü yazma yapılacak.
- Tamamlanan adımlar geçiş takip sayfasında Codex tarafından işaretlenecek.
