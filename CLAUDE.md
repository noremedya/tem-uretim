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

1. **Hiçbir CDN veya harici kaynak kullanma.** Panelde ortak JS (Alpine bileşenleri) `resources/js/filament/app.js` üzerinden Vite ile yüklenir; yeni ortak bileşenler buraya kaydedilir. Font, JS, CSS, ikon dahil her şey Vite ile derlenip uygulamayla birlikte gelmeli. İnternet kesikken arayüz eksiksiz çalışmalı.
2. **Stok yalnızca `StockService` üzerinden değişir.** Hiçbir controller, Filament action veya model olayı stok bakiyesini doğrudan güncelleyemez.
3. **Stok hareketleri değiştirilemez ve silinemez.** Hata düzeltme ters kayıtla (düzeltme hareketi) yapılır.
4. **Birbirine bağlı her işlem tek `DB::transaction` içinde.** Stok bakiyesi güncellenirken ilgili satır `lockForUpdate()` ile kilitlenir.
5. **Kurallar veritabanında da zorlanır:** foreign key'ler (`restrictOnDelete`), unique index'ler, check constraint'ler (ör. miktar > 0, bakiye >= 0). Sadece form validasyonuna güvenme.
6. **Eşzamanlı düzenleme koruması:** düzenlenebilir ana tablolarda `lock_version` (integer) ile optimistic locking; çakışmada kullanıcıya anlaşılır Türkçe uyarı.
   - Kontrol **tek atomik sorguyla** yapılır: `UPDATE ... SET ..., lock_version = lock_version + 1 WHERE id = ? AND lock_version = ?`; etkilenen satır 0 ise `StaleModelException`. Önce okuyup sonra karşılaştırma yapılmaz.
   - Filament düzenleme formu, formun **açıldığı andaki** `lock_version`'ı taşır ve kaydederken onu kullanır; Filament'in kaydı yeniden okuması çakışmayı gizleyemez. Bu davranış iki ayrı oturumun aynı kaydı düzenlediği bir testle doğrulanır.
7. **Silme yok, pasifleştirme var:** ana kayıtlarda soft delete veya `is_active` alanı. Stok hareketi, durum geçmişi ve üretilmiş motor kayıtları hiçbir şekilde silinemez.
8. **Durum değişiklikleri enum + izinli geçiş tablosuyla** yönetilir; izinsiz geçiş exception fırlatır.
9. Durumlar, hareket tipleri, roller PHP `enum` olarak tanımlanır; string karşılaştırması dağınık kullanılmaz.
   - **Kolon adları ve enum değerleri İngilizcedir** (ör. `is_active`, `critical_level`; `planned`, `in_progress`, `stock_in`). Türkçe yalnızca arayüz etiketlerindedir (enum'larda `label()` metodu). Bölüm 5 ve sonrasındaki Türkçe alan/durum adları bölüm 4'teki İngilizce karşılıklarını ifade eder.
10. Arayüz tamamen **Türkçe**, `APP_LOCALE=tr`, `APP_TIMEZONE=Europe/Istanbul`, sayılar Türkçe biçimde.
11. Tüm iş mantığı `app/Services/` altında; Filament sayfaları yalnızca bu servisleri çağırır.
12. **Denetim izine hassas alan yazılmaz:** `password` ve `remember_token` activity log'a hiçbir şekilde kaydedilmez.
13. Belirsiz bir iş kuralıyla karşılaşırsan **varsayım yapma, bana sor.**

## 4. Veri modeli

Tablo adları, kolon adları ve enum değerleri İngilizce; arayüz etiketleri Türkçe (parantez içi Türkçe açıklamalar yalnızca anlam içindir).

- **users**: username (unique, zorunlu — giriş bununla yapılır), name, email (nullable, unique), password (hash), is_active, lock_version, roller (spatie; birden fazla olabilir, en az bir rol zorunlu)
- **units** (birim: adet, kg, metre…): name (unique), allows_decimal (bool — false ise bu birimdeki miktarlar tam sayı olmalı), lock_version
- **parts** (stok kartı): code (unique), name, unit_id, type (enum: raw_material / component / consumable — hammadde / parça / sarf), barcode (unique, nullable), critical_level (decimal, >= 0; 0 = takip yok), is_active, lock_version. Hareketi olan parçanın birimi değiştirilemez.
- **stock_balances**: part_id (unique), quantity (decimal, >= 0), is_below_critical (bool — kritik bildirimi gönderildi mi), updated_at — hızlı okuma için; tek yazıcısı StockService. Parça oluşturulurken 0 bakiyeyle açılır.
- **stock_movements**: part_id, type (enum: stock_in, stock_out, production_consumption, count_adjustment, return, correction — giriş, çıkış, üretim sarfı, sayım düzeltme, iade, ters kayıt), quantity (işaretli decimal, 0 olamaz), balance_before, balance_after, reference (morph: work_order vb., nullable), corrected_movement_id (nullable, unique — ters kaydedilen hareket), idempotency_key (uuid, nullable, unique — çift gönderim koruması), description, user_id, created_at. Update/delete/truncate yok (veritabanı trigger'ı ile de engellenir).
- **motor_models**: code (unique), name, güç, devir, gerilim gibi teknik alanlar (power, speed, voltage…), description, is_active
- **bom_items** (ürün reçetesi): motor_model_id, part_id, quantity_per_unit; (motor_model_id, part_id) unique
- **customers**: name (ad/ünvan), tax_number, phone, email, address, is_active
- **orders**: order_number (unique), customer_id, order_date, due_date, status (enum: open, partial, completed, cancelled — açık, kısmi, tamamlandı, iptal), notes
- **order_items**: order_id, motor_model_id, quantity
- **production_batches** (parti): batch_number (unique), date, notes
- **work_orders**: work_order_number (unique, otomatik), order_item_id (nullable), motor_model_id, quantity, production_batch_id (nullable), status (enum: planned, in_progress, completed, cancelled — planlandı, üretimde, tamamlandı, iptal), assigned_user_id, planned_start, planned_end, started_at, completed_at, lock_version
- **work_order_status_histories**: work_order_id, from_status, to_status, user_id, description, created_at
- **motor_units** (üretilen motor): serial_number (unique), motor_model_id, work_order_id, production_batch_id, order_id (nullable), production_date, status (enum: in_stock, shipped — stokta, sevk edildi), notes

Tarih, serial_number, status, motor_model_id, part_id gibi filtrelenen alanlara index ekle.

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
- Hareket tiplerinin yönü: `stock_in` ve `return` (+), `stock_out` ve `production_consumption` (−), `count_adjustment` ve `correction` (±). `return` üretimden/müşteriden depoya dönüştür; **tedarikçiye iade `stock_out` + açıklamayla** yapılır.
- Açıklama zorunlu: `stock_out`, `count_adjustment`, `correction`. `stock_in` ve `return`'de isteğe bağlı.
- **Sayım:** kullanıcı sayılan miktarı girer, sistem farkı hesaplar; fark 0 ise hareket oluşmaz, "bakiye zaten doğru" mesajı gösterilir.
- **Kritik seviye:** `bakiye <= critical_level` ise parça kritiktir. `critical_level = 0` takip yok demektir, bildirim gitmez.
- **Pasif parçaya** yalnızca sayım (`count_adjustment`) yapılabilir; diğer tüm hareketler reddedilir.
- **Küsurat:** birimi `allows_decimal = false` olan parçada küsuratlı miktar (hareket miktarı, sayılan miktar) servis seviyesinde reddedilir.
- **Ters kayıt (`correction`):** miktar orijinal hareketin tam tersidir; bir hareket yalnızca bir kez ters kaydedilebilir (`corrected_movement_id` unique); ters kaydın kendisi ters kaydedilemez; stoğu eksiye düşürecekse reddedilir. Bu kurallar servis + veritabanı (unique, insert trigger'ı) seviyesinde zorlanır.
- **Üretim sarfı (`production_consumption`) elle ters kaydedilemez.** Motor kayıtları oluşmuşken malzemenin geri dönmesi tutarsızlık yaratır; Aşama 4'te iş emri geri alma akışıyla ele alınacak.
- **Çift gönderim:** stok işlemi formu işlem sırasında butonu pasifleştirir ve formun ikinci kez gönderilmesini tarayıcıda engeller (Alpine `submitGuard`, bkz. bölüm 7); sunucu tarafında her form gönderimi bir `idempotency_key` taşır, aynı anahtarla ikinci hareket oluşmaz. Koruma yalnızca bu anahtara dayanır: aynı anahtarlı ikinci istek hareket oluşturmaz, ilk işlemin sonucunu gösterir ve bildirimi tekrarlamaz. Başarılı işlemden sonra form sıfırlanır (miktar ve açıklama temizlenir, tür ve parça kalır) ve yeni anahtar üretilir; aynı parça ve miktarla bilinçli ikinci işlem (ör. iki ayrı teslimat) yeni hareket oluşturur. İşlem sonrası kullanıcıya yeni bakiye gösterilir.
- Stok hareketleri listesini yönetici ve depo görür; operatör görmez.
- Günlük zamanlanmış komut `stock:reconcile`: her parça için hareket toplamı ile `stock_balances` karşılaştırılır; fark varsa loglanır ve yöneticiye bildirim gider (otomatik düzeltme yapmaz).

**Denetim izi:** users, parts, motor_models, bom_items, customers, orders, work_orders, motor_units değişiklikleri activitylog ile kaydedilir. Yönetici için görüntüleme ekranı olsun.

## 6. Roller ve yetkiler

| | Yönetici | Saha operatörü | Depo |
|---|---|---|---|
| Kullanıcı yönetimi | ✓ | | |
| Motor modeli, reçete | ✓ | görüntüleme | görüntüleme |
| Müşteri, sipariş | ✓ | görüntüleme | görüntüleme |
| İş emri görüntüleme | ✓ | kendine atananlar | ✓ (salt okunur) |
| İş emri oluşturma/atama | ✓ | | |
| İş emri durum değiştirme | ✓ | yalnızca kendine atananlar | |
| Stok kartları | ✓ | görüntüleme | ✓ |
| Stok giriş/çıkış/sayım | ✓ | | ✓ |
| Raporlar ve Excel | ✓ | | stok raporları |
| Denetim izi | ✓ | | |

- Bir kullanıcının **birden fazla rolü** olabilir (yetkiler birleşir); **en az bir rol zorunludur.** Kullanıcı formunda roller çoklu seçimle atanır.
- Giriş **kullanıcı adı (username) + şifre** ile yapılır; e-posta isteğe bağlıdır. Şifre sıfırlamayı yönetici yapar (e-posta ile sıfırlama yok).
- Kullanıcı adı yalnızca ASCII'dir (küçük harf, rakam, `.`, `_`, `-`). Formda, girişte ve komutta Türkçe karakterler otomatik çevrilir (ş→s, ç→c, ğ→g, ü→u, ö→o, ı→i, İ→i) ve büyük harfler küçültülür; kullanıcı hata almadan düzeltilmiş hâlini görür.
- Pasifleştirilen kullanıcı bir sonraki isteğinde (Livewire istekleri dahil) otomatik çıkış yapar ve giriş ekranında "Hesabınız pasifleştirildi, yöneticinize başvurun." mesajını görür.
- **Yönetici güvenliği:** kullanıcı kendini pasifleştiremez; son aktif yöneticinin yönetici rolü kaldırılamaz ve pasifleştirilemez.
- "En az bir rol" ve yönetici güvenliği kuralları **servis seviyesinde** (UserService, kilitli) zorlanır; bunlar için veritabanı trigger'ı eklenmez (bölüm 3 kural 5'in bilinçli istisnası).
- Filament paneli kök adreste (`/`) çalışır.

Yetkiler Laravel Policy'leriyle uygulanır; Filament menüsü de yetkiye göre gizlenir. Saha operatörü arayüzü sade olmalı: ana ekranı "Bana atanan iş emirleri" + "Hızlı işlem".

## 7. Ekranlar

- **Dashboard**: bugün/bu hafta tamamlanan motor sayısı, üretimdeki iş emirleri, gecikmiş iş emirleri (planlanan bitiş geçmiş), kritik stoktaki parçalar
- Standart Filament kaynakları: kullanıcılar, birimler, parçalar, motor modelleri (reçete ilişki yöneticisiyle), müşteriler, siparişler (kalemleriyle), partiler, iş emirleri, üretilen motorlar, stok hareketleri (salt okunur liste)
- **Stok işlemi sayfası**: giriş / çıkış / sayım düzeltme formu
- **Hızlı İşlem sayfası** (dokunmatik ve büyük butonlu):
  - Otomatik odaklı tek bir okuma alanı; USB okuyucunun gönderdiği Enter ile işlem tetiklenir. Okuma alanı bir form içindedir ve Enter formu gönderir; form, istek sürerken ikinci gönderimi engelleyen ortak Alpine bileşeni **`submitGuard`** ile korunur (`resources/js/filament/submit-guard.js`, kullanım: `x-data="submitGuard({ action: '...' })"`). Açık soru (Aşama 5'te karar verilecek): guard, istek sürerken gelen okumayı düşürür; hızlı art arda farklı kodların okunması durumunda düşürmek mi, sıraya almak mı istendiği sorulacak.
  - "Kamera ile okut" butonu (telefon/tablet)
  - Okunan değer parça barkoduysa → hızlı stok giriş/çıkış formu
  - Seri no ise → motor detay kartı
  - İş emri numarası ise → durum değiştirme butonları. Saha operatörü yalnızca kendine atanan iş emirlerini görür (listede, detayda ve burada); başkasına atanmış bir iş emri okutulursa iş emri bilgisi gösterilmez, "Bu iş emri size atanmamış." mesajı çıkar
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
- Sağlık kontrolü: Laravel'in `/up` endpoint'i. `app` servisinin healthcheck'i hem `docker-compose.yml` hem `docker-compose.prod.yml` içinde açıkça tanımlanır: `curl -fsS -o /dev/null http://localhost:<port>/up || exit 1` (geliştirmede 8000, üretimde 80). `dunglas/frankenphp` taban imajından miras kalan healthcheck (`localhost:2019/metrics`, Caddy admin API) kullanılmaz; o port açık olmadığından konteyner uygulama çalışırken bile `unhealthy` görünür.

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
- Ters kayıt kuralları (tam ters miktar, tek sefer, ters kaydın ters kaydı yok, eksiye düşürmez), pasif parça, küsurat, çift gönderim

**Eşzamanlılık testleri** (`tests/Concurrency`, ayrı test suite): gerçek eşzamanlılık için veri commit edilmeli, bu yüzden bu testler transaction'lı `RefreshDatabase` kullanmaz. `stock_movements` TRUNCATE'e kapalı olduğundan `DatabaseTruncation` da kullanılmaz; temizlik `migrate:fresh` ile (DROP TABLE) yapılır. Trigger'ı devre dışı bırakan hiçbir uygulama yolu (ayar, oturum değişkeni, ortam bayrağı) yoktur.

Testler PostgreSQL üzerinde çalışmalı (SQLite değil), çünkü kilitler ve constraint'ler davranışı farklıdır.

**Testler yalnızca `tem_uretim_test` veritabanında çalışır.** Bağlı veritabanının adı farklıysa testler `migrate:fresh` veya herhangi bir sorgu çalıştırmadan hata vererek durur (`Tests\TestCase::ensureTestDatabase()`; eşzamanlılık testleri ve işçi süreçleri de aynı kontrolü yapar). Bu koruma testle doğrulanır.

## 11. Çalışma şekli

- Aşağıdaki aşamalarla ilerle. **Her aşamaya başlamadan önce kısa bir plan göster ve onayımı bekle.**
- Her aşama sonunda: testleri çalıştır, geçtiğini göster, anlamlı bir mesajla commit at, yapılanları ve açık kalan soruları özetle.
- Kod yorumları ve commit mesajları Türkçe olabilir; kod içi isimlendirme İngilizce.
- **Geliştirme veritabanında veri silen komut çalıştırmadan önce mutlaka onay iste:** `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`, `db:seed` ile üzerine yazma, doğrudan `DELETE`/`TRUNCATE`/`DROP` vb. Şema değişikliği için yalnızca `migrate` (ileri) onaysız çalıştırılabilir. Test veritabanı (`tem_uretim_test`) bu kuralın dışındadır.

**Aşamalar**
1. Proje iskeleti: Laravel + Filament kurulumu, geliştirme için `docker-compose.yml`, PostgreSQL, Türkçe dil/saat dilimi, roller ve yetkiler, `app:create-admin`, kullanıcı yönetimi, Pest kurulumu
2. Stok: birimler, parçalar, `StockService`, stok hareketleri, stok işlemi sayfası, kritik stok bildirimi, `stock:reconcile`, testler
3. Müşteri ve sipariş
4. Üretim: motor modelleri, reçete, partiler, iş emirleri, durum makinesi, tamamlama akışı, seri no üretimi, üretilen motorlar, testler
   - İş emri geri alma akışı: üretim sarfının ters kaydı burada ele alınır (elle ters kayıt kapalıdır, bkz. bölüm 5).
5. Hızlı İşlem sayfası: USB barkod ve kamera ile okuma
6. Raporlar ve Excel dışa aktarım
7. Dashboard ve denetim izi ekranı
8. Üretim dağıtım dosyaları, betikler, `.env.production.example`, README
   - **Ayrı uygulama veritabanı rolü:** uygulama, tablo sahibi olmayan bir rolle bağlanır (yalnızca gereken tablo yetkileri; `ALTER TABLE ... DISABLE TRIGGER` yapamaz). Migration'lar tablo sahibi kullanıcıyla çalışır. `deploy.sh`, `.env.production.example` ve kurulum kılavuzu buna göre düzenlenir.
9. Demo veri, genel gözden geçirme (güvenlik, N+1 sorgular, eksik index'ler, CDN kontrolü)
10. (Opsiyonel) Etiket yazdırma
