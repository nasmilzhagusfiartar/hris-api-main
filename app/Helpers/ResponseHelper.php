<?php

namespace App\Helpers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class ResponseHelper
{
    public const GENERIC_SERVER_ERROR = 'An unexpected error occurred. Please try again.';

    public static function jsonResponse($success, $message, $data, $statusCode): JsonResponse
    {
        if ($statusCode >= 500 && ! config('app.debug') && $message !== self::GENERIC_SERVER_ERROR) {
            Log::error((string) $message, [
                'user_id' => auth()->id(),
                'method' => request()?->method(),
                'endpoint' => request()?->fullUrl(),
                'status_code' => $statusCode,
            ]);
            $message = self::GENERIC_SERVER_ERROR;
        }

        return response()->json([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }

    public static function serverError(Throwable $e, string $debugPrefix = 'Internal Server Error'): JsonResponse
    {
        Log::error($e->getMessage(), [
            'exception' => get_class($e),
            'user_id' => auth()->id(),
            'method' => request()?->method(),
            'endpoint' => request()?->fullUrl(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        $message = config('app.debug')
            ? ($debugPrefix !== '' ? $debugPrefix.': '.$e->getMessage() : $e->getMessage())
            : self::GENERIC_SERVER_ERROR;

        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
        ], 500);
    }
}
