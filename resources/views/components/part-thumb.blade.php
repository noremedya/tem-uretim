{{-- Ürün görselinin küçük hâli (görsel yoksa hiçbir şey); tıklanınca tam boy yeni sekmede açılır. --}}
@props(['part', 'size' => '2rem'])
@if ($url = $part?->imageUrl())
    <a href="{{ $url }}" target="_blank" style="display: inline-flex; flex: none; vertical-align: middle;">
        <img src="{{ $url }}" alt="{{ $part->name }}" loading="lazy" style="width: {{ $size }}; height: {{ $size }}; object-fit: cover; border-radius: .25rem; border: 1px solid rgba(127,127,127,.25);">
    </a>
@endif
