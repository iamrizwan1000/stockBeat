<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * Standard envelope for every /api/v1 and /admin/api/v1 JSON response
 * (Plan §10): `{success, message, data}` on success, `{success, message,
 * errors}` on failure — the client can rely on `success` alone to branch,
 * regardless of which endpoint it called.
 */
class ApiResponse
{
    public static function success(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * @param  array<string, array<int, string>>|null  $errors
     */
    public static function error(string $message, ?array $errors = null, int $status = 422): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }
}
