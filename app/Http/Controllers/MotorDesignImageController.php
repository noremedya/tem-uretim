<?php

namespace App\Http\Controllers;

use App\Models\MotorDesign;
use App\Services\MotorDesignService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Tasarımın motor görseli: yalnızca tasarımı görebilen, oturumu açık kullanıcıya (yerel disk, CDN yok). */
class MotorDesignImageController
{
    public function __invoke(Request $request, MotorDesign $motorDesign): StreamedResponse
    {
        abort_unless(config('features.motor_designs') && $request->user()->can('view', $motorDesign), 403);

        $disk = Storage::disk(MotorDesignService::IMAGE_DISK);
        abort_if($motorDesign->image_path === null || ! $disk->exists($motorDesign->image_path), 404);

        return $disk->response($motorDesign->image_path, headers: ['Cache-Control' => 'private, max-age=604800']);
    }
}
