<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/*
 * Üretimde uygulama tablo sahibi olmayan bir kullanıcıyla bağlanır (deploy/db/app-role.sql, CLAUDE.md bölüm 11).
 * Gerçek SQL dosyası psql ile test veritabanında, geçici bir kullanıcı için çalıştırılır; sonra o kullanıcıyla
 * bağlanılıp neye izin verildiği ve neyin reddedildiği denetlenir. Kullanıcı test sonunda silinir.
 */

const TEST_APP_ROLE = 'tem_uretim_test_app';

function testDbConfig(): array
{
    return config('database.connections.'.config('database.default'));
}

/** Test veritabanında tablo sahibi (testlerin bağlandığı kullanıcı) olarak psql çalıştırır. */
function ownerPsql(array $arguments, array $env = [], ?string $input = null): ProcessResult
{
    $config = testDbConfig();

    return Process::env(['PGPASSWORD' => $config['password'], ...$env])
        ->input($input)
        ->run([
            'psql', '-X', '-q', '-v', 'ON_ERROR_STOP=1',
            '-h', $config['host'], '-p', (string) $config['port'], '-U', $config['username'], '-d', $config['database'],
            ...$arguments,
        ]);
}

function dropTestAppRole(): void
{
    DB::purge('app_role');
    ownerPsql(['-c', sprintf(
        "DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '%1\$s') THEN DROP OWNED BY %1\$s; DROP ROLE %1\$s; END IF; END \$\$;",
        TEST_APP_ROLE,
    )])->throw();
    ownerPsql(['-c', 'DROP TABLE IF EXISTS deployment_default_privilege_probe'])->throw();
}

beforeEach(function () {
    dropTestAppRole();

    $this->appPassword = Str::random(32);

    ownerPsql(
        ['-v', 'app_user='.TEST_APP_ROLE, '-v', 'owner_user='.testDbConfig()['username'], '-v', 'db_name='.testDbConfig()['database'], '-f', '-'],
        ['APP_DB_PASSWORD' => $this->appPassword],
        file_get_contents(base_path('deploy/db/app-role.sql')),
    )->throw();

    config(['database.connections.app_role' => [
        ...testDbConfig(),
        'username' => TEST_APP_ROLE,
        'password' => $this->appPassword,
    ]]);
});

afterEach(function () {
    dropTestAppRole();
});

function appRole(): Connection
{
    return DB::connection('app_role');
}

/** Uygulama kullanıcısıyla çalıştırılan ifadenin yetki hatasıyla reddedildiğini doğrular. */
function expectDenied(string $sql): void
{
    try {
        appRole()->unprepared($sql);
    } catch (QueryException $e) {
        expect($e->getMessage())->toMatch('/must be owner|permission denied/');

        return;
    }

    test()->fail("Uygulama kullanıcısı şu ifadeyi çalıştırabildi: {$sql}");
}

it('uygulama kullanıcısı veri okuyup yazabilir, sayaç kullanabilir', function () {
    $connection = appRole();

    expect($connection->selectOne('select current_user as u')->u)->toBe(TEST_APP_ROLE)
        ->and($connection->selectOne('select rolsuper from pg_roles where rolname = current_user')->rolsuper)->toBeFalse();

    $connection->beginTransaction();

    try {
        $id = $connection->table('units')->insertGetId([
            'name' => 'yetki-deneme-'.Str::random(6), 'allows_decimal' => false, 'lock_version' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $connection->table('units')->where('id', $id)->update(['allows_decimal' => true]);
        $connection->table('units')->where('id', $id)->lockForUpdate()->first();
        $connection->table('units')->where('id', $id)->delete();

        // NumberSequenceService'in atomik sayaç sorgusu.
        $connection->selectOne(
            "insert into number_sequences (name, scope, last_value, created_at, updated_at) values ('yetki', 'deneme', 1, now(), now())
             on conflict (name, scope) do update set last_value = number_sequences.last_value + 1 returning last_value"
        );
    } finally {
        $connection->rollBack();
    }
});

it('uygulama kullanıcısı trigger kapatamaz, tablo yapısını değiştiremez, TRUNCATE ve DROP yapamaz', function () {
    expectDenied('ALTER TABLE stock_movements DISABLE TRIGGER ALL');
    expectDenied('ALTER TABLE stock_movements DISABLE TRIGGER USER');
    expectDenied('ALTER TABLE stock_movements ADD COLUMN x int');
    expectDenied('TRUNCATE stock_movements');
    expectDenied('TRUNCATE notifications');
    expectDenied('DROP TABLE report_exports');
    expectDenied('DROP FUNCTION stock_movements_immutable() CASCADE');
    expectDenied('CREATE TABLE yetki_deneme (id int)');
    expectDenied("SET session_replication_role = 'replica'");
});

it('tablo sahibinin sonradan oluşturduğu tablolara varsayılan yetkiler uygulanır', function () {
    // Yeni bir migration'ın oluşturacağı tablo gibi: tablo sahibi oluşturur, uygulama kullanıcısı kullanır.
    ownerPsql(['-c', 'CREATE TABLE deployment_default_privilege_probe (id bigserial primary key, note text)'])->throw();

    appRole()->table('deployment_default_privilege_probe')->insert(['note' => 'ok']);

    expect(appRole()->table('deployment_default_privilege_probe')->count())->toBe(1);
    expectDenied('TRUNCATE deployment_default_privilege_probe');
});

it('şifre boş veya tanımsızsa yetki betiği hata verir ve kullanıcı oluşturmaz', function (array $env) {
    dropTestAppRole();

    $result = ownerPsql(
        ['-v', 'app_user='.TEST_APP_ROLE, '-v', 'owner_user='.testDbConfig()['username'], '-v', 'db_name='.testDbConfig()['database'], '-f', '-'],
        $env,
        file_get_contents(base_path('deploy/db/app-role.sql')),
    );

    expect($result->successful())->toBeFalse()
        ->and($result->errorOutput())->toContain('APP_DB_PASSWORD')
        ->and(DB::selectOne('select count(*) as c from pg_roles where rolname = ?', [TEST_APP_ROLE])->c)->toBe(0);
})->with([
    'boş' => [['APP_DB_PASSWORD' => '']],
    'tanımsız' => [[]],
]);
