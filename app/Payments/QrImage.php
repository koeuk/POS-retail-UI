<?php

namespace App\Payments;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * QR text to an inline SVG. Rendered server-side so the till needs no QR
 * library of its own, and the static code can ride along in the offline feed
 * as plain markup that Dexie stores like any other string.
 */
final class QrImage
{
    public static function svg(string $text): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
            'svgAddXmlHeader' => false,
            // Dark modules on white whatever the app theme: banking apps
            // struggle with an inverted code.
            'drawLightModules' => true,
            'moduleValues' => [],
        ]);

        return (new QRCode($options))->render($text);
    }
}
