<?php

namespace App\Filament\Concerns;

use App\Models\BomTemplate;
use App\Models\Part;
use App\Support\Quantity;
use Closure;
use Illuminate\Database\Eloquent\Collection;

/**
 * Motor şablonundan bileşen seçen ekranlar ("Motor oluştur", "Bileşen seçerek tasarla") için ortak: montaj çizimi
 * işaretleri (satırda ürün veya deneme bileşeni varsa yeşil), seçili ürünlerin tek sorguda okunması, pozitif miktar kuralı.
 * Kullanan sayfa form durumunu $data['rows'] altında tutar.
 */
trait InteractsWithAssemblyDrawing
{
    /** @var Collection<int, Part>|null İstek boyunca seçili ürünler (görsel ve birim için tek sorgu). */
    protected ?Collection $selectedParts = null;

    /**
     * Çizim ve numara işaretleri: şablonda olan numara, satırlarından birine ürün seçildiyse "seçildi".
     *
     * @return array<string, mixed>
     */
    protected function drawingData(mixed $templateId, array $rows): array
    {
        $template = filled($templateId) ? BomTemplate::query()->find($templateId) : null;
        $drawing = $template?->drawingConfig();

        if ($drawing === null) {
            return ['drawing' => null];
        }

        $markers = [];

        foreach ($template->lines as $line) {
            if ($line->drawing_number === null) {
                continue;
            }

            $number = $line->drawing_number;
            $markers[$number] ??= ['label' => $line->drawingLabel(), 'lines' => [], 'selected' => false, 'target' => "motor-line-{$line->getKey()}"];
            $markers[$number]['lines'][] = $line->label;
            $markers[$number]['selected'] = $markers[$number]['selected'] || filled($rows[$line->getKey()]['part_id'] ?? null) || filled($rows[$line->getKey()]['description'] ?? null);
        }

        $points = [];

        foreach ($markers as $number => $marker) {
            foreach ($drawing['markers'][$number] ?? [] as [$x, $y]) {
                $points[] = [...$marker, 'left' => round($x / $drawing['width'] * 100, 2), 'top' => round($y / $drawing['height'] * 100, 2)];
            }
        }

        return ['drawing' => $drawing, 'points' => $points];
    }

    protected function part(mixed $id): ?Part
    {
        if (blank($id)) {
            return null;
        }

        if ($this->selectedParts === null || ! $this->selectedParts->has((int) $id)) {
            $ids = collect($this->data['rows'] ?? [])->pluck('part_id')->filter()->map(fn ($v) => (int) $v)->push((int) $id)->unique();
            $this->selectedParts = Part::query()->with('unit')->whereKey($ids)->get()->keyBy('id');
        }

        return $this->selectedParts->get((int) $id);
    }

    protected function positiveRule(string $message): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($message): void {
            if (filled($value) && (! Quantity::isValid($value) || Quantity::compare(Quantity::parse($value), '0') <= 0)) {
                $fail($message);
            }
        };
    }
}
