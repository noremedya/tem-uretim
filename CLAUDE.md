# Tem Motor Üretim Takip Sistemi — Claude Code Proje Talimatları

Bu dosya projenin kalıcı talimatlarıdır. Her oturumda baştan oku ve buradaki kurallara uy.

## 1. Proje özeti

Bir elektrik motoru üreticisi (Tem Motor) için üretim takip web uygulaması. Fabrika içindeki bir mini PC sunucuda Docker ile çalışır; kullanıcılar yerel ağ üzerinden tarayıcıyla (PC, tablet, telefon) bağlanır. **Uygulama internet olmadan tam çalışmak zorundadır** (tek istisna e-dönüşüm gönderimleridir, bkz. bölüm 3 kural 1). Bulut yalnızca yedekleme ve Cloudflare Tunnel ile uzaktan erişim için kullanılır.

Kapsam:
- İş emri oluşturma, atama, üretim aşaması takibi (planlandı / üretimde / tamamlandı / iptal)
- Motor modeli, seri no ve üretim partisi bazlı kayıt
- Stok takibi muhasebe kodlarıyla: hammadde, komponent, direkt/endirekt sarf, yarı mamul, mamul; kritik stok, hareketsiz stok ve yetersiz malzeme uyarıları
- Müşteri ve sipariş kaydı, sipariş–iş emri–motor ilişkilendirme
- Rol bazlı yetkilendirme: yönetici, saha operatörü, depo, muhasebe
- Günlük/haftalık/aylık üretim ve verimlilik raporları, Excel'e aktarım
- Barkod/QR ile hızlı işlem (USB okuyucu + telefon kamerası)
- Denetim izi (kim neyi ne zaman değiştirdi)
- Ön muhasebe (Mikro'nun yerine): cari hesaplar (müşteri ve tedarikçi), fiyat/iskonto/KDV, döviz, alış ve satış faturası, tahsilat ve ödeme, mali müşavire (Luca) aktarım
- e-Dönüşüm: Uyumsoft üzerinden e-fatura, e-arşiv, e-irsaliye; gelen e-faturaların alış kaydına ve stok girişine otomatik bağlanması

## 2. Teknoloji

- PHP 8.4, Laravel güncel kararlı sürüm
- Filament güncel kararlı sürüm (v4 veya üstü) — tüm yönetim arayüzü
- PostgreSQL 17
- Kuyruk, önbellek ve oturum sürücüsü: `database` (Redis kullanma; hareketli parça sayısını az tut)
- spatie/laravel-permission (roller/yetkiler), spatie/laravel-activitylog (denetim izi)
- Pest (testler)
- QR/barkod kamera okuma: `@zxing/browser` npm paketi (QR ve 1D barkod; html5-qrcode 2023'ten beri bakımsız olduğu için seçilmedi), Vite ile derlenip pakete dahil edilir. Kamera yalnızca HTTPS'te (geliştirmede localhost) çalışır.
- e-Dönüşüm: Uyumsoft e-Dönüşüm SOAP servisi (`https://edonusumapi.uyum.com.tr/`, SOAP 1.1, WS-Security UsernameToken), PHP `soap` eklentisi; WSDL/XSD dosyaları repoda tutulur (çalışma anında internetten çekilmez), kimlik bilgileri yalnızca `.env`'de
- Üretim sunucusu: Docker Compose, FrankenPHP tabanlı uygulama imajı, önde Caddy (Cloudflare DNS modülüyle), cloudflared
- Paket eklemeden önce güncel Laravel/Filament sürümüyle uyumluluğunu kontrol et; uyumsuzsa bana sor.

## 3. Değişmez kurallar

1. **Hiçbir CDN veya harici kaynak kullanma.** Panelde ortak JS (Alpine bileşenleri) `resources/js/filament/app.js` üzerinden Vite ile yüklenir; yeni ortak bileşenler buraya kaydedilir. Font, JS, CSS, ikon dahil her şey Vite ile derlenip uygulamayla birlikte gelmeli. İnternet kesikken arayüz eksiksiz çalışmalı.
   - **Bilinçli istisna — e-dönüşüm:** Uyumsoft'a gönderim, durum sorgulama ve gelen fatura çekme internet ister. Belgeler internetsiz kesilip kaydedilir ve kuyruğa alınır; internet gelince kuyruktan gönderilir. Fabrikanın geri kalanı bundan etkilenmez.
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
- **parts** (stok kartı): code (unique), name, unit_id, type (enum, muhasebe koduyla: raw_material 150.01 hammadde / component 150.02 komponent / direct_consumable 150.03 direkt sarf / indirect_consumable 157 endirekt sarf / semi_finished 151 yarı mamul / finished_good 152 mamul; kodlar `config/accounting.php`'de), barcode (unique, nullable), critical_level (decimal, >= 0; 0 = takip yok), is_active, lock_version. Hareketi olan parçanın birimi değiştirilemez. Mamul (finished_good) kartı elle açılamaz ve hiçbir karta mamul türü verilemez/mamul türünden çıkarılamaz (Aşama 4'te motor modelinden oluşur).
- **stock_balances**: part_id (unique), quantity (decimal, >= 0), is_below_critical (bool — kritik bildirimi gönderildi mi), updated_at — hızlı okuma için; tek yazıcısı StockService. Parça oluşturulurken 0 bakiyeyle açılır.
- **stock_movements**: part_id, type (enum: stock_in, stock_out, production_consumption, production_scrap, production_output, shipment, count_adjustment, return, correction — giriş, çıkış, üretim sarfı, üretim firesi, üretimden giriş, sevk, sayım düzeltme, iade, ters kayıt), quantity (işaretli decimal, 0 olamaz), balance_before, balance_after, reference (morph: work_order vb., nullable), corrected_movement_id (nullable, unique — ters kaydedilen hareket), idempotency_key (uuid, nullable, unique — çift gönderim koruması), description, user_id, created_at. Update/delete/truncate yok (veritabanı trigger'ı ile de engellenir).
- **motor_models**: code (unique), name, teknik alanlar (hepsi isteğe bağlı): power_kw (güç, kW, decimal), speed_rpm (devir, d/dk, integer), voltage (gerilim, metin), frequency_hz (frekans, Hz), pole_count (kutup sayısı), frame_size (gövde ölçüsü, metin), phase (enum: single_phase / three_phase), mounting_type (montaj şekli, metin, ör. B3/B5/B14), protection_class (koruma sınıfı, metin, ör. IP55), efficiency_class (verim sınıfı, metin, ör. IE3); description, is_active, lock_version, finished_part_id (unique — modelin mamul kartı, model oluşturulunca otomatik açılır)
- **bom_items** (ürün reçetesi, stok kartına bağlı): parent_part_id (yarı mamul veya mamul kartı), component_part_id (endirekt sarf ve mamul olamaz), quantity_per_unit (> 0), scrap_rate (standart fire yüzdesi, 0 ≤ oran < 100); (parent_part_id, component_part_id) unique, parent ≠ component. Motorun reçetesi mamul kartının reçetesidir.
- **customers**: name (ad/ünvan, zorunlu), tax_number (isteğe bağlı; doluysa unique, 10–11 rakam ve kontrol hanesi geçerli — VKN/TCKN), tax_office (vergi dairesi; vergi no ile birlikte: biri doluysa diğeri de zorunlu), phone (zorunlu), email, address, is_active, lock_version, is_customer ve is_supplier (cari türü; en az biri), payment_term_days (vade günü, 0–365), default_currency (TRY/EUR/USD). Arayüzde "cari hesap".
- **exchange_rates** (döviz kurları): currency (EUR/USD), rate_date, forex_buying ve forex_selling (> 0, 6 ondalık), source (tcmb/manual), user_id (nullable), lock_version; (currency, rate_date) unique
- **invoices** (faturalar): direction (sales/purchase — satış/alış), kind (normal/return — fatura/iade), status (draft/issued/cancelled), customer_id (cari), invoice_number (bizim kestiğimiz belgede `FTR-{YIL}-{SIRA:6}`, kesilince verilir; nullable unique), counterparty_number (karşı tarafın kestiği belgede onun belge no'su; (customer_id, counterparty_number) unique), original_invoice_id (iadede, nullable), invoice_date, due_date, currency, exchange_rate (6 ondalık; TRY'de 1), subtotal, discount_total, vat_total, grand_total, grand_total_try (2 ondalık; kesilince sabitlenir), notes, issued_at, issued_by_user_id, cancelled_at, cancellation_reason, lock_version
- **invoice_lines**: invoice_id, part_id (alış satırında, nullable), motor_model_id (motor satırında, nullable), description, quantity (> 0), unit, unit_price (≥ 0, 4 ondalık), discount_rate (0–100), vat_rate (0–100), line_gross, discount_amount, line_net, vat_amount, line_total (2 ondalık)
- **invoice_line_motor_units**: invoice_line_id, motor_unit_id — satış satırının seri numaraları; bir motor iptal edilmemiş tek bir satış faturasında yer alır (servis + trigger)
- **invoice_line_stock_movements**: invoice_line_id, stock_movement_id — alış satırının bağlı olduğu stok girişleri; bir giriş iptal edilmemiş tek bir alış faturasına bağlanır (servis + trigger)
- **account_transactions** (cari hareketleri; değiştirilemez ve silinemez, trigger): customer_id, currency, type (enum: opening, invoice, payment, cheque_bounced, correction), amount (işaretli, 2 ondalık; + cari borç = bizim alacağımız, − cari alacak), transaction_date, due_date (borç/alacak doğuran belgede), reference (morph: invoice, payment, cheque), corrected_transaction_id (nullable, unique), description, user_id, created_at
- **cash_accounts** (kasa ve banka hesapları): kind (cash/bank), name, currency, bank_name, branch, iban (bankada), is_active, lock_version; para birimi başına tek kasa
- **cash_movements** (kasa/banka hareketleri; değiştirilemez ve silinemez): cash_account_id, type (enum: opening, payment, cheque_collected, cheque_paid, correction), amount (işaretli; + giriş, − çıkış), transaction_date, reference (morph), corrected_movement_id (nullable, unique), description, user_id, created_at
- **payments** (tahsilat/ödeme): direction (received/made — tahsilat/ödeme), customer_id, payment_date, method (cash, bank, cheque, note, endorsement — nakit, banka, çek, senet, ciro), cash_account_id (nakit/bankada), cheque_id (çek/senet/ciroda), amount ve currency (ödemenin kendisi), account_currency, exchange_rate (ödeme → cari para birimi), account_amount, description, status (posted/cancelled), cancelled_at, cancellation_reason, user_id, lock_version
- **payment_allocations** (elle faturaya bağlama): payment_id, invoice_id, amount
- **cheques** (çek/senet): direction (received/issued — alınan/verilen), kind (cheque/note — çek/senet), customer_id, number, bank_name, drawer (keşideci), due_date, amount, currency, status (portfolio, collected, endorsed, bounced, paid, cancelled — portföyde, tahsil edildi, ciro edildi, karşılıksız, ödendi, iptal), cash_account_id (tahsil/ödeme hesabı), lock_version
- **prices** (satış fiyatları): motor_model_id, customer_id (nullable — boşsa liste fiyatı), currency, unit_price (> 0, 2 ondalık), valid_from, lock_version; (motor_model_id, customer_id, valid_from) unique
- **orders**: order_number (unique, otomatik), customer_reference (müşteri sipariş no, isteğe bağlı, aranabilir), customer_id, order_date, due_date (zorunlu, >= order_date), status (enum: open, partial, completed, cancelled — açık, kısmi, tamamlandı, iptal), cancellation_reason (iptalde zorunlu), notes, lock_version
- **order_items**: order_id, motor_model_id, quantity (tam sayı, > 0); (order_id, motor_model_id) unique
- **number_sequences** (numara sayaçları): name, scope, last_value; (name, scope) unique — bkz. bölüm 5 "Numara üretimi"
- **production_batches** (parti): batch_number (unique, elle girilir), date, notes, lock_version
- **work_orders**: work_order_number (unique, otomatik), product_part_id (üretilen kart: yarı mamul veya mamul), motor_model_id (nullable; mamulde dolu), order_item_id (nullable; yalnızca mamulde, kalemin modeli aynı olmalı), quantity (> 0; birimin küsurat kuralına uyar), production_batch_id (nullable), status (enum: planned, in_progress, completed, cancelled — planlandı, üretimde, tamamlandı, iptal), assigned_user_id (nullable; aktif saha operatörü), planned_start (tarih, isteğe bağlı), planned_end (tarih, zorunlu, >= planned_start), started_at, completed_at, cancellation_reason (iptalde zorunlu), notes, lock_version
- **work_order_materials** (iş emrinin malzeme listesi; oluşturulurken reçeteden kopyalanır): work_order_id, part_id, quantity_per_unit, scrap_rate, required_quantity (adet × birim başına), planned_scrap (standart fire), actual_scrap (tamamlamada girilen fire, nullable); (work_order_id, part_id) unique
- **work_order_status_histories**: work_order_id, from_status (oluşturmada null), to_status, user_id, description, created_at. Update/delete yok (veritabanı trigger'ı ile de engellenir).
- **motor_units** (üretilen motor): serial_number (unique), motor_model_id, work_order_id, production_batch_id, order_id (nullable), production_date, status (enum: in_stock, shipped, voided — stokta, sevk edildi, iptal edildi), shipped_at, shipped_by_user_id, notes. Silinmez; seri no tekrar kullanılmaz.

Tarih, serial_number, status, motor_model_id, part_id gibi filtrelenen alanlara index ekle.

## 5. İş kuralları

**İş emri durum geçişleri**
- planlandi → uretimde (atanan operatör veya yönetici)
- uretimde → tamamlandi (atanan operatör veya yönetici)
- planlandi → iptal (yönetici)
- uretimde → planlandi (yalnızca yönetici, açıklama zorunlu)
- tamamlandi → uretimde yalnızca **geri alma** akışıyla (aşağıda); iptal son durumdur
- Her geçiş `work_order_status_histories`'e yazılır.

**İş emri**
- Bir stok kartı üretir: yarı mamul veya mamul. Mamul iş emri siparişin aynı modeldeki kalemine bağlanabilir. Numara: `IE-{YIL}-{SIRA:5}`.
- **Malzeme listesi** oluşturulurken reçeteden `work_order_materials`'a kopyalanır; reçete sonradan değişse de açık iş emri etkilenmez. `planned` iş emrinde "reçeteyi yenile" ile güncel reçete yeniden kopyalanır. Reçetesi olmayan karta iş emri açılamaz.
- **Atama:** atanan operatör isteğe bağlıdır ve sonradan atanabilir; yalnızca aktif saha operatörleri atanabilir. Atanmamış iş emrini yalnızca yönetici başlatır ve tamamlar.
- **Tarihler:** planlanan bitiş zorunlu (gecikme buna göre hesaplanır), planlanan başlangıç isteğe bağlı.
- **İptal** (`planned` → `cancelled`) açıklaması zorunludur; `cancellation_reason`'a ve durum geçmişine yazılır.
- **Sipariş kalemi kotası:** bir kaleme birden fazla iş emri bağlanabilir; iptal edilmemiş iş emirlerinin toplam adedi kalem adedini aşamaz (kalem satırı kilitlenerek kontrol edilir). Fazla üretim siparişe bağlanmadan yapılır. İptal edilmemiş iş emri olan kalem silinemez, miktarı bu iş emirlerinin toplamının altına inemez. Yalnızca `open`/`partial` siparişin kalemine iş emri bağlanır.
- **Standart fire** = ihtiyaç × oran / 100; tam sayılı birimde yukarı yuvarlanır (ör. 4 civata × %2 → 1). Operatör tamamlamada gerçek fireyi girer.
- Reçetesinde pasif bileşen olan karta iş emri açılamaz (pasif parça hareket görmez).
- Adet yönetici tarafından `planned` ve `in_progress` iken değiştirilebilir (malzeme ihtiyacı yeniden hesaplanır). **Kısmi tamamlama yoktur**: az üretildiyse önce adet düşürülür.
- **Yetersiz malzeme uyarısı:** iş emri ekranında her malzeme için ihtiyaç (fire dahil), mevcut ve eksik gösterilir. Uyarıdır; oluşturma ve başlatmayı engellemez, başka iş emirlerinin ihtiyacını hesaba katmaz.

**İş emri tamamlanınca (tek transaction):**
1. Operatör her malzeme için gerçekleşen fireyi girer (varsayılan: standart fire). Net ihtiyaç (`adet × birim başına`) `production_consumption`, fire `production_scrap` hareketiyle düşülür.
2. Herhangi bir malzeme yetersizse (yarı mamul dahil) işlem tamamen iptal edilir; hangi malzemeden ne kadar eksik olduğu kullanıcıya listelenir. Eksik yarı mamul otomatik üretilmez.
3. Üretilen kart `production_output` hareketiyle stoğa girer.
4. Mamulde `adet` kadar `motor_units` kaydı oluşturulur. Seri no formatı yapılandırılabilir (varsayılan: `{MODEL_KODU}-{YY}{AA}-{SIRA:5}`; sıra model ve ay bazında sıfırlanır), sıra numarası çakışmasız üretilir (`NumberSequenceService`, bkz. "Numara üretimi").
5. İş emri siparişe bağlıysa motorlar siparişe bağlanır, sipariş durumu yeniden hesaplanır (hiç motor yoksa açık, eksik kalem varsa kısmi, tüm kalemler kadar motor üretildiyse tamamlandı; iptal edilmiş motorlar sayılmaz). Sipariş bu arada iptal edildiyse motorlar siparişe bağlanmaz.

**İş emri geri alma** (yalnızca yönetici, açıklama zorunlu, tek transaction): yalnızca tamamlanmış iş emri; motorların hepsi stokta olmalı (sevk edilmiş motor varsa reddedilir). Sarf, fire ve üretimden giriş hareketleri ters kaydedilir (`correction`); üretilen yarı mamul bu arada tüketildiyse ters kayıt stoğu eksiye düşüreceği için reddedilir. Motorlar `voided` olur, iş emri `in_progress`'e döner, sipariş durumu yeniden hesaplanır.

**Reçete**
- Üst kart yarı mamul veya mamul; bileşen endirekt sarf veya mamul olamaz. Direkt sarf reçeteye girer.
- Döngü yasaktır (A, B'yi içerir; B, A'yı içeremez — dolaylı dahil); kontrol kilit altında yapılır.

**Mamul ve yarı mamul**
- Motor modeli oluşturulunca mamul kartı otomatik açılır: kod ve ad modelinkiyle aynı, birim "Adet", tür `finished_good`. Model kodu/adı değişince kart da değişir; model pasifleşince kart pasifleşir.
- Mamul kartında elle hareket yoktur: yalnızca `production_output`, `shipment` ve geri almadaki `correction` (servis + veritabanı trigger'ı). Kural: **mamul bakiyesi = o modelin `in_stock` motor sayısı**; `stock:reconcile` bunu da kontrol eder.
- Yarı mamulde lot/seri takibi yoktur; yarı mamul satılmaz (sipariş kalemi yalnızca motor modeline bağlıdır).

**Sevk** (yönetici ve depo, yetki `motor_units.ship`): stoktaki motorlar seçilip sevk edilir (isteğe bağlı sevk notu); motor `shipped` olur, mamul kartından motor başına bir `shipment` hareketiyle düşülür. Seçilenlerden biri stokta değilse hiçbiri sevk edilmez. Pasifleştirilen modelin stoktaki motorları da sevk edilebilir (pasif parçada yalnızca sayım kuralının istisnası; mamulde sayım olmadığından stok başka türlü çıkamaz). E-irsaliye bağlantısı Aşama 6'da eklenir.

**Stok**
- Bakiye hiçbir zaman negatife düşemez (servis + check constraint).
- Her hareketten sonra bakiye `kritik_seviye`'nin altına inerse yönetici ve depo rollerine Filament veritabanı bildirimi gönderilir. Aynı parça için bakiye kritik seviyenin üstüne çıkana kadar tekrar bildirim gönderilmez.
- Hareket tiplerinin yönü: `stock_in` ve `return` (+), `stock_out` ve `production_consumption` (−), `count_adjustment` ve `correction` (±). `return` üretimden/müşteriden depoya dönüştür; **tedarikçiye iade `stock_out` + açıklamayla** yapılır.
- Açıklama zorunlu: `stock_out`, `count_adjustment`, `correction`. `stock_in` ve `return`'de isteğe bağlı.
- **Sayım:** kullanıcı sayılan miktarı girer, sistem farkı hesaplar; fark 0 ise hareket oluşmaz, "bakiye zaten doğru" mesajı gösterilir.
- **Kritik seviye:** `bakiye <= critical_level` ise parça kritiktir. `critical_level = 0` takip yok demektir, bildirim gitmez.
- **Pasif parçaya** yalnızca sayım (`count_adjustment`) yapılabilir; diğer tüm hareketler reddedilir (istisna: pasif mamulün sevki, bkz. "Sevk").
- **Küsurat:** birimi `allows_decimal = false` olan parçada küsuratlı miktar (hareket miktarı, sayılan miktar) servis seviyesinde reddedilir.
- **Ters kayıt (`correction`):** miktar orijinal hareketin tam tersidir; bir hareket yalnızca bir kez ters kaydedilebilir (`corrected_movement_id` unique); ters kaydın kendisi ters kaydedilemez; stoğu eksiye düşürecekse reddedilir. Bu kurallar servis + veritabanı (unique, insert trigger'ı) seviyesinde zorlanır.
- **Üretim hareketleri (`production_consumption`, `production_scrap`, `production_output`) ve `shipment` elle ters kaydedilemez.** Motor kayıtlarıyla tutarlılık için yalnızca iş emri geri alma akışı bunları ters kaydeder.
- **Çift gönderim:** stok işlemi formu işlem sırasında butonu pasifleştirir ve formun ikinci kez gönderilmesini tarayıcıda engeller (Alpine `submitGuard`, bkz. bölüm 7); sunucu tarafında her form gönderimi bir `idempotency_key` taşır, aynı anahtarla ikinci hareket oluşmaz. Koruma yalnızca bu anahtara dayanır: aynı anahtarlı ikinci istek hareket oluşturmaz, ilk işlemin sonucunu gösterir ve bildirimi tekrarlamaz. Başarılı işlemden sonra form sıfırlanır (miktar ve açıklama temizlenir, tür ve parça kalır) ve yeni anahtar üretilir; aynı parça ve miktarla bilinçli ikinci işlem (ör. iki ayrı teslimat) yeni hareket oluşturur. İşlem sonrası kullanıcıya yeni bakiye gösterilir.
- Stok hareketleri listesini yönetici ve depo görür; operatör görmez.
- **Kart türü** değiştirilebilir (yanlış sınıflanan kartı düzeltmek için) ve denetim izine yazılır; mamul türü bu kuralın dışındadır (bkz. bölüm 4).
- **Hareketsiz stok uyarısı:** aktif, bakiyesi > 0 olan ve son 90 gündür (`config/stock.php`, yapılandırılabilir) hiç hareket görmeyen parçalar haftalık **özet** bildirimiyle yönetici ve depoya bildirilir (zamanlanmış komut `stock:idle-report`, scheduler'da haftada bir). Hareketsiz parça yoksa bildirim gönderilmez. Parça listesinde "Hareketsiz" filtresi bulunur.
- **Yetersiz malzeme uyarısı** (Aşama 4): iş emrinin reçete ihtiyacı (fire dahil) mevcut stoğu aşıyorsa iş emri ekranında hangi malzemeden ne kadar eksik olduğu gösterilir; tamamlama zaten bölüm 5'teki kuralla engellenir.
- Günlük zamanlanmış komut `stock:reconcile`: her parça için hareket toplamı ile `stock_balances` karşılaştırılır; fark varsa loglanır ve yöneticiye bildirim gider (otomatik düzeltme yapmaz).

**Üretim yapısı**
- Yarı mamuller (ör. gövde, kapak/flanş, rotor, bobinajlı stator, işlenmiş mil) fabrikada üretilir ve modele özeldir (ör. 0,37 kW 1500 d/dk 71 tip 60 mm stator) → çok seviyeli reçete gerekir.
- Mamul ayrı bir stok kartıdır (motor_units'e ek olarak).
- Direkt sarf reçeteye girer ve üretimde otomatik düşer; endirekt sarf reçeteye girmez.
- Fire: reçetede her malzeme için standart fire oranı; operatör tamamlamada gerçekleşen fireyi girebilir. Fire, üretim sarfından ayrı bir hareket tipiyle kaydedilir ve muhasebe aktarımına yansır.
- Ayrıntılı kurallar: yukarıdaki "İş emri", "Reçete", "Mamul ve yarı mamul", "Sevk".
- Açık soru: fabrikada sistem öncesinden stokta bitmiş motor varsa açılış kaydı nasıl yapılacak (mamulde elle giriş kapalı).

**Cari, döviz ve fiyat** (Aşama 5a)
- Cari hesap = `customers` kaydı; müşteri ve/veya tedarikçi işaretlidir (en az biri, check constraint). Siparişe yalnızca müşteri işaretli aktif cari seçilir (servis + trigger).
- Para birimleri: TRY, EUR, USD (`Currency` enum). Cari bakiyesi para birimi bazında ayrı tutulur; raporlarda TL karşılığı da gösterilir.
- **Kur:** TCMB `today.xml`'den döviz alış ve döviz satış kurları `exchange-rates:fetch` ile çekilir (iş günleri 16:00–22:00 saatte bir; o tarihin kuru varsa bir şey yapmaz). İnternet yoksa uyarı loglanır, komut çökmez; kur elle girilir veya düzeltilir (yönetici, muhasebe). Bir tarihin kuru = o tarih ve öncesindeki en son kur. Faturada kullanılan tür `config/accounting.php` `invoice_rate_type` ile seçilir; varsayılan döviz satış. **Mali müşavirle teyit edilecek:** VUK 280 değerlemede döviz alış kurunu öngörür. Fatura kuru fatura tarihinde faturaya kopyalanır ve taslakta düzeltilebilir.
- **Fiyat:** motor modeli liste fiyatı (müşterisiz) ve müşteriye özel fiyat; para birimi ve geçerlilik başlangıcıyla. Etkin fiyat = tarihe kadar başlamış en son kayıt; önce müşteri fiyatı, yoksa liste fiyatı. Geçerliliği başlamış fiyat değiştirilemez ve silinemez (yeni fiyat kaydı açılır); yalnızca ileri tarihli fiyat düzenlenir veya silinir.
- Cari, kur ve fiyat değişiklikleri denetim izine yazılır.

**Ön muhasebe ve e-dönüşüm** (Aşama 5b–5d ve 6 planlarında ayrıntılanır)
- Mikro bırakılır; cari takibi bu sistemde yapılır.
- **Satış faturası** sevk edilen motorlardan oluşturulur (seri numaralarıyla; bir motor bir kez faturalanır); elle satır (nakliye, hizmet) eklenebilir. **Alış faturası** depoda yapılmış stok girişlerine bağlanır; bağlanacak giriş yoksa fatura kaydedilirken stok girişi oluşur (aynı mal iki kez girilmez).
- Fatura durumları: taslak (düzenlenebilir, silinebilir) → kesildi (kilitli) → iptal (açıklama zorunlu). Kesilmiş fatura silinmez. E-fatura numarası Aşama 6'da Uyumsoft'tan gelir.

**Faturalar** (Aşama 5b)
- Belge türleri: satış faturası, alış faturası, satış iadesi, alış iadesi. **Bizim kestiğimiz** belgeler (satış faturası, alış iadesi) kesilince `FTR-{YIL}-{SIRA:6}` numarası alır (tek seri). **Karşı tarafın kestiği** belgeler (alış faturası, satış iadesi) karşı tarafın belge numarasıyla kaydedilir (kesmeden önce zorunlu; aynı cari için tekrar edemez).
- Cari: satış faturası ve satış iadesi müşteri işaretli, alış faturası ve alış iadesi tedarikçi işaretli aktif cariye kesilir.
- Vade = fatura tarihi + carinin vade günü (değiştirilebilir). Para birimi carinin varsayılanıyla gelir. Dövizli faturada satırlar faturanın para biriminde; kur fatura tarihinin kuruyla dolar, taslakta düzeltilebilir; TL karşılığı kesilince sabitlenir.
- Hesaplama (satır): brüt = miktar × birim fiyat; iskonto = brüt × oran; net = brüt − iskonto; KDV = net × KDV oranı; toplam = net + KDV. Her tutar kuruşa yuvarlanır (yarım yukarı); fatura toplamları satırların toplamıdır.
- **Satış faturası** satırları sevk edilmiş motorlardan oluşur (model başına satır, seri numaraları satıra bağlı); birim fiyat önerisi etkin fiyattan (aynı para birimindeyse). Elle satır (nakliye, hizmet) eklenebilir. Bir motor iptal edilmemiş tek bir satış faturasında yer alır; fatura iptal edilirse motor tekrar faturalanabilir. Motorun müşterisi (siparişi varsa) fatura carisiyle aynı olmalı.
- **Alış faturası** satırı bir parçaya aittir ve aynı parçanın, henüz faturaya bağlanmamış stok girişlerine bağlanır; bağlı girişlerin toplamı satır miktarına eşit olmalı. Bağlanacak giriş seçilmezse fatura kesilirken satır miktarı kadar stok girişi oluşur (referans fatura). Alış faturası iptal edilirse kesimde oluşan girişler ters kaydedilir (stok yetmezse iptal reddedilir); önceden var olan girişlerin bağı çözülür.
- **İadeler** 5b'de yalnızca tutar düzeltmesidir (stok ve motor durumu değişmez); orijinal faturaya bağlanabilir. Satış iadesinde motorun fiziksel olarak stoğa dönüşü açık sorudur.
- Yetki: yönetici ve muhasebe tüm faturaları yönetir; depo alış faturalarını salt okunur görür.
- İskonto satır bazında tek oran: %15, %20, %30 hazır seçenek, başka oran da girilebilir; art arda iskonto yok. KDV varsayılan %20, satırda değiştirilebilir; KDV satır bazında hesaplanıp toplanır, tutarlar kuruş hassasiyetinde (bcmath).
- Tahsilat ve ödeme: nakit (kasa), banka hesapları, çek/senet. Kasa ve banka hesaplarının bakiyesi ve hareket listesi tutulur. Cari hareketleri değiştirilemez; düzeltme ters kayıtla.

**Cari hesap hareketleri, tahsilat, ödeme** (Aşama 5c)
- Cari bakiyesi = cari hareketlerinin toplamı, para birimi bazında. İşaret: + cari borçlu (bizim alacağımız), − cari alacaklı (bizim borcumuz). Fatura kesilince hareket oluşur (satış faturası ve alış iadesi +, alış faturası ve satış iadesi −; vade = fatura vadesi); fatura iptal edilince ters kayıt. Tahsilat −, ödeme +.
- **Açılış (devir):** Mikro'daki güncel bakiyeler cari ve para birimi başına tek seferlik açılış kaydıyla girilir (borç veya alacak); kasa ve banka açılış bakiyeleri de hesap başına tek seferlik.
- **Kasa** para birimi başına bir tane (TRY, EUR, USD); **banka hesapları** elle tanımlanır (banka, şube, IBAN, para birimi). Kasa/banka bakiyesi = hareketlerin toplamı.
- **Tahsilat/ödeme yöntemleri:** nakit (kasa), banka (havale/EFT), çek, senet; ödemede ayrıca **ciro** (portföydeki alınan çekle tedarikçiye ödeme). Nakit ve bankada hesap, ödemenin para birimiyle aynı olmalı.
- **Farklı para biriminde ödeme:** ödemenin para birimi carinin hareket para biriminden farklıysa ödeme tarihindeki kurla çevrilir (kur düzeltilebilir); cari hareketi cari para biriminde, kasa/banka hareketi ödemenin para biriminde yazılır. Kur farkı faturası sonraya bırakıldı.
- **Faturalarla eşleşme:** tahsilat/ödeme istenirse belirli faturalara elle bağlanır; kalan tutar vadesi en eski açık belgeleri kapatır (FIFO). Açık belge ve vadesi geçmiş alacak hesabı her seferinde hareketlerden yapılır (önce elle bağlamalar, sonra FIFO).
- **Çek/senet:** alınan çek/senet tahsilatla portföye girer (cari alacaklanır). Portföydeki alınan çek: tahsil edildi (banka hesabına giriş), ciro edildi (tedarikçiye ödeme olarak), karşılıksız (cari tekrar borçlanır). Verilen çek/senet ödemeyle oluşur (tedarikçiye borcumuz kapanır); vadesinde ödendi olarak işaretlenir (banka hesabından çıkış).
- **İptal:** tahsilat/ödeme açıklamayla iptal edilir; cari ve kasa/banka hareketleri ters kaydedilir. Çeki tahsil edilmiş, ciro edilmiş veya ödenmiş tahsilat/ödeme iptal edilemez.
- Yetki: yönetici ve muhasebe (`finance.manage`).
- Mali müşavir Luca kullanır; dönemsel Excel aktarımı yapılır. **Biçim için mali müşavirden örnek dosya bekleniyor** (gelene kadar 5d bekler).
- Uyumsoft: e-fatura, e-arşiv, e-irsaliye. Gelen e-faturalar otomatik çekilip alış kaydına ve stok girişine bağlanır. Geliştirme Uyumsoft test ortamıyla yapılır; canlı ortama deneme belgesi gönderilmez.
- Kesilen fatura değiştirilemez; düzeltme iptal veya iade faturasıyla yapılır.
- Açık sorular: firma notundaki "Mikro'ya satış / genel satış" ayrımının anlamı; ihracat yapılıyor mu (KDV istisnası gerekir mi).

**Numara üretimi** (`NumberSequenceService`): sipariş no, iş emri no ve seri no aynı servisle üretilir.
- Numara, yapılandırılabilir bir şablondan üretilir (`config/numbering.php`). Belirteçler: `{YIL}` (4 hane), `{YY}`, `{AA}` (ay), `{SIRA:n}` (n haneli sıra) ve çağıranın verdiği bağlam değerleri (ör. `{MODEL_KODU}`).
- Sayaç kapsamı, şablonun sıra dışındaki kısmıdır: `SIP-{YIL}-{SIRA:5}` için kapsam `SIP-2026-` olduğundan sıra her yıl başında kendiliğinden sıfırlanır.
- Sayaç `number_sequences` tablosunda tek atomik sorguyla artırılır (`INSERT ... ON CONFLICT DO UPDATE ... RETURNING`); satır kilidi çağıranın transaction'ı bitene kadar tutulur. Böylece eşzamanlı işlemlerde çakışma olmaz, geri alınan işlem numara tüketmez.
- Sipariş no: `SIP-{YIL}-{SIRA:5}` (ör. SIP-2026-00001).

**Müşteri**
- Pasif müşteriye yeni sipariş açılamaz (siparişin müşterisi pasif bir müşteriyle değiştirilemez); mevcut siparişleri görünür kalır.
- Telefon zorunludur. Vergi no ve vergi dairesi birlikte girilir: biri doluysa diğeri de zorunludur (form, servis ve check constraint).
- **Vergi no kontrol hanesi** (`App\Support\TaxNumber`): 11 haneli TCKN'de ilk hane 0 olamaz, 10. hane = ((1.+3.+5.+7.+9. haneler) × 7 − (2.+4.+6.+8. haneler)) mod 10, 11. hane = (ilk 10 hanenin toplamı) mod 10. 10 haneli VKN'de son hane Gelir İdaresi algoritmasına uymalıdır. Hatalıysa: "Geçersiz vergi/TC kimlik numarası, lütfen kontrol edin." Kontrol hanesi yalnızca form ve servis seviyesindedir; veritabanı check'i yalnızca biçimi (10–11 rakam) denetler (bölüm 3 kural 5'in bilinçli istisnası).
- **Aynı ad:** aynı adla (büyük/küçük harf — Türkçe I/ı dahil — ve baştaki/sondaki boşluklar önemsiz; karşılaştırma `translate(lower(btrim(name)), 'ı', 'i')` ile) aktif bir müşteri varsa kayıt engellenmez; "Bu adla bir müşteri zaten var" uyarısı gösterilir ve kullanıcı onay kutusunu işaretleyerek devam eder. Onaysız kayıt servis tarafından reddedilir. Düzenlemede yalnızca ad değiştiğinde kontrol edilir; pasif müşteriler sayılmaz. Veritabanında unique değildir (bilinçli: aynı adlı farklı firmalar olabilir).

**Sipariş**
- En az bir kalem ve termin tarihi (`due_date`) zorunlu; `due_date >= order_date`.
- Sipariş no otomatik üretilir, formda girilmez.
- Durumlar: `open` → `partial` / `completed` / `cancelled`, `partial` → `completed` / `cancelled`. `open`, `partial`, `completed` üretime göre hesaplanır (Aşama 4); `cancelled` elle yapılır. Üretim geri alındığında gerekebilecek geri geçişler Aşama 4'te ele alınır.
- **İptal:** yalnızca `open` ve `partial` siparişler iptal edilebilir; açıklama zorunludur, `cancellation_reason`'a ve denetim izine yazılır, sipariş sayfasında görünür. İptal geri alınamaz.
- **Kalemler** yalnızca sipariş `open` iken eklenip çıkarılabilir ve miktarı değişebilir. Aşama 4'te: bağlı iş emri veya üretilmiş motor varsa kalem silinemez, miktar üretilenin altına inemez.
- `completed` ve `cancelled` siparişler düzenlenemez. `partial` siparişte yalnızca termin, müşteri sipariş no ve notlar değişebilir (müşteri ve kalemler değişmez).
- Pasif motor modeli yeni kaleme eklenemez; mevcut kalemler korunur.

**Denetim izi:** users, parts, motor_models, bom_items, customers, orders, order_items, production_batches, work_orders, motor_units, exchange_rates, prices, invoices, invoice_lines, cash_accounts, payments, cheques değişiklikleri activitylog ile kaydedilir. Yönetici için görüntüleme ekranı olsun.
- Kayıtlar **kalıcıdır**; otomatik silinmez (`activitylog:clean` zamanlanmaz).
- Kullanıcı girişleri de yazılır: başarılı giriş, başarısız giriş (denenen kullanıcı adı) ve çıkış; zaman ve IP ile (log adı `auth`; şifre hiçbir zaman yazılmaz).
- Ekran: tarih, kullanıcı, kayıt türü ve kaydı, işlem; filtreler (kayıt türü, kullanıcı, işlem, tarih); detayda değişen alanlar eski → yeni, Türkçe alan adları, enum etiketleri ve bağlı kayıtların adlarıyla. Yönetici, kayıt sayfalarındaki "Geçmiş" butonuyla o kaydın denetim izini açar.

## 6. Roller ve yetkiler

| | Yönetici | Saha operatörü | Depo | Muhasebe |
|---|---|---|---|---|
| Kullanıcı yönetimi | ✓ | | | |
| Motor modeli, reçete | ✓ | görüntüleme | görüntüleme | görüntüleme |
| Cari hesaplar (müşteri, tedarikçi) | ✓ | görüntüleme | görüntüleme | ✓ |
| Sipariş | ✓ | görüntüleme | görüntüleme | görüntüleme |
| İş emri görüntüleme | ✓ | kendine atananlar | ✓ (salt okunur) | |
| İş emri oluşturma/atama | ✓ | | | |
| İş emri geri alma | ✓ | | | |
| İş emri durum değiştirme | ✓ | yalnızca kendine atananlar | | |
| Stok kartları | ✓ | görüntüleme | ✓ | görüntüleme |
| Stok giriş/çıkış/sayım | ✓ | | ✓ | |
| Reçete | ✓ (motor modeli yetkisiyle) | görüntüleme | görüntüleme | |
| Üretim partileri | ✓ | görüntüleme | görüntüleme | |
| Üretilen motorlar | ✓ | görüntüleme | görüntüleme | görüntüleme |
| Sevk | ✓ | | ✓ | |
| Fiyatlar, döviz kurları | ✓ | | | ✓ |
| Faturalar, tahsilat/ödeme, kasa/banka, Luca aktarımı (Aşama 5b–5d) | ✓ | | alış faturaları görüntüleme | ✓ |
| Raporlar ve Excel | ✓ | | stok raporları | |
| Denetim izi | ✓ | | | |

- Bir kullanıcının **birden fazla rolü** olabilir (yetkiler birleşir); **en az bir rol zorunludur.** Kullanıcı formunda roller çoklu seçimle atanır.
- Giriş **kullanıcı adı (username) + şifre** ile yapılır; e-posta isteğe bağlıdır. Şifre sıfırlamayı yönetici yapar (e-posta ile sıfırlama yok).
- Kullanıcı adı yalnızca ASCII'dir (küçük harf, rakam, `.`, `_`, `-`). Formda, girişte ve komutta Türkçe karakterler otomatik çevrilir (ş→s, ç→c, ğ→g, ü→u, ö→o, ı→i, İ→i) ve büyük harfler küçültülür; kullanıcı hata almadan düzeltilmiş hâlini görür.
- Pasifleştirilen kullanıcı bir sonraki isteğinde (Livewire istekleri dahil) otomatik çıkış yapar ve giriş ekranında "Hesabınız pasifleştirildi, yöneticinize başvurun." mesajını görür.
- **Yönetici güvenliği:** kullanıcı kendini pasifleştiremez; son aktif yöneticinin yönetici rolü kaldırılamaz ve pasifleştirilemez.
- "En az bir rol" ve yönetici güvenliği kuralları **servis seviyesinde** (UserService, kilitli) zorlanır; bunlar için veritabanı trigger'ı eklenmez (bölüm 3 kural 5'in bilinçli istisnası).
- Filament paneli kök adreste (`/`) çalışır.

Yetkiler Laravel Policy'leriyle uygulanır; Filament menüsü de yetkiye göre gizlenir. Saha operatörü arayüzü sade olmalı: ana ekranı "Bana atanan iş emirleri" + büyük "Hızlı işlem" butonu (Aşama 7).

## 7. Ekranlar

- **Dashboard** (rol bazlı, 60 saniyede bir yenilenir): bugün/bu hafta tamamlanan motor sayısı, üretimdeki iş emirleri, gecikmiş iş emirleri (planlanan bitiş geçmiş), kritik stoktaki parçalar, hareketsiz stok — yönetici ve depo; son 14 günün günlük motor üretimi grafiği — yönetici; vadesi geçmiş alacak toplamı ve kasa/banka bakiyeleri — yönetici ve muhasebe; "Bana atanan iş emirleri" ve Hızlı İşlem — operatör
- Standart Filament kaynakları: kullanıcılar, birimler, parçalar, motor modelleri (reçete ilişki yöneticisiyle), müşteriler, siparişler (kalemleriyle), partiler, iş emirleri, üretilen motorlar, stok hareketleri (salt okunur liste)
- **Stok işlemi sayfası**: giriş / çıkış / sayım düzeltme formu
- **Hızlı İşlem sayfası** (dokunmatik ve büyük butonlu):
  - Otomatik odaklı tek bir okuma alanı; USB okuyucunun gönderdiği Enter ile işlem tetiklenir. Okuma alanı bir form içindedir ve Enter formu gönderir; form, istek sürerken ikinci gönderimi engelleyen ortak Alpine bileşeni **`submitGuard`** ile korunur (`resources/js/filament/submit-guard.js`, kullanım: `x-data="submitGuard({ action: '...' })"`). İstek sürerken gelen okuma **düşürülür** ve sesli/görsel uyarı verilir ("İşleniyor, tekrar okutun"); sıraya alınmaz (her okuma bir form açar).
  - Kod çözümü (büyük/küçük harf ve baştaki/sondaki boşluk önemsiz): parça barkodu veya parça kodu → seri no → iş emri no; hiçbiri değilse "Kod bulunamadı". Tüm roller kullanır; her işlem kendi yetkisine bağlıdır.
  - Başarılı okumada kısa bip, hatada farklı ses, telefonda titreşim (ses dosyası yok; tarayıcıda üretilir).
  - "Kamera ile okut" butonu (telefon/tablet)
  - Okunan değer parça barkoduysa → parça kartı (bakiye) ve hızlı stok giriş/çıkış formu (stok hareketi yetkisi olanlara; sayım tam stok işlemi sayfasındadır)
  - Seri no ise → motor detay kartı; stoktaki motor için yönetici ve depoya "Sevk et"
  - İş emri numarası ise → yetkiye göre "Başlat" ve "Tamamla" (fire giriş penceresiyle, standart fireyle dolu); diğer işlemler için iş emri sayfasına bağlantı. Saha operatörü yalnızca kendine atanan iş emirlerini görür (listede, detayda ve burada); başkasına atanmış bir iş emri okutulursa iş emri bilgisi gösterilmez, "Bu iş emri size atanmamış." mesajı çıkar
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

**Kararlar (Aşama 8)**
- Hazır aralıklar: bugün, dün, bu hafta, geçen hafta, bu ay, geçen ay, özel; hafta pazartesi başlar.
- Her rapor tek bir sınıfta tanımlanır (`app/Reports`); ekran tablosu ve Excel aynı tanımdan üretilir.
- **Üretim adedi** üretilen motorlardan (iptal edilenler hariç, üretim tarihine göre); "yarı mamul dahil" seçilirse tamamlanan yarı mamul iş emirleri ayrı satırlarla eklenir.
- **Üretim süresi** takvim süresidir (başlama → bitiş, saat); vardiya/mesai takvimi yoktur. **Zamanında** = tamamlanma tarihi ≤ planlanan bitiş. Operatör raporunda iş emri atanan operatöre yazılır; atanmamışlar "Atanmamış" satırındadır.
- **Stok raporu** parça başına açılış bakiyesi, hareket tiplerine göre giriş/çıkış ve dönem sonu bakiyesi (muhasebe koduna göre gruplu); ayrıca hareket listesi. Depo da görür.
- **Sipariş durumu** kalem başına sipariş, üretilen, sevk edilen, kalan adet; termin ve gecikme.
- Excel: openspout (`openspout/openspout`, doğrudan bağımlılık); Türkçe başlıklar, sayılar sayı hücresi, ayrıca "Özet" sayfası (filtreler ve özet). 2.000 satıra kadar doğrudan iner; fazlası kuyrukta hazırlanır, bitince bildirim gelir; dosyayı yalnızca hazırlayan indirir; 7 günden eski dosyalar silinir (`reports:prune-exports`, günlük).
- Muhasebe raporları (cari bakiye listesi, kasa/banka özeti) 5d ile birlikte en sona bırakıldı.

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
- `deploy.sh`: backup.sh çalıştırır → `git pull --ff-only` → `docker compose -f docker-compose.prod.yml build` → `db-migrate.sh` (migration, yeni kod başlamadan önce) → `up -d` → `optimize` → `/up` sağlık kontrolü (başarısızsa açık hata mesajıyla çıkar)
- `restore.sh <dump-dosyası> [files-arşivi]` (root): onay ister → mevcut veritabanının güvenlik dökümü (`pre-restore-*.dump`) → app/worker/scheduler durdurur → şema boşaltılır (dökümde olmayan yeni tablolar migration'ı bozmasın) → `pg_restore --clean --if-exists --single-transaction` → (verildiyse) `storage/app` geri yüklenir → `db-migrate.sh` → servisleri başlatır → `optimize` → `/up`
- `db-migrate.sh`: migration'ları tablo sahibiyle tek seferlik konteynerde çalıştırır (ayar önbelleği kurulmaz) ve uygulama kullanıcısını `deploy/db/app-role.sql` ile ayarlar; ilk kurulumda da kullanılır. Ortak yardımcılar `lib.sh`'tadır.

**Veritabanı kullanıcıları ve ortam değişkenleri (Aşama 10 kararları)**
- Tablo sahibi `DB_OWNER_USERNAME` (varsayılan `uretim`; postgres konteynerinin `POSTGRES_USER`'ı) migration, yedek ve geri yüklemeyi yapar. Uygulama `DB_USERNAME` (varsayılan `uretim_app`) ile bağlanır: tablo/sekans/fonksiyon üzerinde yalnızca SELECT/INSERT/UPDATE/DELETE, USAGE/SELECT/UPDATE, EXECUTE; varsayılan yetkilerle yeni tablolar da kapsanır; TRUNCATE, TRIGGER, REFERENCES, CREATE yok. `app-role.sql` tekrar çalıştırılabilir; şifre ortam değişkeninden (`APP_DB_PASSWORD`) okunur, boşsa hata verir. Bu davranış `tests/Feature/Deployment` ile test edilir.
- Uygulama konteynerlerine `.env`'in tamamı verilmez (`env_file` yok); yalnızca compose'daki `x-app-environment` listesi geçer. `DB_OWNER_PASSWORD`, `CF_API_TOKEN`, `TUNNEL_TOKEN` uygulamaya ulaşmaz. Uygulamanın okuyacağı yeni bir değişken bu listeye de eklenir (test, `.env.production.example`'daki her anahtarın compose'da kullanıldığını denetler).
- Compose proje adı sabit `name: uretim`; imajlar `uretim-app`, `uretim-caddy` (geliştirme ortamının `tem-uretim-*` adlarıyla çakışmaz).
- İmaj: `deploy/Dockerfile` (base → vendor → assets → son), eklentilere ek olarak `pcntl` ve Aşama 6 için `soap`; kullanıcı `app` (UID 1000), FrankenPHP'ye yalnızca `CAP_NET_BIND_SERVICE`. Giriş betiği (`deploy/docker-entrypoint.sh`) web sunucusu, kuyruk işçisi ve zamanlayıcı başlarken `optimize` çalıştırır; tek seferlik komutlarda çalıştırmaz.
- Loglar `stderr` → Docker json-file (servis başına 5 × 10 MB). Şifreler yalnızca harf/rakam (`openssl rand -hex 24`).
- Yedekte bulut gönderimi başarısızsa yerel yedek korunur, healthchecks'e `/fail` gönderilir; USB disk yoksa yalnızca uyarı.

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
- Çok seviyeli reçete, döngü engeli, fire (standart ve girilen), yarı mamul üretimi ve tüketimi, mamul bakiyesi = stoktaki motor sayısı, mamulde elle hareket reddi (servis ve veritabanı), sevk, geri alma kuralları
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
- **Her aşama ve düzeltme özetinin sonuna** şu iki bilgi otomatik eklenir: tüm test paketinin sonucu (toplam / geçen / başarısız / atlanan) ve son commit'in hash'i. Sayılar, özetten hemen önce çalıştırılan tam paketten alınır (kısmi çalıştırmadan değil).
- Kod yorumları ve commit mesajları Türkçe olabilir; kod içi isimlendirme İngilizce.
- **Geliştirme veritabanında veri silen komut çalıştırmadan önce mutlaka onay iste:** `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`, `db:seed` ile üzerine yazma, doğrudan `DELETE`/`TRUNCATE`/`DROP` vb. Şema değişikliği için yalnızca `migrate` (ileri) onaysız çalıştırılabilir. Test veritabanı (`tem_uretim_test`) bu kuralın dışındadır.
- **Çalıştırılmış (herhangi bir veritabanında, geliştirme dahil) veya commit edilmiş bir migration dosyası asla değiştirilmez.** Her şema değişikliği, düzeltmeler dahil, yeni bir migration ile yapılır; böylece her ortam aynı şemaya ileri `migrate` ile ulaşır.

**Aşamalar** (sıra: 1–5c tamam → 7, 8, 9, 10, 11, 12 → en son 5d ve 6; gerçek cihaz denemeleri (USB okuyucu, telefon kamerası) teslim öncesinde kurulum kılavuzundaki kontrol listesiyle topluca yapılır; 5d ve 6 dış bilgi bekliyor: Luca örnek dosyası, Uyumsoft test hesabı)
1. Proje iskeleti: Laravel + Filament kurulumu, geliştirme için `docker-compose.yml`, PostgreSQL, Türkçe dil/saat dilimi, roller ve yetkiler, `app:create-admin`, kullanıcı yönetimi, Pest kurulumu
2. Stok: birimler, parçalar, `StockService`, stok hareketleri, stok işlemi sayfası, kritik stok bildirimi, `stock:reconcile`, testler
   - Ek (Aşama 4'ten önce): stok kartı türleri ve muhasebe kodları (6 tür, mevcut sarf kartları direkt sarfa), hareketsiz stok uyarısı
3. Müşteri ve sipariş, motor modelleri (tablo + yönetim ekranı; sipariş kalemleri motor modeline bağlı olduğu için Aşama 4'ten öne alındı), `NumberSequenceService`
4. Üretim: reçete (çok seviyeli, direkt sarf ve fire dahil), yarı mamul ve mamul üretimi, partiler, iş emirleri, durum makinesi, tamamlama akışı, seri no üretimi, üretilen motorlar, yetersiz malzeme uyarısı, testler
   - Alt adımlar (her biri testler ve commit ile biter): 4a hareket tipleri, mamul kartı, reçete · 4b iş emirleri, malzeme listesi, durum makinesi · 4c tamamlama, motorlar, seri no, sevk · 4d iş emri geri alma
5. Ön muhasebe: cari hesaplar (müşteri ve tedarikçi), fiyat/iskonto/KDV, döviz, alış ve satış faturası, tahsilat ve ödeme, Luca aktarımı
   - Alt adımlar: 5a cari hesaplar, döviz kurları, fiyatlar, Muhasebe rolü · 5b faturalar · 5c tahsilat/ödeme, kasa/banka, çek/senet, cari ekstre · 5d Luca aktarımı (örnek dosya bekleniyor)
6. e-Dönüşüm: Uyumsoft e-fatura, e-arşiv, e-irsaliye, gelen faturaların alışa bağlanması, gönderim kuyruğu ve durum takibi
7. Hızlı İşlem sayfası: USB barkod ve kamera ile okuma
8. Raporlar ve Excel dışa aktarım
9. Dashboard (kritik ve hareketsiz stok listesi dahil) ve denetim izi ekranı
10. Üretim dağıtım dosyaları, betikler, `.env.production.example`, README
   - **Ayrı uygulama veritabanı rolü:** uygulama, tablo sahibi olmayan bir rolle bağlanır (yalnızca gereken tablo yetkileri; `ALTER TABLE ... DISABLE TRIGGER` yapamaz). Migration'lar tablo sahibi kullanıcıyla çalışır. `deploy.sh`, `.env.production.example` ve kurulum kılavuzu buna göre düzenlenir.
11. Demo veri, genel gözden geçirme (güvenlik, N+1 sorgular, eksik index'ler, CDN kontrolü)
   - **Kararlar:** `DemoDataSeeder` kayıtları servislerle, işlemi yapan kullanıcının oturumu ve o anın tarihiyle oluşturur (120 gün önce açılış sayımı ve devir, sonra 7 haftalık tempo; şu andan sonraki adımlar yapılmaz); üretimde çalışmaz, ikinci kez yüklenmez; şifre `demo1234`.
   - **Tarama testi** (`tests/Feature/Seeders/PanelSweepTest.php`): demo veriyle her panel GET sayfası her rolle açılır; yanıt bölüm 6'daki tabloya uyar (izinli 200, değilse 403/404), sayfa başı sorgu ≤ 30, harici src/href yok. Yeni sayfa eklenince beklenti tablosuna da eklenir (eklenmezse test hata verir).
   - **Index kuralı:** tek kolonlu her foreign key bir index'in ilk kolonudur (`tests/Feature/Database/IndexTest.php`).
   - **Güvenlik başlıkları** (`App\Http\Middleware\SecurityHeaders`, global): CSP yalnızca `'self'` (Livewire/Alpine için `'unsafe-inline'`, `'unsafe-eval'`; geliştirmede Vite sunucusu), `X-Frame-Options: SAMEORIGIN`, `nosniff`, `Referrer-Policy: same-origin`, `Permissions-Policy` (kamera yalnızca kendi sayfalarımız). HSTS eklenmedi (yerel ağda IP ile http'ye düşme ihtimali; gerekirse Caddy'de).
12. (Opsiyonel) Etiket yazdırma
