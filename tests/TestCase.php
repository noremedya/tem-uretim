<?php

namespace Tests;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** RefreshDatabase migrate ettikten sonra roller ve yetkiler hazır olsun. */
    protected bool $seed = true;

    protected string $seeder = RolesAndPermissionsSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        // Testler derlenmiş Vite varlıklarına (public/build) bağımlı olmasın.
        $this->withoutVite();
    }
}
