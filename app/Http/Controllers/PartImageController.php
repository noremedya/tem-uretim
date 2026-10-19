<?php

namespace App\Http\Controllers;

use App\Models\Part;
use App\Services\PartService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Stok kartı görseli: yalnızca kartı görebilen, oturumu açık kullanıcıya (görseller yerel diskte, CDN yok). */
class PartImageController
{
    public function __invoke(Request $request, Part $part): StreamedResponse
    {
        abort_unless($request->user()->can('view', $part), 403);

        $disk = Storage::disk(PartService::IMAGE_DISK);
        abort_if($part->image_path === null || ! $disk->exists($part->image_path), 404);

        // Adres görsel değişince değiştiği için (?v=) tarayıcı uzun süre önbellekte tutabilir.
        return $disk->response($part->image_path, headers: ['Cache-Control' => 'private, max-age=604800']);
    }
}
