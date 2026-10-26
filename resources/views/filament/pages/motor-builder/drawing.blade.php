{{-- Montaj çizimi: şablondaki her parça numarası işaretlenir (yeşil: ürün seçildi, kırmızı: seçilmedi); tıklayınca satıra gider. --}}
@if ($drawing)
    <div style="position: sticky; top: 5rem;">
        <div style="position: relative; width: 100%; border-radius: 0.5rem; overflow: hidden; background: #fff; border: 1px solid rgba(0,0,0,.1);">
            <img src="{{ asset($drawing['image']) }}" alt="{{ $drawing['label'] }}" style="display: block; width: 100%; height: auto;">
            @foreach ($points as $point)
                <button type="button"
                    title="{{ $point['label'] }} · {{ implode(', ', $point['lines']) }}{{ $point['selected'] ? '' : ' (seçilmedi)' }}"
                    x-on:click="document.getElementById(@js($point['target']))?.scrollIntoView({ behavior: 'smooth', block: 'start' })"
                    style="position: absolute; left: {{ $point['left'] }}%; top: {{ $point['top'] }}%; transform: translate(-50%, -50%);
                        width: 8.5%; aspect-ratio: 1; border-radius: 9999px; cursor: pointer;
                        border: 3px solid {{ $point['selected'] ? '#16a34a' : '#dc2626' }};
                        background: {{ $point['selected'] ? 'rgba(22,163,74,.35)' : 'rgba(220,38,38,.08)' }};
                        {{ $point['selected'] ? '' : 'border-style: dashed;' }}">
                    <span style="position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0);">{{ $point['label'] }}</span>
                </button>
            @endforeach
        </div>
        <p style="margin-top: .5rem; font-size: .75rem; line-height: 1rem;" class="text-gray-500 dark:text-gray-400">
            <span style="color: #16a34a; font-weight: 600;">● seçildi</span> ·
            <span style="color: #dc2626; font-weight: 600;">○ seçilmedi</span> ·
            numaraya tıklayınca ilgili satıra gidilir. {{ $drawing['label'] }} (kaynak: {{ $drawing['source'] }}).
        </p>
    </div>
@else
    <p class="text-sm text-gray-500 dark:text-gray-400">Bu motor tipinin montaj çizimi yok (Reçete şablonları ekranından seçilebilir).</p>
@endif
