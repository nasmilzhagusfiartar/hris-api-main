<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class SafeImageFile implements ValidationRule
{
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail("The {$attribute} must be a valid image file.");

            return;
        }

        $realPath = $value->getRealPath();
        if (! $realPath || ! is_readable($realPath)) {
            $fail("The {$attribute} could not be read.");

            return;
        }

        // 1. Inspect actual file bytes via finfo (ignore client-supplied extension/Content-Type)
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = strtolower((string) $finfo->file($realPath));

        if (! in_array($detectedMime, self::ALLOWED_MIMES, true)) {
            $fail("The {$attribute} must be a genuine JPG, PNG, or WEBP image.");

            return;
        }

        // 2. Verify image dimensions/headers via getimagesize (rejects text/PHP/SVG disguised as .jpg)
        $imageInfo = @getimagesize($realPath);
        if ($imageInfo === false || empty($imageInfo[0]) || empty($imageInfo[1])) {
            $fail("The {$attribute} is not a valid raster image.");

            return;
        }

        $imageMime = strtolower((string) ($imageInfo['mime'] ?? ''));
        if (! in_array($imageMime, self::ALLOWED_MIMES, true)) {
            $fail("The {$attribute} must be a genuine JPG, PNG, or WEBP image.");

            return;
        }
    }
}
