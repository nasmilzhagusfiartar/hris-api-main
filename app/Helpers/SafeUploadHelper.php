<?php

namespace App\Helpers;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SafeUploadHelper
{
    private const MIME_TO_EXTENSION = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Derive file extension strictly from actual file content (finfo MIME / guessExtension),
     * NEVER from client-supplied filename or getClientOriginalExtension().
     */
    public static function resolveSafeImageExtension(UploadedFile $file): string
    {
        $realPath = $file->getRealPath();
        if (! $realPath || ! is_readable($realPath)) {
            throw new InvalidArgumentException('Uploaded image file cannot be read.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = strtolower((string) $finfo->file($realPath));

        if (isset(self::MIME_TO_EXTENSION[$detectedMime])) {
            return self::MIME_TO_EXTENSION[$detectedMime];
        }

        $guessed = strtolower((string) ($file->guessExtension() ?? ''));
        if (in_array($guessed, self::ALLOWED_EXTENSIONS, true)) {
            return $guessed === 'jpeg' ? 'jpg' : $guessed;
        }

        throw new InvalidArgumentException('Unsupported image type detected from file content.');
    }

    /**
     * Store a validated raster image with a random UUID filename and content-derived extension.
     */
    public static function storeImage(UploadedFile $file, string $directory, string $disk = 'public'): string
    {
        $ext = self::resolveSafeImageExtension($file);
        $filename = Str::uuid().'.'.$ext;

        return $file->storeAs($directory, $filename, $disk);
    }
}
