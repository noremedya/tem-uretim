{{-- Bileşen satırının görseli: seçilen ürünün görseli, yoksa çizimdeki parça numarası. --}}
<div style="display: flex; align-items: center; justify-content: center; width: 3.5rem; height: 3.5rem; margin-top: 1.5rem; border-radius: .5rem; overflow: hidden; border: 1px solid rgba(0,0,0,.1);" class="bg-gray-50 dark:bg-white/5">
    @if ($part?->imageUrl())
        <img src="{{ $part->imageUrl() }}" alt="{{ $part->name }}" style="width: 100%; height: 100%; object-fit: cover;">
    @else
        <span style="font-weight: 700; font-size: .875rem;" class="{{ $part ? 'text-success-600' : 'text-gray-400' }}">{{ $number ?? '—' }}</span>
    @endif
</div>
