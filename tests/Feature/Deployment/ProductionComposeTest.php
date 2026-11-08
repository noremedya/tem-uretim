<?php

/*
 * docker-compose.prod.yml ile .env.production.example tutarlılığı (CLAUDE.md bölüm 9 ve 11).
 * Uygulama konteynerlerine .env'in tamamı verilmez; yalnızca x-app-environment listesi geçer.
 */

function composeFile(): string
{
    return file_get_contents(base_path('docker-compose.prod.yml'));
}

/** x-app-environment bloğu: anchor satırından bir sonraki üst seviye anahtara kadar. */
function appEnvironmentBlock(): string
{
    preg_match('/^x-app-environment: &app-environment\n(.*?)^\S/ms', composeFile(), $matches);

    return $matches[1] ?? '';
}

/** @return list<string> */
function productionEnvKeys(): array
{
    preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', file_get_contents(base_path('.env.production.example')), $matches);

    return $matches[1];
}

it('örnek .env dosyasındaki her değişken compose dosyasında kullanılır', function () {
    expect(productionEnvKeys())->not->toBeEmpty();

    foreach (productionEnvKeys() as $key) {
        expect(composeFile())->toContain('${'.$key);
    }
});

it('tablo sahibi şifresi ve dış servis anahtarları uygulama konteynerlerine geçmez', function () {
    $block = appEnvironmentBlock();

    expect($block)->toContain('DB_USERNAME:')
        ->and($block)->toContain('DB_PASSWORD:');

    foreach (['DB_OWNER_USERNAME', 'DB_OWNER_PASSWORD', 'CF_API_TOKEN', 'TUNNEL_TOKEN'] as $secret) {
        expect($block)->not->toContain($secret);
    }

    expect(composeFile())->not->toMatch('/^\s*env_file:/m');
});

it('üretimde uygulama /up ile 80 portundan yoklanır', function () {
    expect(composeFile())
        ->toContain('curl -fsS -o /dev/null http://localhost:80/up || exit 1')
        ->not->toContain('2019/metrics"');
});

it('dağıtım betikleri çalıştırılabilir ve sıkı modda', function (string $script) {
    $path = base_path("deploy/scripts/{$script}");

    expect(is_executable($path))->toBeTrue()
        ->and(file_get_contents($path))->toContain('set -euo pipefail');
})->with(['backup.sh', 'deploy.sh', 'restore.sh', 'db-migrate.sh']);

it('Cloudflare Tunnel isteğe bağlıdır: yalnızca "tunnel" profiliyle başlar (intranet kurulumunda kapalı)', function () {
    preg_match('/^  cloudflared:\n(.*?)(?=^\S|^  \S)/ms', file_get_contents(base_path('docker-compose.prod.yml')), $block);

    expect($block[1] ?? '')->toContain('profiles: ["tunnel"]');
});
