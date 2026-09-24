# Tem Motor Üretim Takip Sistemi — Sunucu Kurulum Kılavuzu

Mimari: Fabrika içinde bir mini PC sunucu. Uygulama yerel ağda çalışır, internet kesilse de üretim durmaz. Bulut yalnızca yedekleme ve uzaktan erişim için kullanılır.

```
 Fabrika yerel ağı                                   İnternet
 ┌───────────────────────────────────────────┐
 │ PC / tablet / telefon (tarayıcı)          │
 │        │  https://uretim.alanadi.com      │
 │        ▼  (yerel DNS → 192.168.1.50)      │
 │ ┌──────────── Mini PC (Ubuntu) ─────────┐ │
 │ │ Caddy (HTTPS) → app (Laravel)         │ │     Cloudflare Tunnel ◄── Yönetici (dışarıdan)
 │ │ worker, scheduler, PostgreSQL         │─┼───► Backblaze B2 (şifreli yedek)
 │ │ cloudflared, Tailscale, NUT (UPS)     │─┼───► Tailscale ◄── Geliştirici (SSH)
 │ └───────────────────────────────────────┘ │
 │ Harici yedek disk (USB)                   │
 └───────────────────────────────────────────┘
```

## 0. Bu kılavuzdaki yer tutucular

Aşağıdaki değerleri her yerde kendi değerlerinle değiştir:

| Yer tutucu | Anlamı |
|---|---|
| `uretim.alanadi.com` | Uygulamanın adresi (Cloudflare'de yönetilen bir alan adının alt alan adı) |
| `192.168.1.50` | Sunucuya verilecek sabit yerel IP |
| `192.168.1.0/24` | Fabrika yerel ağı |
| `uretim` | Sunucudaki Linux kullanıcı adı |
| `git@github.com:KULLANICI/tem-uretim.git` | Proje deposu |

---

## 1. Gerekenler

**Donanım**
- Mini PC: Intel N100 / i5 sınıfı, 16 GB RAM, 512 GB NVMe SSD, kablolu Ethernet
- UPS: USB HID desteği olan bir model (APC, Eaton vb.). En az 15-20 dk dayanmalı
- Harici USB disk (yerel yedek kopyası için), 500 GB+
- Router: sabit DHCP ataması ve tercihen yerel DNS kaydı destekleyen

**Hesaplar**
- Cloudflare hesabı ve alan adı Cloudflare DNS'te
- Backblaze B2 hesabı (veya S3 uyumlu başka bir depolama)
- healthchecks.io hesabı (ücretsiz plan yeterli)
- Tailscale hesabı (ücretsiz plan yeterli)
- GitHub/GitLab'da özel (private) proje deposu

---

## 2. BIOS ayarları

Kurulumdan önce BIOS'ta:
- **"Restore on AC Power Loss" → "Power On"** (elektrik gelince sunucu kendiliğinden açılsın; en sık unutulan ayar)
- Secure Boot açık kalabilir
- Gereksizse Wi-Fi ve Bluetooth kapatılabilir

---

## 3. Ubuntu Server kurulumu

1. Ubuntu Server LTS (güncel sürüm) USB belleğe yazılıp kurulur.
2. Kurulumda: kullanıcı `uretim`, **OpenSSH server'ı işaretle**, diski LVM ile tam kullan.
3. Kurulum sonrası:

```bash
sudo apt update && sudo apt full-upgrade -y
sudo timedatectl set-timezone Europe/Istanbul
sudo hostnamectl set-hostname uretim-sunucu
sudo apt install -y curl git ufw unattended-upgrades restic jq
sudo dpkg-reconfigure -plow unattended-upgrades   # "Yes" seç: güvenlik güncellemeleri otomatik
```

**Sabit IP:** En kolayı router'da bu cihazın MAC adresine `192.168.1.50` sabit DHCP ataması yapmak. Router desteklemiyorsa `/etc/netplan/` altındaki dosyadan statik IP tanımla.

---

## 4. Docker kurulumu

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker uretim
# oturumu kapatıp tekrar aç
docker run --rm hello-world
```

Docker servisinin açılışta başlaması varsayılan olarak açıktır:
```bash
sudo systemctl is-enabled docker   # "enabled" görmelisin
```

---

## 5. Tailscale (geliştirici erişimi)

```bash
curl -fsSL https://tailscale.com/install.sh | sh
sudo tailscale up --ssh
```

Tarayıcıda açılan bağlantıdan hesabına ekle. Tailscale yönetim panelinde bu cihaz için **"Disable key expiry"** seç; yoksa birkaç ay sonra erişimin kesilir.

Artık kendi bilgisayarından (Tailscale kurulu olarak) `ssh uretim@uretim-sunucu` ile her yerden bağlanabilirsin.

---

## 6. Güvenlik duvarı

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow from 192.168.1.0/24 to any port 22 proto tcp     # yerel ağdan SSH
sudo ufw allow in on tailscale0                                 # Tailscale üzerinden her şey
sudo ufw allow from 192.168.1.0/24 to any port 80,443 proto tcp
sudo ufw allow from 192.168.1.0/24 to any port 53               # sadece 9.B seçeneğinde gerekli
sudo ufw enable
```

> Not: Docker'ın yayınladığı portlar (80/443) ufw kurallarını atlayabilir. Sunucu router'ın arkasında NAT'lı yerel ağda olduğu ve router'da port yönlendirme yapılmadığı sürece sorun değildir. **Router'da bu sunucuya hiçbir port yönlendirmesi yapma**; dış erişim yalnızca Cloudflare Tunnel üzerinden olacak.

---

## 7. UPS entegrasyonu (NUT)

UPS'i USB ile sunucuya bağla.

```bash
sudo apt install -y nut
lsusb     # UPS görünüyor mu kontrol et
```

`/etc/nut/nut.conf`:
```
MODE=standalone
```

`/etc/nut/ups.conf` (sonuna ekle):
```
[ups]
  driver = usbhid-ups
  port = auto
  desc = "Sunucu UPS"
```

`/etc/nut/upsd.users`:
```
[upsmon]
  password = GUCLU_BIR_SIFRE
  upsmon primary
```

`/etc/nut/upsmon.conf` (ilgili satırı ekle/düzenle):
```
MONITOR ups@localhost 1 upsmon GUCLU_BIR_SIFRE primary
```

```bash
sudo systemctl restart nut-server nut-monitor
upsc ups@localhost     # batarya durumu görünmeli
```

Varsayılan davranış: batarya kritik seviyeye inince sunucu düzgünce kapanır, elektrik gelince BIOS ayarı sayesinde yeniden açılır, Docker konteynerleri kendiliğinden başlar.

> UPS modeline göre sürücü farklı olabilir. `usbhid-ups` çalışmazsa NUT'un donanım uyumluluk listesine bak.

---

## 8. Projenin sunucuya alınması

```bash
sudo mkdir -p /opt/uretim && sudo chown uretim:uretim /opt/uretim
cd /opt/uretim
ssh-keygen -t ed25519 -C "uretim-sunucu"      # çıkan public key'i depoya "deploy key" (salt okunur) olarak ekle
cat ~/.ssh/id_ed25519.pub
git clone git@github.com:KULLANICI/tem-uretim.git .
cp .env.production.example .env
nano .env
```

`.env` içinde doldurulacak başlıca değerler:
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://uretim.alanadi.com
APP_KEY=                       # ilk çalıştırmada üretilecek (bkz. Bölüm 10)

DB_CONNECTION=pgsql
DB_HOST=db
DB_DATABASE=uretim
DB_USERNAME=uretim
DB_PASSWORD=UZUN_RASTGELE_SIFRE

APP_DOMAIN=uretim.alanadi.com
CF_API_TOKEN=                  # Bölüm 9.A
TUNNEL_TOKEN=                  # Bölüm 11
```

```bash
chmod 600 .env
```

Kolaylık için kısayol:
```bash
echo "alias dc='docker compose -f /opt/uretim/docker-compose.prod.yml --env-file /opt/uretim/.env'" >> ~/.bashrc
source ~/.bashrc
```

---

## 9. HTTPS ve yerel DNS

Telefon kamerasıyla QR okutma yalnızca HTTPS'te çalışır. Bu yüzden yerel ağda da geçerli sertifikalı bir alan adı kullanıyoruz.

### 9.A Cloudflare API token (sertifika için)

Cloudflare → My Profile → API Tokens → Create Token → "Edit zone DNS" şablonu → yalnızca ilgili alan adını seç → token'ı `.env` içindeki `CF_API_TOKEN` alanına yaz.

Caddy bu token ile DNS doğrulaması yaparak Let's Encrypt sertifikası alır. Sunucunun internetten erişilebilir olması gerekmez. Sertifika bitişine 30 gün kala kendiliğinden yenilenir; saatlik internet kesintileri bunu etkilemez.

### 9.B Yerel DNS: `uretim.alanadi.com` → `192.168.1.50`

Fabrikadaki cihazlar bu adı internet olmasa da sunucunun yerel IP'sine çözebilmeli.

**Seçenek 1 (önerilen): Router'da statik DNS kaydı.** MikroTik ve çoğu işletme router'ı destekler. `uretim.alanadi.com` için `192.168.1.50` A kaydı ekle. Bitti.

**Seçenek 2: Router desteklemiyorsa sunucuda dnsmasq.**

```bash
# Ubuntu'nun kendi DNS dinleyicisini kapat (53 portunu boşaltmak için)
sudo sed -i 's/^#\?DNSStubListener=.*/DNSStubListener=no/' /etc/systemd/resolved.conf
sudo systemctl restart systemd-resolved
sudo ln -sf /run/systemd/resolve/resolv.conf /etc/resolv.conf

sudo apt install -y dnsmasq
sudo tee /etc/dnsmasq.d/uretim.conf > /dev/null <<'EOF'
listen-address=127.0.0.1,192.168.1.50
bind-interfaces
no-resolv
server=1.1.1.1
server=8.8.8.8
address=/uretim.alanadi.com/192.168.1.50
cache-size=1000
EOF
sudo systemctl restart dnsmasq
```

Sonra router'ın DHCP ayarlarında birincil DNS olarak `192.168.1.50`, ikincil olarak `1.1.1.1` ver. Sunucu kapalıyken cihazlar internete ikincil DNS üzerinden çıkmaya devam eder.

**Her iki seçenekte kontrol edilecekler:**
- Windows'ta Chrome/Edge: Ayarlar → Gizlilik → **"Güvenli DNS kullan" kapalı** olmalı, yoksa yerel DNS atlanır.
- Android: Ayarlar → Ağ → **Özel DNS "Kapalı" veya "Otomatik"** olmalı.
- Sabit Windows bilgisayarlarda ek güvence olarak `C:\Windows\System32\drivers\etc\hosts` dosyasına şu satır eklenebilir:
  ```
  192.168.1.50  uretim.alanadi.com
  ```

Test (fabrikadaki bir bilgisayardan):
```
nslookup uretim.alanadi.com      → 192.168.1.50 dönmeli
```

---

## 10. İlk çalıştırma

```bash
cd /opt/uretim
dc build
dc run --rm app php artisan key:generate --show     # çıkan değeri .env içindeki APP_KEY'e yaz
dc up -d
dc exec app php artisan migrate --force
dc exec app php artisan db:seed --class=RolesAndPermissionsSeeder --force
dc exec app php artisan app:create-admin            # ilk yönetici kullanıcısını oluşturur
dc exec app php artisan optimize
dc ps                                               # tüm servisler "running/healthy" olmalı
```

Fabrikadaki bir bilgisayardan `https://uretim.alanadi.com` aç, yönetici ile giriş yap.

---

## 11. Cloudflare Tunnel ve erişim kontrolü (dışarıdan erişim)

1. Cloudflare Zero Trust paneli → Networks → Tunnels → **Create a tunnel** → Cloudflared.
2. Tünele isim ver (`tem-uretim`), verilen **token**'ı `.env` içindeki `TUNNEL_TOKEN` alanına yaz.
3. **Public hostname** ekle: `uretim.alanadi.com` → Service: `http://app:80`
4. `dc up -d` ile cloudflared konteynerini başlat; panelde tünel "Healthy" görünmeli.
5. Zero Trust → Access → Applications → **Add application → Self-hosted** → domain `uretim.alanadi.com` → Policy: *Allow*, kural: *Emails* → yöneticinin e-posta adresleri.

Sonuç: Dışarıdan gelen kişi önce Cloudflare'in e-posta doğrulamasından geçer, sonra uygulamanın kendi girişini görür. Fabrika içindekiler yerel DNS sayesinde doğrudan sunucuya gider; Cloudflare'e hiç uğramaz, internet kesikken de çalışır. Her iki durumda adres aynıdır.

---

## 12. Yedekleme

Strateji: her gece veritabanı dökümü + yüklenen dosyalar → (1) sunucuda, (2) harici USB diskte, (3) şifreli olarak Backblaze B2'de. 30 günlük + 12 aylık saklama.

### 12.1 Harici disk

```bash
lsblk                              # diski bul, ör. /dev/sdb1
sudo mkfs.ext4 -L yedek /dev/sdb1  # DİKKAT: diski siler
sudo mkdir -p /mnt/yedek
sudo blkid /dev/sdb1               # UUID'yi kopyala
echo "UUID=BURAYA-UUID /mnt/yedek ext4 defaults,nofail 0 2" | sudo tee -a /etc/fstab
sudo mount -a && sudo chown uretim:uretim /mnt/yedek
```

`nofail` sayesinde disk takılı değilse sunucu açılışı takılmaz.

### 12.2 Backblaze B2 ve restic

B2'de özel (private) bir bucket ve yalnızca o bucket'a erişen bir Application Key oluştur.

`/etc/uretim-yedek.env` (sahibi root, izin 600):
```
RESTIC_REPOSITORY=s3:https://s3.BOLGE.backblazeb2.com/BUCKET-ADI
RESTIC_PASSWORD=COK_UZUN_RASTGELE_SIFRE
AWS_ACCESS_KEY_ID=B2_KEY_ID
AWS_SECRET_ACCESS_KEY=B2_APPLICATION_KEY
HC_YEDEK_URL=https://hc-ping.com/YEDEK-CHECK-UUID
```

```bash
sudo chmod 600 /etc/uretim-yedek.env
sudo bash -c 'set -a; source /etc/uretim-yedek.env; restic init'
```

> **RESTIC_PASSWORD'ü şifre yöneticisine ve basılı olarak güvenli bir yere de kaydet.** Bu şifre kaybolursa buluttaki yedekler kullanılamaz.

### 12.3 Zamanlama

Proje içindeki `deploy/scripts/backup.sh` betiği: veritabanı dökümünü alır, dosyaları arşivler, USB diske kopyalar, B2'ye gönderir, eski yedekleri temizler ve sonucu healthchecks.io'ya bildirir.

```bash
sudo crontab -e
```
```
# Her gece 02:30 yedek
30 2 * * * /opt/uretim/deploy/scripts/backup.sh >> /var/log/uretim-yedek.log 2>&1
# Her 5 dakikada "sunucu ayakta" sinyali
*/5 * * * * curl -fsS -m 10 --retry 3 https://hc-ping.com/CANLI-CHECK-UUID > /dev/null
```

healthchecks.io'da iki kontrol oluştur:
- **Yedek**: periyot 1 gün, grace 2 saat
- **Canlı**: periyot 5 dk, grace 30 dk

İkisi için de e-posta (ve istersen Telegram) bildirimi aç. Böylece yedek alınmazsa ya da sunucu/internet uzun süre giderse haberin olur.

İlk yedeği elle çalıştırıp kontrol et:
```bash
sudo /opt/uretim/deploy/scripts/backup.sh
sudo bash -c 'set -a; source /etc/uretim-yedek.env; restic snapshots'
ls -lh /mnt/yedek/uretim/
```

---

## 13. Güncelleme

Mesai dışında, Tailscale üzerinden bağlanarak:

```bash
cd /opt/uretim
./deploy/scripts/deploy.sh
```

Betik sırasıyla: yedek alır → `git pull` → imajı yeniden derler → konteynerleri yeniler → migration'ları çalıştırır → önbellekleri yeniler → uygulamanın sağlık kontrolünü yapar. Sağlık kontrolü başarısız olursa hata verip durur; bu durumda son yedekten dönmek için Bölüm 14'e bak.

---

## 14. Geri yükleme ve felaket kurtarma

### 14.1 Aynı sunucuda geri yükleme

```bash
cd /opt/uretim
ls /var/backups/uretim/                         # veya /mnt/yedek/uretim/
./deploy/scripts/restore.sh /var/backups/uretim/db-20260924-0230.dump
```

Buluttan dosya çekmek gerekirse:
```bash
sudo bash -c 'set -a; source /etc/uretim-yedek.env; restic snapshots; restic restore latest --target /tmp/geri'
```

### 14.2 Sunucu tamamen bozulursa (hedef: 1-2 saat)

1. Yeni mini PC'ye Bölüm 2-9'u uygula (BIOS, Ubuntu, Docker, Tailscale, ufw, NUT, DNS).
2. Bölüm 8'deki gibi projeyi klonla; `.env` dosyasını şifre yöneticisindeki kopyadan geri koy.
3. `/etc/uretim-yedek.env` dosyasını geri koy.
4. `dc build && dc up -d`
5. Son yedeği USB diskten veya `restic restore` ile al, `restore.sh` ile yükle.
6. Router'da sabit IP atamasını yeni cihazın MAC adresine taşı.

> **`.env` ve `/etc/uretim-yedek.env` dosyalarının güncel kopyalarını şifre yöneticisinde sakla.** Kurtarmanın en çok takıldığı nokta bunlardır.

### 14.3 Geri yükleme tatbikatı

Ayda bir, test bilgisayarında veya ayrı bir klasörde son yedeği geri yükleyip uygulamanın açıldığını ve son günün kayıtlarının yerinde olduğunu kontrol et. Tarih ve süreyi not al.

---

## 15. Teslim öncesi kontrol listesi

- [ ] BIOS: elektrik gelince otomatik açılma
- [ ] Sabit IP atandı
- [ ] Otomatik güvenlik güncellemeleri açık
- [ ] ufw aktif, router'da port yönlendirme yok
- [ ] Tailscale bağlı, key expiry kapalı
- [ ] UPS: `upsc` veri gösteriyor; fişi çekip test edildi (sunucu düzgün kapandı ve elektrik gelince açıldı)
- [ ] `https://uretim.alanadi.com` fabrikadaki PC, tablet ve telefondan açılıyor
- [ ] Router internet kablosu çıkarılıp test edildi: uygulama yerel ağda çalışmaya devam ediyor
- [ ] Telefon kamerasıyla QR okutma çalışıyor
- [ ] USB barkod okuyucu test edildi
- [ ] Dışarıdan erişim Cloudflare Access e-posta doğrulamasıyla çalışıyor
- [ ] İlk yedek alındı: sunucu, USB disk ve B2'de görünüyor
- [ ] healthchecks.io iki kontrol de yeşil, bildirim e-postası test edildi
- [ ] Geri yükleme tatbikatı bir kez yapıldı
- [ ] `.env`, yedek şifresi ve restic şifresi şifre yöneticisinde
- [ ] Yönetici, operatör ve depo kullanıcıları oluşturuldu; yetkiler test edildi
- [ ] Kesinti durumunda kullanılacak basılı kayıt formu fabrikaya teslim edildi
