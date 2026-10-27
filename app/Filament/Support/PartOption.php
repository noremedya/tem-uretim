<?php

namespace App\Filament\Support;

use App\Models\Part;
use Illuminate\Support\Collection;

/**
 * Ürün seçim listelerinde görselli seçenek (Select::allowHtml ile): küçük görsel + etiket. Görseli olmayan üründe
 * aynı boyutta boş kutu (hizalama bozulmasın). Etiket kaçışlanır.
 */
final class PartOption
{
    public static function html(Part $part, string $label): string
    {
        $box = 'width:2rem;height:2rem;flex:none;border-radius:.25rem;border:1px solid rgba(127,127,127,.25);';
        $image = ($url = $part->imageUrl()) !== null
            ? '<img src="'.e($url).'" alt="" loading="lazy" style="'.$box.'object-fit:cover;">'
            : '<span style="'.$box.'"></span>';

        return '<span style="display:flex;align-items:center;gap:.5rem;">'.$image.'<span>'.e($label).'</span></span>';
    }

    /**
     * @param  Collection<int, Part>  $parts
     * @param  callable(Part): string  $label
     * @return array<int, string>
     */
    public static function options(Collection $parts, callable $label): array
    {
        return $parts->mapWithKeys(fn (Part $part): array => [$part->getKey() => self::html($part, $label($part))])->all();
    }
}
