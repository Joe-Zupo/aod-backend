<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ResponseAPI
{
    /**
     * Return a successful API response using the application's standard envelope.
     *
     * @param  array<string, mixed>  $data
     */
    protected function success(string $message, array $data = [], int $statusCode = 200): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => $data,
            'code' => $statusCode,
            'error' => false,
        ], $statusCode);
    }

    /**
     * Return an error API response using the application's standard envelope.
     *
     * @param  array<string, mixed>  $data
     */
    protected function error(string $message, int $statusCode = 500, array $data = []): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => $data,
            'code' => $statusCode,
            'error' => true,
        ], $statusCode);
    }
}
