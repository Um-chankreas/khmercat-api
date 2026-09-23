<?php

namespace App\Helpers;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /**
     * Standard Success Response (200, 201)
     */
    public static function success(mixed $data = null, string $message = 'Success', int $code = 200): JsonResponse
    {
        $response = [
            'status' => 'success',
            'message' => $message,
        ];

        if (! is_null($data)) {
            $response['data'] = $data;
        }

        return response()->json($response, $code);
    }

    /**
     * Standard Warning Response (200, 202)
     * Use when an operation succeeds partially or requires user action.
     */
    public static function warning(mixed $data = null, string $message = 'Action completed with warnings', int $code = 200): JsonResponse
    {
        $response = [
            'status' => 'warning',
            'message' => $message,
        ];

        if (! is_null($data)) {
            $response['data'] = $data;
        }

        return response()->json($response, $code);
    }

    /**
     * Validation Error Response (422)
     */
    public static function validationError(mixed $errors = [], string $message = 'Validation failed'): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
            'errors' => $errors,
        ], 422);
    }

    /**
     * General Error Response (400, 401, 403, 404, 500)
     */
    public static function error(
        string $message = 'An error occurred',
        int $code = 400,
        mixed $errorDetails = null,
        ?array $meta = null // always included, for structured error data the client needs
    ): JsonResponse {
        $response = [
            'status' => 'error',
            'message' => $message,
        ];

        if ($meta !== null) {
            $response['meta'] = $meta;
        }

        if (! is_null($errorDetails) && config('app.debug')) {
            $response['debug'] = $errorDetails;
        }

        return response()->json($response, $code);
    }
}
