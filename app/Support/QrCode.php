<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode as Generator;
use chillerlan\QRCode\QROptions;

/**
 * Sunucu tarafında QR kod (etiketler, Aşama 12). İnternet/CDN gerekmez. İçerik kodun kendisidir
 * (seri no, iş emri no); Hızlı İşlem okunan değeri doğrudan çözer.
 */
final class QrCode
{
    /** Satır içi SVG (XML başlığı yok); boyutu CSS belirler. */
    public static function svg(string $data): string
    {
        return (new Generator(new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'eccLevel' => EccLevel::M,
            'quietzoneSize' => 2,
            'drawLightModules' => false,
            'connectPaths' => true,
        ])))->render($data);
    }

    /** PNG (testlerde kodu geri okumak için). */
    public static function png(string $data): string
    {
        return (new Generator(new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'scale' => 6,
        ])))->render($data);
    }

    public static function read(string $png): string
    {
        return (string) (new Generator(new QROptions))->readFromBlob($png);
    }
}
