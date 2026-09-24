<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature testleri PostgreSQL test veritabanında, her test bir transaction içinde çalışır.
| Roller ve yetkiler migrate sonrasında bir kez seed edilir (TestCase::$seeder).
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
