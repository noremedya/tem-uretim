<?php

namespace App\Services;

use App\Enums\Role;
use App\Filament\Resources\Parts\PartResource;
use App\Models\Part;
use App\Models\User;
use App\Support\Quantity;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

/**
 * Stokla ilgili Filament veritabanı bildirimleri.
 */
class StockAlertService
{
    /** Parça kritik seviyeye indi: aktif yönetici ve depo kullanıcılarına. */
    public function criticalStock(Part $part, string $quantity): void
    {
        $part->loadMissing('unit');

        Notification::make()
            ->warning()
            ->title("Kritik stok: {$part->code} {$part->name}")
            ->body(sprintf(
                'Bakiye %s, kritik seviye %s.',
                Quantity::format($quantity, $part->unit->name),
                Quantity::format((string) $part->critical_level, $part->unit->name),
            ))
            ->actions([
                Action::make('view')
                    ->label('Parçayı aç')
                    ->url(PartResource::getUrl('view', ['record' => $part])),
            ])
            ->sendToDatabase($this->recipients(Role::Admin, Role::Warehouse));
    }

    /**
     * stock:reconcile tutarsızlık buldu: aktif yöneticilere.
     *
     * @param  list<array{code: string, name: string, balance: ?string, movements_total: string}>  $mismatches
     */
    public function reconcileMismatches(array $mismatches): void
    {
        $lines = array_map(
            fn (array $m): string => sprintf(
                '%s %s: bakiye %s, hareket toplamı %s',
                $m['code'],
                $m['name'],
                $m['balance'] === null ? 'yok' : Quantity::format($m['balance']),
                Quantity::format($m['movements_total']),
            ),
            array_slice($mismatches, 0, 10),
        );

        if (count($mismatches) > 10) {
            $lines[] = sprintf('… ve %d parça daha (ayrıntılar uygulama logunda).', count($mismatches) - 10);
        }

        Notification::make()
            ->danger()
            ->title(sprintf('Stok tutarlılık kontrolü: %d parçada fark bulundu', count($mismatches)))
            ->body(implode("\n", $lines))
            ->sendToDatabase($this->recipients(Role::Admin));
    }

    /** @return Collection<int, User> */
    private function recipients(Role ...$roles): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->role(array_map(fn (Role $role) => $role->value, $roles))
            ->get();
    }
}
