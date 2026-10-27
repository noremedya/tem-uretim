@php($part = $this->part())
@if ($part)
    <x-filament::section>
        <x-slot name="heading">
            <span style="display: flex; align-items: center; gap: .75rem;"><x-part-thumb :part="$part" size="4rem" /> {{ $part->code }} — {{ $part->name }}</span>
        </x-slot>
        <x-slot name="description">
            {{ $part->typeLabel() }} · Birim: {{ $part->unit->name }}@unless ($part->is_active) · <span class="text-danger-600">Pasif</span>@endunless
        </x-slot>

        @if ($part->coProductOf)
            <p class="text-sm">
                Bu ürün <strong>{{ $part->coProductOf->parent->code }} — {{ $part->coProductOf->parent->name }}</strong> ürününün eş ürünüdür:
                ana ürün bir birim üretilirken {{ \App\Support\Quantity::format((string) $part->coProductOf->quantity_per_unit, $part->unit->name) }} çıkar.
                Kendi reçetesi yoktur; iş emri ana ürün için açılır.
                <a href="{{ \App\Filament\Pages\BomEditor::getUrl(['part' => $part->coProductOf->parent_part_id]) }}" class="text-primary-600 underline">Ana ürünün reçetesini aç</a>
            </p>
        @elseif ($part->type === \App\Enums\PartType::SemiFinished)
            <div class="space-y-2">
                <div class="flex items-center justify-between gap-4">
                    <h3 class="text-sm font-semibold">Eş ürünler <span class="font-normal text-gray-500">(aynı işlemde birlikte çıkan yarı mamuller)</span></h3>
                    {{ $this->addCoProductAction }}
                </div>
                @forelse ($part->coProducts as $coProduct)
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-white/10">
                        <span style="display: flex; align-items: center; gap: .5rem;">
                            <x-part-thumb :part="$coProduct->coPart" />
                            <strong>{{ $coProduct->coPart->code }}</strong> — {{ $coProduct->coPart->name }}:
                            birim başına {{ \App\Support\Quantity::format((string) $coProduct->quantity_per_unit, $coProduct->coPart->unit->name) }}
                        </span>
                        <span class="flex gap-3">
                            {{ ($this->editCoProductAction)(['coProduct' => $coProduct->getKey()]) }}
                            {{ ($this->removeCoProductAction)(['coProduct' => $coProduct->getKey()]) }}
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">Eş ürün yok.</p>
                @endforelse
            </div>
        @elseif ($part->motorModel)
            <p class="text-sm text-gray-500">Mamul kartı: {{ $part->motorModel->code }} motor modelinin reçetesi.</p>
        @endif
    </x-filament::section>
@endif
