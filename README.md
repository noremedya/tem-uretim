# Tem Motor Üretim Takip Sistemi

Laravel 13 + Filament 5 + PostgreSQL 17 ile üretim, stok ve sipariş takibi. Uygulama internet olmadan çalışır; tüm varlıklar yereldir.

Sunucuya kurulum ve dağıtım için: [KURULUM_KILAVUZU.md](KURULUM_KILAVUZU.md)

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

## Testler

Testler PostgreSQL'de, ayrı `tem_uretim_test` veritabanında çalışır (veritabanı konteyneri ilk açılışta oluşturur).

```bash
docker compose exec app php artisan test
```

Kod biçimi: `docker compose exec app vendor/bin/pint`
