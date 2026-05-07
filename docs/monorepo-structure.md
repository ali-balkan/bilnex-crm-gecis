# Monorepo Klasör Yapısı

Bu yapı, mevcut CRM'i davranış değiştirmeden parçalara ayırmak ve sadece `Customer` entegrasyonunu SQL Server mimarisine uyumlu hale getirmek için hazırlanmıştır.

Bu adımda Bilnex SQL Server üzerinde herhangi bir tablo, kayıt veya şema değişikliği yapılmaz.

## Hedef Yapı

```text
apps/
  crm-web/
    public/
    app/

packages/
  database/
  shared/

docs/
scripts/
```

## Klasörlerin Rolü

### `apps/crm-web`

Mevcut PHP CRM uygulamasının taşınacağı ana uygulama klasörüdür. İlk taşıma aşamasında davranış değişmeyecek.

### `packages/database`

Veritabanı bağlantıları ve repository katmanı burada tutulacak.

Kapsam:

- SQLite geçici/geri dönüş bağlantısı
- SQL Server bağlantı yapılandırması
- `CustomerRepository`
- `AddressRepository`
- Customer okuma/yazma eşleştirme katmanı

Bu paketin ilk SQL Server kapsamı yalnızca Bilnex `Customer` tarafıdır.

### `packages/shared`

Ortak sabitler ve yardımcılar burada tutulacak.

Kapsam:

- CustomerTypeId eşleştirmeleri
- CRM rol sabitleri
- ortak formatlama ve validasyon yardımcıları

## Entegrasyon Sınırı

Bu geçişte Bilnex SQL tarafında sadece şu yapı hedef alınır:

- `dbo.Customer`
- `dbo.Address`
- `dbo.CustomerType`
- gerektiğinde yetkili kişi için `dbo.CustomerRep`

Bilnex SQL şeması değiştirilmeyecek.

CRM'e özel satış fırsatı, görüşme ve görev yapıları ayrı değerlendirilecek; Bilnex ana tablolarına gereksiz alan veya tablo eklenmeyecek.
