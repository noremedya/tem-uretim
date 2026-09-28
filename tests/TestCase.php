<?php

namespace Tests;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** Testlerin çalışabileceği tek veritabanı. Başka bir veritabanında testler veri silmeden durur. */
    public const TEST_DATABASE = 'tem_uretim_test';

    /** RefreshDatabase migrate ettikten sonra roller ve yetkiler hazır olsun. */
    protected bool $seed = true;

    protected string $seeder = RolesAndPermissionsSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        // Testler derlenmiş Vite varlıklarına (public/build) bağımlı olmasın.
        $this->withoutVite();
    }

    /**
     * RefreshDatabase (migrate:fresh) burada çalışır; öncesinde bağlı veritabanı kontrol edilir.
     */
    protected function setUpTraits()
    {
        static::ensureTestDatabase();

        return parent::setUpTraits();
    }

    /**
     * Bağlı veritabanı test veritabanı değilse hiçbir sorgu çalıştırmadan durur. Bağlantı adı yapılandırmadan
     * (DB_URL dahil) okunur; veritabanına bağlanılmaz.
     *
     * @throws RuntimeException
     */
    public static function ensureTestDatabase(): void
    {
        $database = DB::connection()->getDatabaseName();

        if ($database !== self::TEST_DATABASE) {
            throw new RuntimeException(sprintf(
                'Testler yalnızca "%s" veritabanında çalışır; bağlı veritabanı "%s". Veri silinmemesi için testler durduruldu.',
                self::TEST_DATABASE,
                $database,
            ));
        }
    }
}
