<?php

namespace App\Policies;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Enums\Permission;
use App\Models\Invoice;
use App\Models\User;

/**
 * Faturalar: yönetici ve muhasebe yönetir; depo alış faturalarını salt okunur görür (CLAUDE.md bölüm 6).
 */
class InvoicePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::InvoicesManage) || $actor->can(Permission::InvoicesViewPurchase);
    }

    public function view(User $actor, Invoice $invoice): bool
    {
        return $actor->can(Permission::InvoicesManage)
            || ($actor->can(Permission::InvoicesViewPurchase) && $invoice->direction === InvoiceDirection::Purchase);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::InvoicesManage);
    }

    /** Taslak düzenlenir (başlık ve satırlar). */
    public function update(User $actor, Invoice $invoice): bool
    {
        return $actor->can(Permission::InvoicesManage) && $invoice->status === InvoiceStatus::Draft;
    }

    public function issue(User $actor, Invoice $invoice): bool
    {
        return $this->update($actor, $invoice);
    }

    public function cancel(User $actor, Invoice $invoice): bool
    {
        return $actor->can(Permission::InvoicesManage) && $invoice->status === InvoiceStatus::Issued;
    }

    /** Kesilmiş ihracat / ihraç kayıtlı faturaya gümrük beyannamesi işlenir. */
    public function recordCustomsDeclaration(User $actor, Invoice $invoice): bool
    {
        return $actor->can(Permission::InvoicesManage) && $invoice->status === InvoiceStatus::Issued && $invoice->export_type !== null;
    }

    /** Yalnızca taslak silinir. */
    public function delete(User $actor, Invoice $invoice): bool
    {
        return $this->update($actor, $invoice);
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
