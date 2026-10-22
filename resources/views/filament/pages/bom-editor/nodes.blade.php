@foreach ($nodes as $node)
    @php($unit = $node['part']->unit->name)
    <tr class="border-b border-gray-100 dark:border-white/5">
        <td class="py-1.5 pe-4" style="padding-inline-start: {{ $node['depth'] * 1.5 }}rem">
            @if ($node['depth'] > 0)<span class="text-gray-400">└</span>@endif
            <a href="{{ $node['children'] !== [] ? \App\Filament\Pages\BomEditor::getUrl(['part' => $node['part']->getKey()]) : '#' }}" @class(['font-semibold' => $node['children'] !== [], 'pointer-events-none' => $node['children'] === []])>{{ $node['part']->code }}</a>
            — {{ $node['part']->name }}
            @if ($node['item']->scrapPart)<span class="text-xs text-gray-500">(fire → {{ $node['item']->scrapPart->code }})</span>@endif
        </td>
        <td class="py-1.5 pe-4 text-end">{{ \App\Support\Quantity::format($node['net'], $unit) }}</td>
        <td class="py-1.5 pe-4 text-end">{{ \App\Support\Quantity::format($node['scrap'], $unit) }}</td>
        <td class="py-1.5 text-end font-medium">{{ \App\Support\Quantity::format($node['total'], $unit) }}</td>
    </tr>
    @include('filament.pages.bom-editor.nodes', ['nodes' => $node['children']])
@endforeach
