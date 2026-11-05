<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;

/** Resend-style error body: {"statusCode": 422, "name": "validation_error", "message": "..."} */
final class ApiError
{
    public static function make(int $status, string $name, string $message, array $headers = []): JsonResponse
    {
        return response()->json([
            'statusCode' => $status,
            'name' => $name,
            'message' => $message,
        ], $status, $headers);
    }

    public static function isApiRequest(\Illuminate\Http\Request $request): bool
    {
        return $request->is('emails', 'emails/*', 'v1/*');
    }
}
