<?php

/*
 * Demo (tanıtım) sunucusu: APP_ENV=demo. Ekranda "DEMO" uyarısı çıkar; DemoDataSeeder çalışabilir ve kullanıcı şifresi
 * DEMO_PASSWORD'dan alınır (internete açık sunucuda bilinen şifre kalmasın). Geliştirmede sabit şifre kullanılır.
 */
return [
    'environment' => 'demo',
    'password' => env('DEMO_PASSWORD'),
];
