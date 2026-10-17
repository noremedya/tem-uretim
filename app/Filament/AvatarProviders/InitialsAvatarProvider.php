<?php

namespace App\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Baş harflerden yerel avatar (gömülü SVG). Filament'in varsayılanı ui-avatars.com'dan resim çeker;
 * CDN/harici kaynak kuralı ve internetsiz çalışma için kullanılmaz (CLAUDE.md bölüm 3 kural 1).
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $name = trim(Filament::getNameForDefaultAvatar($record));

        $initials = collect(preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY))
            ->take(2)
            ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('');

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64"><rect width="64" height="64" fill="#09090b"/><text x="50%%" y="50%%" dy=".35em" text-anchor="middle" font-family="sans-serif" font-size="26" fill="#ffffff">%s</text></svg>',
            htmlspecialchars($initials !== '' ? $initials : '?', ENT_XML1),
        );

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
