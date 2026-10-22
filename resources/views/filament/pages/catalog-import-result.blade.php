@php($result = $this->importResult())
@php($titles = collect(\App\Imports\CatalogSheets::SHEETS)->map(fn ($s) => $s['title']))
@php($colors = ['created' => 'success', 'updated' => 'info', 'skipped' => 'gray', 'unchanged' => 'gray', 'error' => 'danger'])
@if ($result)
    <x-filament::section>
        <x-slot name="heading">{{ $result->committed ? 'Aktarma sonucu' : 'Kontrol sonucu' }}</x-slot>
        <x-slot name="description">
            @if ($result->committed)
                Kayıtlar kaydedildi.
            @elseif ($result->hasErrors())
                {{ $result->errorCount() }} satırda hata var; hiçbir şey kaydedilmedi. Hataları düzeltip dosyayı yeniden yükleyin.
            @else
                Hata yok. Aşağıdakiler "Aktar" ile kaydedilecek.
            @endif
        </x-slot>

        <div class="mb-4 flex flex-wrap gap-2">
            @foreach ($result->sheets as $sheet => $rows)
                <span class="rounded-lg border border-gray-200 px-3 py-1 text-sm dark:border-white/10">
                    <strong>{{ $titles[$sheet] ?? $sheet }}</strong>:
                    @foreach ($result->counts($sheet) as $status => $count)
                        {{ $count }} {{ mb_strtolower($result->label($status)) }}@if (! $loop->last), @endif
                    @endforeach
                </span>
            @endforeach
        </div>

        @if ($result->created !== [])
            <p class="mb-4 text-sm">Otomatik açılacak tanımlar: {{ implode(', ', array_unique($result->created)) }}</p>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th class="py-2 pe-4" style="text-align: start">Sayfa</th>
                        <th class="py-2 pe-4" style="text-align: start">Satır</th>
                        <th class="py-2 pe-4" style="text-align: start">Kayıt</th>
                        <th class="py-2 pe-4" style="text-align: start">Durum</th>
                        <th class="py-2" style="text-align: start">Açıklama</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (collect($result->sheets)->flatMap(fn ($rows, $sheet) => collect($rows)->map(fn ($r) => [...$r, 'sheet' => $sheet]))->sortBy(fn ($r) => $r['status'] === 'error' ? 0 : 1)->take(500) as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-1.5 pe-4">{{ $titles[$row['sheet']] ?? $row['sheet'] }}</td>
                            <td class="py-1.5 pe-4">{{ $row['row'] }}</td>
                            <td class="py-1.5 pe-4">{{ $row['key'] }}</td>
                            <td class="py-1.5 pe-4"><x-filament::badge :color="$colors[$row['status']] ?? 'gray'">{{ $result->label($row['status']) }}</x-filament::badge></td>
                            <td @class(['py-1.5', 'text-danger-600' => $row['status'] === 'error'])>{{ $row['message'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
@endif
