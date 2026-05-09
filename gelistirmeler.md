# Bilnex CRM - Son 24 Saat Geliştirme ve QA Logu

Tarih: 09.05.2026  
Kapsam: Bilnex İş Ortakları CRM kullanıcı arayüzü, cariler, takip listesi, satış fırsatları, görüşmeler, raporlar, profil ayarları, yetki kontrolleri ve veri güvenliği.

## Profesyonel Kullanıcı Testi Özeti

Bu turda uygulama, gerçek kullanıcı akışları üzerinden geniş kapsamlı olarak test edildi. Testler lokal SQLite ortamında, farklı kullanıcı rolleriyle ve HTTP istekleri üzerinden yürütüldü.

### Çalıştırılan Testler

- PHP sözdizimi kontrolü: `18` PHP dosyası lint edildi, hata yok.
- `scripts/regression-test.ps1`: Login, rol erişimleri, kullanıcı oluşturma, cari oluşturma/düzenleme, cari arama, görüşme, takip, satış fırsatı, filtreler, CSRF, yetkisiz erişim ve silme koruması test edildi.
- `scripts/smoke-test.ps1`: Temel login, rol görünürlüğü, firma kartı erişimi, görüşme ve satış fırsatı oluşturma akışları test edildi.
- `scripts/test-role-visibility-records.php`: Admin, Yönetici, Bayi Kanal Yöneticisi, Bayi Kanal Uzmanı ve Saha Satış görünürlük kuralları test edildi.
- `scripts/test-dashboard-reports.ps1`: Dashboard ve raporlarda görev, görüşme, fırsat ve yetki bazlı veri görünürlüğü test edildi.
- `scripts/test-profile-settings.ps1`: Profil fotoğrafı yükleme, otomatik 256x256 küçültme, şifre değiştirme ve çıkış akışı test edildi.
- `scripts/test-mobile-permissions-reports.php`: Mobil/yetki/rapor kapsam kontrolleri test edildi.
- `scripts/test-sqlserver-readonly-layer.php`: SQL Server read-only koruma katmanı test edildi.
- `scripts/test-sql-customer-relations.php`: SQL Customer Id ilişkisinin cariler, görüşmeler, takipler ve satış fırsatlarında korunması test edildi.
- Ek rota taraması: `dashboard`, `profile`, `users`, `companies`, `company_form`, `interactions`, `task_form`, `followups`, legacy takip sayfaları, `opportunities`, `opportunity_form`, `reports`, `company_view`, `company_lookup_search`, `tax_office_search`, `sql_customer_search` sayfaları 200/HTML hata kontrolünden geçirildi.

### Test Bulguları

- Kritik kullanıcı akışlarında yeni uygulama hatası bulunmadı.
- Takip Listesi, Satış Fırsatları ve Görüşmeler için silme aksiyonlarının kapalı olduğu doğrulandı.
- Eski `delete_task` ve `delete_opportunity` endpointlerine direkt POST gelse bile kayıtların silinmediği doğrulandı.
- Dashboard ve Raporlar, yetki seviyesine göre doğru veri gösteriyor.
- Profil fotoğrafı büyük yüklense bile 256x256 JPEG formatına küçültülüyor ve dosya küçük tutuluyor.
- Yeni satış fırsatı ekranındaki cari arama 3 karakterden sonra sonuç döndürüyor ve seçilen cariyle devam ediyor.

### Çalıştırılamayan / Ortam Bağımlı Test

- Canlı SQL Server liste okuma testi (`test-companies-sqlserver-list.php`) bu lokal PHP ortamında `pdo_sqlsrv` modülü yüklü olmadığı için çalıştırılmadı. Bu test Coolify/production PHP imajında ya da `pdo_sqlsrv` yüklü bir ortamda çalıştırılmalıdır.

## Bu QA Turunda Yapılan Düzeltme Logu

- Yeni uygulama hatası bulunmadığı için bu turda ek uygulama kodu düzeltmesi yapılmadı.
- Test kapsamı doğrulandı ve son 24 saatte yapılan değişiklikler bu dosyada kayıt altına alındı.

## Son 24 Saatte Yapılan Geliştirmeler

### Altyapı ve Deploy Hazırlıkları

- Docker/PHP imajında SQL Server sürücüleri ve SQLite geliştirme paketleri düzenlendi.
- CRM data klasörünün Docker build sırasında oluşturulması sağlandı.
- SQL Server login timeout davranışı sınırlandı ve yapılandırılabilir hale getirildi.
- Coolify ortamında SQL Server host adayları ve fallback davranışı düzenlendi.
- SQL Customer okuma hataları kullanıcıya ve admin diagnostiklerine daha görünür hale getirildi.

### SQL Server ve Cari Entegrasyonu

- Bilnex SQL Customer şeması dokümante edildi.
- CRM'den açılan yeni carilerin Bilnex SQL Customer tablosuna yazılması sağlandı.
- Bilnex müşteri türleri CRM filtrelerine ve cari türü seçimlerine eklendi.
- SQL Customer Id alanı kullanıcı hatası oluşturmaması için kilitlendi.
- Yeni cari formunda cari kodu otomatik/kilitli hale getirildi.
- SQL kaynaklı carilerde düzenleme butonu eklendi ve SQL verisine düzenleme desteği sağlandı.
- SQL contact alanlarında telefon/vergi no için şifreli/bozuk görünen değerlerin gizlenmesi ve çözümlenmesi çalışıldı.

### Cariler

- Bayi/Firma menüsü Cariler olarak düzenlendi.
- Cari listesi daha modern ve verimli tablo yapısına alındı.
- Telefon, il, ilçe ve yetkili bilgileri listeye uygun şekilde yerleştirildi.
- Sonraki takip/aksiyon alanları cari listesinde kaldırıldı.
- Yeni cari formunda Kaynak, Bakiye ve B/A alanları kaldırıldı.
- Vergi no ve vergi dairesi zorunlu hale getirildi.
- Vergi dairesi seçimi için yerel GİB vergi daireleri arama/seçme alanı eklendi.

### Takip Listesi ve İş Atama

- Rol yapısı Admin, Yönetici, Bayi Kanal Yöneticisi, Bayi Kanal Uzmanı, Saha Satış olarak netleştirildi.
- Yetki görünürlüğü kuralları sıkılaştırıldı.
- Takvim odaklı takip görünümü yerine daha anlaşılır iş atama/takip paneli eklendi.
- Herkesin herkese iş atayabilmesi sağlandı.
- Atanan işlerin atayan kişi tarafından görülebilmesi sağlandı.
- Takipte cari seçimi zorunlu olmaktan çıkarıldı.
- Takiplerde cari seçimi SQL/local kaynaklı arama ile desteklendi.
- Takip tamamlandı ve düzenle akışları test edildi.
- Takip kayıtlarının silinmeyecek şekilde korunması sağlandı.

### Satış Fırsatları

- Satış fırsatları için kanban/pipeline görünümü modernleştirildi.
- Yeni satış fırsatı ekranında cari seçimi geliştirildi.
- Cari yoksa tek tuşla cari ekleyip satış fırsatı formuna seçili dönme akışı eklendi.
- Cari arama 3 karakterden sonra otomatik arama yapacak ve en ilgili sonuçları listeleyecek şekilde düzeltildi.
- Fırsat görünürlüğü rol bazlı test edildi.
- Satış fırsatı kayıtlarının silinmeyecek şekilde korunması sağlandı.

### Görüşmeler

- Ana menüye Görüşme ekle alanı eklendi.
- Görüşme ekleme sayfası oluşturuldu.
- Cari seçimi için hızlı arama desteklendi.
- Eski görüşmeler listelenebilir hale getirildi.
- Bugün, bu hafta, bu ay filtreleri eklendi.
- Dashboard ve raporlarda görüşmelerin yetkiye göre görünmesi doğrulandı.
- Görüşme kayıtlarının silinmeyecek şekilde korunması test edildi.

### Dashboard

- Dashboard görsel olarak modern, kartlı ve grafik odaklı yapıya alındı.
- Tıklanabilir veri kartları eklendi.
- Bayi/cari tür dağılımı grafik ve listeyle gösterildi.
- Satış pipeline, yaklaşan görevler, geciken görevler, açık fırsatlar ve hatırlatmalar alanları eklendi.
- Dashboard responsive davranışı stabilize edildi.
- Bilnex logosu tüm gerekli alanlarda SVG olarak kullanıldı.

### Raporlar ve PDF

- Raporlar sayfası dashboard verileriyle dolduruldu.
- Grafik ve rapor alanları geliştirildi.
- PDF çıktısı A4 yatay tek sayfaya sığacak profesyonel rapor tasarımına dönüştürüldü.
- Yazdırma görünümü için KPI, grafik, funnel, tablo ve özet alanları eklendi.

### Profil ve Bildirimler

- Sol alt kullanıcı profil alanı profil fotoğrafı ile gösterilecek hale getirildi.
- Profil ayarları sayfası eklendi.
- Profil fotoğrafı yükleme, şifre değiştirme ve çıkış alanları tasarlandı.
- Profil fotoğrafı boyutu ne olursa olsun 256x256 JPEG olarak küçültülüyor.
- Sağ üst bildirim zili ve iş atama bildirim merkezi eklendi.
- Görünen bildirimlerin süreli temizlenmesi için bildirim davranışı geliştirildi.

### Giriş Ekranı

- Ana giriş ekranı Bilnex markasına daha uygun kurumsal tasarıma alındı.
- Sol tarafta marka/CRM anlatımı, sağ tarafta temiz giriş paneli olacak şekilde responsive yapı kuruldu.

### Veri Güvenliği

- Takip Listesi, Satış Fırsatları ve Görüşmeler için silme aksiyonları kapatıldı.
- Eski silme endpointleri kayıt silmeyecek şekilde güvenli hale getirildi.
- Yeni kurulumlarda görüşme ve satış fırsatı kayıtlarının cari silinince cascade ile düşmesini engellemek için ilişki `RESTRICT` yapıldı.
- Reset/import scripti, takip/görüşme/satış fırsatı kaydı varken çalışmayı durduracak şekilde korumaya alındı.

## Son 24 Saat Commit Listesi

- `23551f7` 09.05.2026 04:08 - Protect activity records from deletion
- `00b0ef5` 09.05.2026 03:55 - Redesign login screen
- `fb9c197` 09.05.2026 03:46 - Improve opportunity company lookup
- `10ec061` 09.05.2026 03:35 - Require tax office for company records
- `7cf92a3` 09.05.2026 03:06 - Add interaction entry workspace
- `788a030` 09.05.2026 02:52 - Improve printable reports dashboard
- `52f57b7` 09.05.2026 02:28 - Resize profile photos on upload
- `7be6e3c` 09.05.2026 02:21 - Fix dashboard and report data visibility
- `7b90b9b` 09.05.2026 02:08 - Add profile settings and tighten role visibility
- `0624e02` 09.05.2026 01:19 - Add SQL company editing from list
- `4023539` 09.05.2026 00:58 - Lock company code on new company form
- `7e328f2` 09.05.2026 00:55 - Lock SQL customer id on company form
- `4d71ac3` 09.05.2026 00:50 - Decrypt SQL contact fields
- `bc7e791` 09.05.2026 00:40 - Hide encoded SQL contact fields
- `b035770` 09.05.2026 00:34 - Improve company list layout and SQL contact fields
- `0e2c3d3` 09.05.2026 00:22 - Stabilize responsive dashboard layout
- `5e03a01` 09.05.2026 00:11 - Use provided Bilnex SVG logo
- `7d19d2f` 09.05.2026 00:08 - Restyle dashboard overview
- `9d85fb8` 09.05.2026 00:03 - Return new customers to opportunity form
- `c5c54fe` 08.05.2026 23:58 - Clarify optional followup customer selection
- `f2f506b` 08.05.2026 23:57 - Add SQL customer lookup for followups
- `62ffa65` 08.05.2026 23:34 - Revise task roles and followups UI
- `863bc0c` 08.05.2026 23:20 - Improve reports PDF print layout
- `b08b252` 08.05.2026 23:16 - Modernize CRM frontend experience
- `2e6b4e8` 08.05.2026 22:59 - Simplify cariler creation flow
- `ab9e3ff` 08.05.2026 22:47 - Hide company status in SQL customer mode
- `1052276` 08.05.2026 22:39 - Write new CRM cariler to Bilnex SQL
- `de07563` 08.05.2026 22:30 - Expose Bilnex customer types in CRM filters
- `148137d` 08.05.2026 22:24 - Document Bilnex customer SQL write schema
- `b566b3e` 08.05.2026 22:12 - Remove unsupported SQL Server PDO timeout attribute
- `0d04bf8` 08.05.2026 22:11 - Fix admin SQL diagnostics permission check
- `d3870e6` 08.05.2026 21:58 - Show admin SQL connection diagnostics
- `2e5620e` 08.05.2026 21:56 - Try Docker host SQL endpoints in production
- `5dc17d3` 08.05.2026 21:50 - Normalize Coolify SQL host for customer reads
- `9da9180` 08.05.2026 21:37 - Use confirmed SQL database fallback in rollback test
- `947f4e4` 08.05.2026 21:36 - Use confirmed SQL database fallback
- `ed89d74` 08.05.2026 21:35 - Align regression permissions test with scoped company access
- `cad426e` 08.05.2026 21:34 - Expose SQL Customer read errors
- `7d49520` 08.05.2026 21:33 - Restore company access scoping
- `7cd9887` 08.05.2026 18:02 - Expose SQL Server login timeout config
- `498e2eb` 08.05.2026 18:02 - Add bounded SQL Server login timeout
- `38e1501` 08.05.2026 18:01 - Handle SQL Server customer read failures gracefully
- `bf22297` 08.05.2026 17:38 - Create CRM data directory during Docker build
- `35a5cbb` 08.05.2026 17:33 - Pin SQL Server PHP extensions for PHP 8.2
- `1eaec52` 08.05.2026 17:30 - Add sqlite dev package for Docker build
- `ea9d5d4` 08.05.2026 17:27 - Fix Dockerfile SQL Server driver build
- `d6612b3` 08.05.2026 17:22 - Add files via upload
