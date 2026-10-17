@php
    /** @var array $layout */
    $w = $layout['label_width'];
    $h = $layout['label_height'];
    $small = $h < 45;
    $qr = $h - ($small ? 5 : 6);
@endphp
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    {{-- Bağımsız yazdırma sayfası: yalnızca satır içi stil, harici kaynak yok. --}}
    <style>
        @page { size: {{ $layout['page_width'] }}mm {{ $layout['page_height'] }}mm; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fff; color: #000; }
        body { font-family: "DejaVu Sans", "Segoe UI", Arial, sans-serif; }

        .toolbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; padding: 12px 16px; background: #f3f4f6; border-bottom: 1px solid #d1d5db; font-size: 15px; }
        .toolbar a, .toolbar button { font: inherit; padding: 8px 14px; border-radius: 8px; border: 1px solid #9ca3af; background: #fff; color: #111; text-decoration: none; cursor: pointer; }
        .toolbar .active { background: #111827; color: #fff; border-color: #111827; }
        .toolbar .hint { color: #4b5563; font-size: 13px; }

        .sheet {
            width: {{ $layout['page_width'] }}mm; height: {{ $layout['page_height'] }}mm;
            padding: {{ $layout['margin_top'] }}mm 0 0 {{ $layout['margin_left'] }}mm;
            display: grid; grid-template-columns: repeat({{ $layout['columns'] }}, {{ $w }}mm); grid-auto-rows: {{ $h }}mm;
            overflow: hidden; break-after: page; page-break-after: always;
        }
        .sheet:last-child { break-after: auto; page-break-after: auto; }
        .label { width: {{ $w }}mm; height: {{ $h }}mm; padding: {{ $small ? 2 : 2.5 }}mm; display: flex; gap: 2mm; align-items: center; overflow: hidden; }
        .qr { flex: 0 0 {{ $qr }}mm; width: {{ $qr }}mm; height: {{ $qr }}mm; }
        .qr svg { width: 100%; height: 100%; display: block; }
        .text { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 0.6mm; }
        .company { font-size: {{ $small ? 6 : 7 }}pt; font-weight: 700; letter-spacing: 0.08em; }
        .code { font-size: {{ $small ? 9 : 12 }}pt; font-weight: 700; font-family: "DejaVu Sans Mono", Consolas, monospace; word-break: break-all; }
        .title { font-size: {{ $small ? 6.5 : 8 }}pt; font-weight: 600; line-height: 1.15; max-height: 2.3em; overflow: hidden; }
        .line { font-size: {{ $small ? 5.5 : 7 }}pt; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        @media screen {
            body { background: #e5e7eb; }
            .sheets { padding: 16px; display: flex; flex-direction: column; gap: 16px; align-items: flex-start; }
            .sheet { background: #fff; box-shadow: 0 1px 4px rgba(0, 0, 0, .25); }
            .label { outline: 1px dashed #cbd5e1; }
        }
        @media print { .toolbar { display: none; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <strong>{{ $title }} ({{ $count }} etiket)</strong>
        @foreach ($layouts as $key => $option)
            <a href="{{ request()->fullUrlWithQuery(['layout' => $key]) }}" @class(['active' => $key === $layoutKey])>{{ $option['label'] }}</a>
        @endforeach
        <button type="button" onclick="window.print()">Yazdır</button>
        <a href="javascript:history.back()">Geri dön</a>
        <span class="hint">Yazıcıda ölçek %100 (gerçek boyut), kenar boşluğu "yok" seçin.</span>
    </div>

    <div class="sheets">
        @foreach ($pages as $page)
            <div class="sheet">
                @foreach ($page as $label)
                    <div class="label">
                        <div class="qr">{!! $label['qr'] !!}</div>
                        <div class="text">
                            <div class="company">{{ $company }}</div>
                            <div class="code">{{ $label['code'] }}</div>
                            <div class="title">{{ $label['title'] }}</div>
                            @foreach ($label['lines'] as $line)
                                <div class="line">{{ $line }}</div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    <script>window.addEventListener('load', () => window.print());</script>
</body>
</html>
