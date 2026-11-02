<?php

namespace App\Console\Commands;

use App\Exceptions\BusinessRuleException;
use App\Services\PartWarehouseService;
use Illuminate\Console\Command;

/** Mevcut reçetelerdeki ürünlere depo açar, stoğu taşır (tekrar çalıştırılabilir). Yeni ürünlerde bu otomatik olur. */
class SyncPartWarehouses extends Command
{
    protected $signature = 'warehouses:sync-parts';

    protected $description = 'Reçetedeki her ürün için ayrı depo açar ve stoğunu oraya taşır';

    public function handle(PartWarehouseService $service): int
    {
        try {
            $result = $service->syncAll();
        } catch (BusinessRuleException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("{$result['warehouses']} ürün deposu açıldı.");

        if ($result['retired'] !== []) {
            $this->info('Pasifleştirilen depolar: '.implode(', ', $result['retired']));
        }

        return self::SUCCESS;
    }
}
