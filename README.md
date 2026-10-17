# Tem Motor Üretim Takip Sistemi

Laravel 13 + Filament 5 + PostgreSQL 17 ile üretim, stok ve sipariş takibi. Uygulama internet olmadan çalışır; tüm varlıklar yereldir.

Sunucuya kurulum ve dağıtım için: [KURULUM_KILAVUZU.md](KURULUM_KILAVUZU.md)

## Üretim dağıtımı (özet)

- `docker-compose.prod.yml`: app, worker, scheduler, db, caddy, cloudflared; değişkenler `.env` (örnek: `.env.production.example`).
- `deploy/Dockerfile` üretim imajı, `deploy/caddy/` HTTPS ön yüzü, `deploy/db/app-role.sql` uygulama veritabanı kullanıcısı.
- `deploy/scripts/`: `deploy.sh` (güncelleme), `backup.sh` (gece yedeği), `restore.sh` (geri yükleme), `db-migrate.sh` (migration + kullanıcı yetkileri).
- Uygulama tablo sahibi olmayan bir kullanıcıyla bağlanır; migration'lar her zaman `db-migrate.sh` ile tablo sahibi olarak çalışır.

## Geliştirme ortamı

Bilgisayarda yalnızca Docker gerekir; PHP, Composer ve Node konteynerin içindedir.

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app npm install
docker compose exec app npm run build          # veya canlı yenileme için: npm run dev
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed   # roller ve yetkiler dahil
docker compose exec app php artisan app:create-admin
```

Uygulama: http://localhost:8000 (kullanıcı adı ve şifreyle giriş).

**Demo veri** (yalnızca geliştirme; üretimde çalışmaz): `docker compose exec app php artisan db:seed --class=DemoDataSeeder`. Yaklaşık 7 haftalık üretim, stok, sipariş, fatura ve tahsilat kaydı oluşturur (~30 sn). Kullanıcılar `yonetici`, `operator1`, `operator2`, `depo`, `muhasebe`; şifre `demo1234`. Temiz kurmak için (geliştirme verisini siler): `php artisan migrate:fresh --seed && php artisan db:seed --class=DemoDataSeeder`.

## Testler

Testler PostgreSQL'de, ayrı `tem_uretim_test` veritabanında çalışır (veritabanı konteyneri ilk açılışta oluşturur).

```bash
docker compose exec app php artisan test
```

`tests/Concurrency` altındaki eşzamanlılık testleri ayrı PHP süreçleri başlatır ve veriyi commit eder; her testten önce ve sonra test veritabanını `migrate:fresh` ile yeniden kurar. Yalnızca bunları çalıştırmak için: `php artisan test --testsuite=Concurrency`.

Tarayıcı tarafı JS mantık testleri Node'un yerleşik test çalıştırıcısıyla yazılır (`tests/js`, ek paket yok) ve `php artisan test` içinden de çalışır; tek başına: `docker compose exec app npm run test:js`.

Hızlı İşlem sayfasındaki "Kamera ile okut" yalnızca güvenli bağlantıda çalışır: geliştirmede `http://localhost:8000`, fabrikada `https://` adresi (telefon/tablet yerel IP ile `http://` üzerinden bağlanırsa kamera açılmaz). Kamera kütüphanesi (`@zxing/browser`) ayrı bir parça olarak derlenir ve yalnızca kamera açılınca yüklenir.

QR etiketleri motor/iş emri sayfalarındaki "Etiket yazdır" butonlarıyla açılır (termal 100×50 mm veya A4 3×8; ölçüler `config/labels.php`). Yazdırırken ölçek %100 ve kenar boşluğu "yok" seçilmelidir.

Panel JS'i değiştiğinde varlıkları yeniden derleyin: `docker compose exec app npm run build` (veya `npm run dev`).

Stok tutarlılık kontrolü elle: `docker compose exec app php artisan stock:reconcile` (üretimde scheduler her gün 03:00'te çalıştırır).

Hareketsiz stok özeti elle: `docker compose exec app php artisan stock:idle-report` (üretimde her pazartesi 08:00; eşik `config/stock.php`, varsayılan 90 gün).

Raporlar 2.000 satırı aşınca Excel dosyası kuyrukta hazırlanır. Geliştirmede kuyruk işçisi otomatik çalışmaz; denemek için: `docker compose exec app php artisan queue:work` (üretimde `worker` servisi çalıştırır). Hazırlanan dosyalar 7 gün saklanır (`reports:prune-exports`, her gün 04:00).

Kod biçimi: `docker compose exec app vendor/bin/pint`
