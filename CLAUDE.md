# Tem Motor Üretim Takip Sistemi — Claude Code Proje Talimatları

Bu dosya projenin kalıcı talimatlarıdır. Her oturumda baştan oku ve buradaki kurallara uy.

## 1. Proje özeti

Bir elektrik motoru üreticisi (Tem Motor) için üretim takip web uygulaması. Fabrika içindeki bir mini PC sunucuda Docker ile çalışır; kullanıcılar yerel ağ üzerinden tarayıcıyla (PC, tablet, telefon) bağlanır. **Uygulama internet olmadan tam çalışmak zorundadır.** Bulut yalnızca yedekleme ve Cloudflare Tunnel ile uzaktan erişim için kullanılır.

Kapsam:
- İş emri oluşturma, atama, üretim aşaması takibi (planlandı / üretimde / tamamlandı / iptal)
- Motor modeli, seri no ve üretim partisi bazlı kayıt
- Hammadde/parça stok takibi, kritik stok uyarısı
- Müşteri ve sipariş kaydı, sipariş–iş emri–motor ilişkilendirme
- Rol bazlı yetkilendirme: yönetici, saha operatörü, depo
- Günlük/haftalık/aylık üretim ve verimlilik raporları, Excel'e aktarım
- Barkod/QR ile hızlı işlem (USB okuyucu + telefon kamerası)
- Denetim izi (kim neyi ne zaman değiştirdi)

## 2. Teknoloji

- PHP 8.4, Laravel güncel kararlı sürüm
- Filament güncel kararlı sürüm (v4 veya üstü) — tüm yönetim arayüzü
- PostgreSQL 17
- Kuyruk, önbellek ve oturum sürücüsü: `database` (Redis kullanma; hareketli parça sayısını az tut)
- spatie/laravel-permission (roller/yetkiler), spatie/laravel-activitylog (denetim izi)
- Pest (testler)
- QR/barkod kamera okuma: npm paketi (ör. html5-qrcode), Vite ile derlenip pakete dahil edilir
- Üretim sunucusu: Docker Compose, FrankenPHP tabanlı uygulama imajı, önde Caddy (Cloudflare DNS modülüyle), cloudflared
- Paket eklemeden önce güncel Laravel/Filament sürümüyle uyumluluğunu kontrol et; uyumsuzsa bana sor.

## 3. Değişmez kurallar

1. **Hiçbir CDN veya harici kaynak kullanma.** Font, JS, CSS, ikon dahil her şey Vite ile derlenip uygulamayla birlikte gelmeli. İnternet kesikken arayüz eksiksiz çalışmalı.
2. **Stok yalnızca `StockService` üzerinden değişir.** Hiçbir controller, Filament action veya model olayı stok bakiyesini doğrudan güncelleyemez.
3. **Stok hareketleri değiştirilemez ve silinemez.** Hata düzeltme ters kayıtla (düzeltme hareketi) yapılır.
4. **Birbirine bağlı her işlem tek `DB::transaction` içinde.** Stok bakiyesi güncellenirken ilgili satır `lockForUpdate()` ile kilitlenir.
5. **Kurallar veritabanında da zorlanır:** foreign key'ler (`restrictOnDelete`), unique index'ler, check constraint'ler (ör. miktar > 0, bakiye >= 0). Sadece form validasyonuna güvenme.
6. **Eşzamanlı düzenleme koruması:** düzenlenebilir ana tablolarda `lock_version` (integer) ile optimistic locking; çakışmada kullanıcıya anlaşılır Türkçe uyarı.
7. **Silme yok, pasifleştirme var:** ana kayıtlarda soft delete veya `aktif` alanı. Stok hareketi, durum geçmişi ve üretilmiş motor kayıtları hiçbir şekilde silinemez.
8. **Durum değişiklikleri enum + izinli geçiş tablosuyla** yönetilir; izinsiz geçiş exception fırlatır.
9. Durumlar, hareket tipleri, roller PHP `enum` olarak tanımlanır; string karşılaştırması dağınık kullanılmaz.
10. Arayüz tamamen **Türkçe**, `APP_LOCALE=tr`, `APP_TIMEZONE=Europe/Istanbul`, sayılar Türkçe biçimde.
11. Tüm iş mantığı `app/Services/` altında; Filament sayfaları yalnızca bu servisleri çağırır.
12. Belirsiz bir iş kuralıyla karşılaşırsan **varsayım yapma, bana sor.**

## 4. Veri modeli

Tablo adları İngilizce, arayüz etiketleri Türkçe.

- **users**: ad, e-posta, şifre (hash), aktif, rol (spatie)
- **units**: birim (adet, kg, metre…)
- **parts** (stok kartı): kod (unique), ad, birim, tür (hammadde / parça / sarf), barkod (unique, nullable), kritik_seviye (decimal), aktif
- **stock_balances**: part_id (unique), miktar (decimal, >= 0), updated_at — hızlı okuma için; tek yazıcısı StockService
- **stock_movements**: part_id, tip (enum: giris, cikis, uretim_sarf, sayim_duzeltme, iade), miktar (işaretli decimal, 0 olamaz), onceki_bakiye, sonraki_bakiye, referans (morph: work_order vb., nullable), aciklama, user_id, created_at. Update/delete yok.
- **motor_models**: kod (unique), ad, güç, devir, gerilim gibi teknik alanlar, açıklama, aktif
- **bom_items** (ürün reçetesi): motor_model_id, part_id, birim_basina_miktar; (motor_model_id, part_id) unique
- **customers**: ad/ünvan, vergi no, telefon, e-posta, adres, aktif
- **orders**: sipariş_no (unique), customer_id, sipariş_tarihi, termin_tarihi, durum (enum: acik, kismi, tamamlandi, iptal), not
- **order_items**: order_id, motor_model_id, adet
- **production_batches** (parti): parti_no (unique), tarih, not
- **work_orders**: is_emri_no (unique, otomatik), order_item_id (nullable), motor_model_id, adet, production_batch_id (nullable), durum (enum: planlandi, uretimde, tamamlandi, iptal), atanan_user_id, planlanan_baslangic, planlanan_bitis, baslama_zamani, bitis_zamani, lock_version
- **work_order_status_histories**: work_order_id, eski_durum, yeni_durum, user_id, aciklama, created_at
- **motor_units** (üretilen motor): seri_no (unique), motor_model_id, work_order_id, production_batch_id, order_id (nullable), uretim_tarihi, durum (enum: stokta, sevk_edildi), not

Tarih, seri_no, durum, motor_model_id, part_id gibi filtrelenen alanlara index ekle.

## 5. İş kuralları

**İş emri durum geçişleri**
- planlandi → uretimde (atanan operatör veya yönetici)
- uretimde → tamamlandi (atanan operatör veya yönetici)
- planlandi → iptal (yönetici)
- uretimde → planlandi (yalnızca yönetici, açıklama zorunlu)
- tamamlandi ve iptal son durumlardır
- Her geçiş `work_order_status_histories`'e yazılır.

**İş emri tamamlanınca (tek transaction):**
1. Reçeteye göre `adet × birim_basina_miktar` kadar malzeme `uretim_sarf` hareketiyle düşülür.
2. Herhangi bir malzeme yetersizse işlem tamamen iptal edilir; hangi malzemeden ne kadar eksik olduğu kullanıcıya listelenir.
3. `adet` kadar `motor_units` kaydı oluşturulur. Seri no formatı yapılandırılabilir olsun (varsayılan: `{MODEL_KODU}-{YY}{AA}-{5 haneli sıra}`), sıra numarası çakışmasız üretilmeli (veritabanı sequence'i veya kilitli sayaç tablosu).
4. İş emri siparişe bağlıysa motorlar siparişe bağlanır, sipariş durumu yeniden hesaplanır.

**Stok**
- Bakiye hiçbir zaman negatife düşemez (servis + check constraint).
- Her hareketten sonra bakiye `kritik_seviye`'nin altına inerse yönetici ve depo rollerine Filament veritabanı bildirimi gönderilir. Aynı parça için bakiye kritik seviyenin üstüne çıkana kadar tekrar bildirim gönderilmez.
- Günlük zamanlanmış komut `stock:reconcile`: her parça için hareket toplamı ile `stock_balances` karşılaştırılır; fark varsa loglanır ve yöneticiye bildirim gider (otomatik düzeltme yapmaz).

**Denetim izi:** users, parts, motor_models, bom_items, customers, orders, work_orders, motor_units değişiklikleri activitylog ile kaydedilir. Yönetici için görüntüleme ekranı olsun.

## 6. Roller ve yetkiler

| | Yönetici | Saha operatörü | Depo |
|---|---|---|---|
| Kullanıcı yönetimi | ✓ | | |
| Motor modeli, reçete | ✓ | görüntüleme | görüntüleme |
| Müşteri, sipariş | ✓ | görüntüleme | görüntüleme |
| İş emri oluşturma/atama | ✓ | | |
| İş emri durum değiştirme | ✓ | yalnızca kendine atananlar | |
| Stok kartları | ✓ | görüntüleme | ✓ |
| Stok giriş/çıkış/sayım | ✓ | | ✓ |
| Raporlar ve Excel | ✓ | | stok raporları |
| Denetim izi | ✓ | | |

Yetkiler Laravel Policy'leriyle uygulanır; Filament menüsü de yetkiye göre gizlenir. Saha operatörü arayüzü sade olmalı: ana ekranı "Bana atanan iş emirleri" + "Hızlı işlem".

## 7. Ekranlar

- **Dashboard**: bugün/bu hafta tamamlanan motor sayısı, üretimdeki iş emirleri, gecikmiş iş emirleri (planlanan bitiş geçmiş), kritik stoktaki parçalar
- Standart Filament kaynakları: kullanıcılar, birimler, parçalar, motor modelleri (reçete ilişki yöneticisiyle), müşteriler, siparişler (kalemleriyle), partiler, iş emirleri, üretilen motorlar, stok hareketleri (salt okunur liste)
- **Stok işlemi sayfası**: giriş / çıkış / sayım düzeltme formu
- **Hızlı İşlem sayfası** (dokunmatik ve büyük butonlu):
  - Otomatik odaklı tek bir okuma alanı; USB okuyucunun gönderdiği Enter ile işlem tetiklenir
  - "Kamera ile okut" butonu (telefon/tablet)
  - Okunan değer parça barkoduysa → hızlı stok giriş/çıkış formu
  - Seri no ise → motor detay kartı
  - İş emri numarası ise → durum değiştirme butonları
  - Aynı kodun 2 saniye içinde tekrar okunması yok sayılır
  - Okuma sonrası odak otomatik olarak okuma alanına döner
- **Etiket yazdırma** (opsiyonel aşama): seri no ve iş emri için QR etiketli, tarayıcıdan yazdırılabilir sayfa

## 8. Raporlar

Tarih aralığı (günlük/haftalık/aylık hazır seçimlerle) ve filtreler (model, operatör, parti):
- Üretim adedi: modele ve güne/haftaya/aya göre
- Tamamlanan iş emirleri, ortalama üretim süresi (başlama → bitiş), zamanında tamamlanma oranı (planlanan bitişe göre)
- Operatör bazlı üretim
- Stok hareketleri ve dönem sonu bakiyeleri
- Sipariş durumu: siparişe göre üretilen/kalan adet

Hepsi Excel (.xlsx) olarak dışa aktarılabilir. Büyük dışa aktarımlar kuyrukta çalışır, bitince bildirim gelir.

## 9. Dağıtım dosyaları (üretim)

Kurulum kılavuzu bu dosya adlarına dayanır; isimleri değiştirme.

- `docker-compose.yml` — geliştirme ortamı (app, db; Caddy/cloudflared yok)
- `docker-compose.prod.yml` — üretim. Servisler:
  - `app`: `deploy/Dockerfile` ile derlenen FrankenPHP imajı, içeride düz HTTP port 80; `restart: unless-stopped`; `storage` volume
  - `worker`: aynı imaj, `php artisan queue:work --tries=3 --max-time=3600`
  - `scheduler`: aynı imaj, `php artisan schedule:work`
  - `db`: `postgres:17`, `pgdata` volume, `pg_isready` healthcheck; app/worker/scheduler `service_healthy` bekler; port dışarı açılmaz
  - `caddy`: `deploy/caddy/Dockerfile` (xcaddy ile `github.com/caddy-dns/cloudflare` modülü), 80/443 yayınlar, `CF_API_TOKEN` ve `APP_DOMAIN` ortam değişkenleri, `caddy_data` ve `caddy_config` volume'ları
  - `cloudflared`: `cloudflare/cloudflared`, `tunnel --no-autoupdate run`, `TUNNEL_TOKEN`
- `deploy/Dockerfile` — çok aşamalı: Node aşamasında Vite derlemesi, Composer aşaması (`--no-dev --optimize-autoloader`), FrankenPHP son aşama; gerekli PHP eklentileri (pdo_pgsql, intl, zip, gd, bcmath, opcache); root olmayan kullanıcı
- `deploy/caddy/Caddyfile`:
  ```
  {$APP_DOMAIN} {
      tls {
          dns cloudflare {env.CF_API_TOKEN}
      }
      encode zstd gzip
      reverse_proxy app:80
  }
  ```
- `.env.production.example` — tüm üretim değişkenleri açıklamalı (APP_DOMAIN, CF_API_TOKEN, TUNNEL_TOKEN dahil)
- Laravel TrustProxies: Caddy ve Cloudflare arkasında doğru şema/IP için proxy'lere güven
- Sağlık kontrolü: Laravel'in `/up` endpoint'i

**Artisan komutları**
- `app:create-admin` — etkileşimli ilk yönetici oluşturma
- `stock:reconcile` — bölüm 5'teki tutarlılık kontrolü (scheduler'da günlük)
- `RolesAndPermissionsSeeder` — roller ve yetkiler (tekrar çalıştırılabilir, idempotent)
- `DemoDataSeeder` — yalnızca geliştirme için örnek veri

**Betikler** (`deploy/scripts/`, çalıştırılabilir, `set -euo pipefail`, anlaşılır Türkçe log çıktısı):
- `backup.sh` (cron ile root olarak çalışır):
  1. `/etc/uretim-yedek.env` dosyasını yükler
  2. `pg_dump -Fc` ile dökümü `/var/backups/uretim/db-YYYYMMDD-HHMM.dump` olarak alır (compose üzerinden `exec -T db`)
  3. storage volume'undaki yüklenen dosyaları `files-YYYYMMDD-HHMM.tar.gz` olarak arşivler
  4. `/mnt/yedek` bağlıysa (`mountpoint -q`) dosyaları `/mnt/yedek/uretim/` altına kopyalar; bağlı değilse uyarı yazar, devam eder
  5. `restic backup` ile `/var/backups/uretim` klasörünü buluta gönderir; internet yoksa uyarı yazar, yerel yedekleri koruyarak devam eder
  6. `restic forget --keep-daily 30 --keep-monthly 12 --prune`
  7. Sunucuda 14 günden, USB diskte 60 günden eski dosyaları siler
  8. Başarıda `$HC_YEDEK_URL`, hatada `$HC_YEDEK_URL/fail` adresine ping atar (curl zaman aşımlı, sessiz)
- `deploy.sh`: backup.sh çalıştırır → `git pull --ff-only` → `docker compose -f docker-compose.prod.yml build` → `up -d` → `migrate --force` → `optimize` → `/up` sağlık kontrolü (başarısızsa açık hata mesajıyla çıkar)
- `restore.sh <dump-dosyası>`: onay ister → app/worker/scheduler durdurur → `pg_restore --clean --if-exists` → servisleri başlatır → `optimize`

Ayrıca kısa bir `README.md`: geliştirme ortamını çalıştırma, testleri çalıştırma, dağıtım için KURULUM_KILAVUZU.md'ye yönlendirme.

## 10. Testler

Pest ile en az şu durumlar test edilmeli:
- Stok girişi/çıkışı bakiye ve hareket kaydını doğru oluşturur
- Negatif stoğa izin verilmez (servis ve veritabanı seviyesinde)
- Stok hareketi güncellenemez/silinemez
- İş emri tamamlanınca reçeteye göre sarf, motor kayıtları ve seri no'lar doğru oluşur
- Malzeme yetersizse hiçbir şey değişmez (transaction geri alınır)
- İzinsiz durum geçişleri reddedilir
- Seri no'lar benzersizdir; aynı anda iki tamamlamada çakışma olmaz
- Kritik stok bildirimi bir kez gönderilir, tekrar etmez
- Rol yetkileri: operatör başkasının iş emrini değiştiremez, depo iş emri oluşturamaz
- `stock:reconcile` tutarsızlığı yakalar

Testler PostgreSQL üzerinde çalışmalı (SQLite değil), çünkü kilitler ve constraint'ler davranışı farklıdır.

## 11. Çalışma şekli

- Aşağıdaki aşamalarla ilerle. **Her aşamaya başlamadan önce kısa bir plan göster ve onayımı bekle.**
- Her aşama sonunda: testleri çalıştır, geçtiğini göster, anlamlı bir mesajla commit at, yapılanları ve açık kalan soruları özetle.
- Kod yorumları ve commit mesajları Türkçe olabilir; kod içi isimlendirme İngilizce.

**Aşamalar**
1. Proje iskeleti: Laravel + Filament kurulumu, geliştirme için `docker-compose.yml`, PostgreSQL, Türkçe dil/saat dilimi, roller ve yetkiler, `app:create-admin`, kullanıcı yönetimi, Pest kurulumu
2. Stok: birimler, parçalar, `StockService`, stok hareketleri, stok işlemi sayfası, kritik stok bildirimi, `stock:reconcile`, testler
3. Müşteri ve sipariş
4. Üretim: motor modelleri, reçete, partiler, iş emirleri, durum makinesi, tamamlama akışı, seri no üretimi, üretilen motorlar, testler
5. Hızlı İşlem sayfası: USB barkod ve kamera ile okuma
6. Raporlar ve Excel dışa aktarım
7. Dashboard ve denetim izi ekranı
8. Üretim dağıtım dosyaları, betikler, `.env.production.example`, README
9. Demo veri, genel gözden geçirme (güvenlik, N+1 sorgular, eksik index'ler, CDN kontrolü)
10. (Opsiyonel) Etiket yazdırma
