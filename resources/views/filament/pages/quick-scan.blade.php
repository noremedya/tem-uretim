<x-filament-panels::page>
    <div
        x-data="quickScan()"
        x-on:quick-scan-done.window="done($event.detail)"
        x-on:submit-guard-blocked="blocked()"
        class="mx-auto w-full max-w-3xl space-y-6"
    >
        {{-- Okuma alanı: USB okuyucu kodu yazıp Enter gönderir. --}}
        <form
            wire:submit="scan"
            x-data="submitGuard({ action: 'scan' })"
            class="flex flex-col gap-3 sm:flex-row"
        >
            <input
                type="text"
                wire:model="code"
                x-ref="input"
                x-on:keydown.enter="onEnter($event)"
                autocomplete="off"
                autocapitalize="characters"
                spellcheck="false"
                placeholder="Barkod, seri no veya iş emri no okutun"
                aria-label="Okuma alanı"
                class="w-full rounded-xl border-2 border-primary-500 bg-white px-5 py-5 text-2xl font-semibold tracking-wide shadow-sm focus:outline-none focus:ring-4 focus:ring-primary-300 dark:bg-gray-900"
            />

            <button
                type="button"
                x-on:click="openCamera()"
                class="rounded-xl bg-gray-800 px-6 py-5 text-lg font-semibold text-white hover:bg-gray-700 dark:bg-gray-700"
            >
                Kamera ile okut
            </button>
        </form>

        <p x-show="notice" x-text="notice" class="rounded-lg bg-warning-100 px-4 py-3 text-lg font-semibold text-warning-800" x-cloak></p>

        {{-- Kamera penceresi --}}
        <div x-show="cameraOpen" x-cloak class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-4 bg-black/90 p-4">
            <video x-ref="video" class="max-h-[70vh] w-full max-w-xl rounded-lg" playsinline muted></video>
            <p x-show="cameraError" x-text="cameraError" class="text-lg font-semibold text-danger-400"></p>
            <button type="button" x-on:click="closeCamera()" class="rounded-xl bg-white px-8 py-4 text-lg font-semibold text-gray-900">
                Kapat
            </button>
        </div>

        @if ($message)
            <div class="rounded-xl border-2 border-danger-300 bg-danger-50 p-6 text-xl font-semibold text-danger-700 dark:bg-danger-950 dark:text-danger-300">
                {{ $message }}
                @if ($lastCode)
                    <span class="block text-base font-normal">Okunan: {{ $lastCode }}</span>
                @endif
            </div>
        @endif

        @if ($part = $this->part())
            <x-filament::section>
                <x-slot name="heading">Ürün: {{ $part->code }}</x-slot>
                @if ($part->imageUrl())
                    <img src="{{ $part->imageUrl() }}" alt="{{ $part->name }}" class="mb-4 h-40 rounded-lg object-contain">
                @endif
                <div class="space-y-2 text-lg">
                    <p class="text-2xl font-semibold">{{ $part->name }}</p>
                    <p>Tür: {{ $part->type->getLabel() }}@if ($part->subtype) › {{ $part->subtype->name }}@endif</p>
                    <p class="text-2xl">
                        Bakiye:
                        <span @class(['font-bold', 'text-danger-600' => $part->isCritical()])>
                            {{ \App\Support\Quantity::format($part->totalStock(), $part->unit->name) }}
                        </span>
                    </p>
                    @if ($part->stockBalances->count() > 1)
                        <ul class="text-base">
                            @foreach ($part->stockBalances->sortBy('warehouse.code') as $balance)
                                <li @class(['text-danger-600' => $balance->isCriticalAt()])>
                                    {{ $balance->warehouse->name }}: {{ \App\Support\Quantity::format((string) $balance->quantity, $part->unit->name) }}
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @unless ($part->is_active)
                        <p class="font-semibold text-warning-600">Pasif ürün: yalnızca sayım yapılabilir (Stok İşlemi sayfası).</p>
                    @endunless
                </div>
                <div class="mt-6 flex flex-wrap gap-4">
                    {{ $this->stockInAction }}
                    {{ $this->stockOutAction }}
                </div>
            </x-filament::section>
        @elseif ($motor = $this->motor())
            <x-filament::section>
                <x-slot name="heading">Motor: {{ $motor->serial_number }}</x-slot>
                <div class="space-y-2 text-lg">
                    <p class="text-2xl font-semibold">{{ $motor->motorModel->code }} — {{ $motor->motorModel->name }}</p>
                    <p>Durum: <x-filament::badge :color="$motor->status->getColor()" class="inline-flex">{{ $motor->status->getLabel() }}</x-filament::badge>@if ($motor->status === \App\Enums\MotorUnitStatus::InStock) · Depo: {{ $motor->warehouse->name }}@endif</p>
                    <p>Üretim tarihi: {{ $motor->production_date->format('d.m.Y') }} · İş emri: {{ $motor->workOrder?->work_order_number ?? 'Açılış kaydı' }}</p>
                    @if ($motor->order)
                        <p>Sipariş: {{ $motor->order->order_number }}@if (auth()->user()->canSeeCustomers()) — {{ $motor->order->customer->name }}@endif</p>
                    @endif
                    @if ($motor->shipped_at)
                        <p>Sevk: {{ $motor->shipped_at->format('d.m.Y H:i') }} ({{ $motor->shippedBy?->name }})</p>
                    @endif
                </div>
                <div class="mt-6 flex flex-wrap gap-4">
                    {{ $this->shipAction }}
                </div>
            </x-filament::section>
        @elseif ($workOrder = $this->workOrder())
            <x-filament::section>
                <x-slot name="heading">İş emri: {{ $workOrder->work_order_number }}</x-slot>
                <div class="space-y-2 text-lg">
                    <p class="text-2xl font-semibold">{{ $workOrder->product->code }} — {{ $workOrder->product->name }}</p>
                    <p>Adet: {{ \App\Support\Quantity::format((string) $workOrder->quantity, $workOrder->product->unit->name) }}</p>
                    <p>Durum: <x-filament::badge :color="$workOrder->status->getColor()" class="inline-flex">{{ $workOrder->status->getLabel() }}</x-filament::badge></p>
                    <p>Operatör: {{ $workOrder->assignedUser?->name ?? 'Atanmadı' }} · Planlanan bitiş: {{ $workOrder->planned_end->format('d.m.Y') }}</p>
                </div>
                <div class="mt-6 flex flex-wrap items-center gap-4">
                    {{ $this->startAction }}
                    {{ $this->completeAction }}
                    <x-filament::link :href="\App\Filament\Resources\WorkOrders\WorkOrderResource::getUrl('view', ['record' => $workOrder])" size="lg">
                        İş emri sayfası
                    </x-filament::link>
                </div>
            </x-filament::section>
        @endif
    </div>

</x-filament-panels::page>
