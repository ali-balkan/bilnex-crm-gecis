# Bilnex İş Ortakları CRM

Sade PHP + SQLite tabanlı ilk sürüm CRM uygulaması. `www.alibalkan.com.tr/CRM` altında çalışması için `base_url` varsayılan olarak `/CRM` ayarlanmıştır.

## Kurulum

1. Bu klasördeki dosyaları hosting üzerinde `CRM` klasörüne yükleyin.
2. Sunucuda PHP 8.1+ ve `pdo_sqlite` eklentisinin aktif olduğundan emin olun.
3. İlk açılışta `data/crm.sqlite` otomatik oluşturulur.
4. Giriş bilgileri:
   - Kullanıcı adı: `superadmin`
   - Şifre: `BlnxCRM!2026`

İlk girişten sonra superadmin şifresini `Kullanıcılar` ekranından değiştirin.

## Modüller

- Kullanıcı yönetimi
- Bayi / firma kartları
- Görüşme geçmişi ve sonraki takip tarihi
- Takip listesi
- Satış fırsatları
- Yönetici ve personel dashboard
- Temel raporlar

Şifreler PHP `password_hash` ile hashlenerek saklanır. Admin ve yönetici tüm kayıtları görebilir; diğer roller kendi sorumluluklarındaki kayıtlarla sınırlıdır.
