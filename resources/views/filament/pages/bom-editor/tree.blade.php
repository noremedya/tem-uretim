@php($nodes = $this->treeNodes())
@php($quantity = \App\Support\Quantity::format($this->quantity()))
@if ($nodes !== [])
    <x-filament::section collapsible>
        <x-slot name="heading">Reçete ağacı — {{ $quantity }} {{ $this->part()->unit->name }} için</x-slot>
        <x-slot name="description">Yarı mamuller kendi reçeteleriyle açılır; her seviyede fire eklenir.</x-slot>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left dark:border-white/10">
                        <th class="py-2 pe-4" style="text-align: start">Malzeme</th>
                        <th class="py-2 pe-4 text-end">Net</th>
                        <th class="py-2 pe-4 text-end">Fire</th>
                        <th class="py-2 text-end">Toplam</th>
                    </tr>
                </thead>
                <tbody>
                    @include('filament.pages.bom-editor.nodes', ['nodes' => $nodes])
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section collapsible>
        <x-slot name="heading">Toplam ihtiyaç — {{ $quantity }} {{ $this->part()->unit->name }} için</x-slot>
        <x-slot name="description">En alt seviyedeki malzemeler (hammadde, komponent, sarf ve reçetesi olmayan yarı mamuller); stok tüm depoların toplamıdır.</x-slot>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left dark:border-white/10">
                        <th class="py-2 pe-4" style="text-align: start">Malzeme</th>
                        <th class="py-2 pe-4" style="text-align: start">Tür</th>
                        <th class="py-2 pe-4 text-end">İhtiyaç (fire dahil)</th>
                        <th class="py-2 pe-4 text-end">Stok</th>
                        <th class="py-2 text-end">Eksik</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->requirementRows() as $row)
                        @php($missing = \App\Support\Quantity::sub($row['total'], $row['stock']))
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-1.5 pe-4"><x-part-thumb :part="$row['part']" size="1.75rem" /> <strong>{{ $row['part']->code }}</strong> — {{ $row['part']->name }}</td>
                            <td class="py-1.5 pe-4">{{ $row['part']->type->label() }}</td>
                            <td class="py-1.5 pe-4 text-end">{{ \App\Support\Quantity::format($row['total'], $row['part']->unit->name) }}</td>
                            <td class="py-1.5 pe-4 text-end">{{ \App\Support\Quantity::format($row['stock'], $row['part']->unit->name) }}</td>
                            <td @class(['py-1.5 text-end', 'font-semibold text-danger-600' => \App\Support\Quantity::compare($missing, '0') > 0])>
                                {{ \App\Support\Quantity::compare($missing, '0') > 0 ? \App\Support\Quantity::format($missing, $row['part']->unit->name) : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
@endif
