# Database Paketi

CRM'in veritabanı erişim katmanı burada toplanacaktır.

## İlk Hedef

Bilnex SQL Server mimarisine uygun şekilde yalnız `Customer` entegrasyonu yapılacak.

## Hedef Repository Sınıfları

- `CustomerRepository`
- `AddressRepository`
- `CustomerTypeRepository`

## SQL Server Kapsamı

- `dbo.Customer`
- `dbo.Address`
- `dbo.CustomerType`
- gerektiğinde `dbo.CustomerRep`

Bilnex SQL Server üzerinde bu aşamada tablo, alan veya veri değişikliği yapılmayacaktır.
