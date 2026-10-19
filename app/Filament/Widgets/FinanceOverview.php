<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Filament\Pages\OverdueReceivables;
use App\Filament\Resources\CashAccounts\CashAccountResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\CashAccount;
use App\Models\Invoice;
use App\Services\LedgerService;
use App\Services\StatementService;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/** Ön muhasebe özeti (yönetici, muhasebe): vadesi geçmiş alacak, beklenen gümrük beyannameleri, kasa/banka bakiyeleri. */
class FinanceOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 5;

    protected ?string $pollingInterval = '60s';

    protected ?string $heading = 'Ön muhasebe';

    public static function canView(): bool
    {
        return Auth::user()?->can(Permission::FinanceManage) ?? false;
    }

    protected function getStats(): array
    {
        $overdue = collect(app(StatementService::class)->overdueReceivables())
            ->groupBy(fn (array $row) => $row['currency']->value)
            ->map(fn ($rows, string $currency) => Money::format($rows->reduce(fn (string $c, array $r) => Money::add($c, $r['overdue']), '0.00'), $currency));

        $stats = [
            Stat::make('Vadesi geçmiş alacak', $overdue->isEmpty() ? 'Yok' : $overdue->implode(' · '))
                ->color($overdue->isEmpty() ? 'success' : 'danger')
                ->url(OverdueReceivables::getUrl()),
        ];

        // İhracat/ihraç kayıtlı faturalarda beklenen gümrük beyannameleri (yalnızca varsa gösterilir).
        $awaiting = Invoice::query()->awaitingCustomsDeclaration()->count();

        if ($awaiting > 0) {
            $late = Invoice::query()->overdueRegisteredExport()->count();
            $stats[] = Stat::make('Beklenen gümrük beyannamesi', (string) $awaiting)
                ->description($late > 0 ? "{$late} ihraç kayıtlı satışın süresi geçti" : 'İhracat ve ihraç kayıtlı faturalar')
                ->color($late > 0 ? 'danger' : 'warning')
                ->url(InvoiceResource::getUrl('index'));
        }

        $ledger = app(LedgerService::class);

        foreach (CashAccount::query()->where('is_active', true)->orderBy('kind')->orderBy('id')->get() as $account) {
            $stats[] = Stat::make($account->label(), Money::format($ledger->cashBalance($account), $account->currency->value))
                ->url(CashAccountResource::getUrl('view', ['record' => $account]));
        }

        return $stats;
    }
}
