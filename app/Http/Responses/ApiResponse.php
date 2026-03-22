<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * API Response Handler
 * 
 * Provides a uniform response structure for all API endpoints
 * 
 * Usage:
 *   return ApiResponse::success($data, 'User created', 201);
 *   return ApiResponse::error('Validation failed', 422, $errors);
 *   return ApiResponse::paginated($items, $paginator);
 */
class ApiResponse
{
    /**
     * Success Response
     * 
     * @param mixed $data Response data (can be null)
     * @param string $message Success message
     * @param int $statusCode HTTP status code (200, 201, etc.)
     * @param array $meta Additional metadata (pagination, etc.)
     * @return JsonResponse
     */
    public static function success(
        mixed $data = null,
        string $message = 'Request successful',
        int $statusCode = 200,
        array $meta = []
    ): JsonResponse {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Error Response
     * 
     * @param string $message Error message
     * @param int $statusCode HTTP status code (400, 404, 500, etc.)
     * @param mixed $errors Error details (validation errors, etc.)
     * @param string|null $errorCode Application error code
     * @return JsonResponse
     */
    public static function error(
        string $message = 'Request failed',
        int $statusCode = 400,
        mixed $errors = null,
        ?string $errorCode = null
    ): JsonResponse {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errorCode) {
            $response['error_code'] = $errorCode;
        }

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Validation Error Response
     * 
     * @param array $errors Validation errors (from validator)
     * @param string $message Custom message
     * @return JsonResponse
     */
    public static function validationError(
        array $errors,
        string $message = 'Validation failed'
    ): JsonResponse {
        return self::error(
            message: $message,
            statusCode: 422,
            errors: $errors,
            errorCode: 'VALIDATION_ERROR'
        );
    }

    /**
     * Paginated Response
     * 
     * @param mixed $data Collection with pagination metadata
     * @param string $message Success message
     * @return JsonResponse
     */
    public static function paginated(
        mixed $data,
        string $message = 'Request successful'
    ): JsonResponse {
        // Check if data is a paginator instance
        if (method_exists($data, 'toArray')) {
            $paginated = $data->toArray();
            
            return self::success(
                data: $paginated['data'] ?? [],
                message: $message,
                statusCode: 200,
                meta: [
                    'pagination' => [
                        'total' => $paginated['total'] ?? 0,
                        'count' => count($paginated['data'] ?? []),
                        'per_page' => $paginated['per_page'] ?? 0,
                        'current_page' => $paginated['current_page'] ?? 1,
                        'last_page' => $paginated['last_page'] ?? 1,
                        'from' => $paginated['from'] ?? null,
                        'to' => $paginated['to'] ?? null,
                    ]
                ]
            );
        }

        return self::success($data, $message);
    }

    /**
     * Created Response (201)
     * 
     * @param mixed $data Created resource data
     * @param string $message Success message
     * @return JsonResponse
     */
    public static function created(
        mixed $data = null,
        string $message = 'Resource created successfully'
    ): JsonResponse {
        return self::success($data, $message, 201);
    }

    /**
     * Updated Response
     * 
     * @param mixed $data Updated resource data
     * @param string $message Success message
     * @return JsonResponse
     */
    public static function updated(
        mixed $data = null,
        string $message = 'Resource updated successfully'
    ): JsonResponse {
        return self::success($data, $message, 200);
    }

    /**
     * Deleted Response
     * 
     * @param string $message Success message
     * @return JsonResponse
     */
    public static function deleted(
        string $message = 'Resource deleted successfully'
    ): JsonResponse {
        return self::success(null, $message, 200);
    }

    /**
     * Unauthorized Response (401)
     * 
     * @param string $message Error message
     * @return JsonResponse
     */
    public static function unauthorized(
        string $message = 'Unauthorized'
    ): JsonResponse {
        return self::error(
            message: $message,
            statusCode: 401,
            errorCode: 'UNAUTHORIZED'
        );
    }

    /**
     * Forbidden Response (403)
     * 
     * @param string $message Error message
     * @return JsonResponse
     */
    public static function forbidden(
        string $message = 'Forbidden'
    ): JsonResponse {
        return self::error(
            message: $message,
            statusCode: 403,
            errorCode: 'FORBIDDEN'
        );
    }

    /**
     * Not Found Response (404)
     * 
     * @param string $message Error message
     * @param string $resource Resource type for context
     * @return JsonResponse
     */
    public static function notFound(
        string $message = 'Resource not found',
        string $resource = ''
    ): JsonResponse {
        if ($resource) {
            $message = "{$resource} not found";
        }

        return self::error(
            message: $message,
            statusCode: 404,
            errorCode: 'NOT_FOUND'
        );
    }

    /**
     * Conflict Response (409)
     * 
     * @param string $message Error message
     * @return JsonResponse
     */
    public static function conflict(
        string $message = 'Resource conflict'
    ): JsonResponse {
        return self::error(
            message: $message,
            statusCode: 409,
            errorCode: 'CONFLICT'
        );
    }

    /**
     * Too Many Requests Response (429)
     * 
     * @param string $message Error message
     * @return JsonResponse
     */
    public static function tooManyRequests(
        string $message = 'Too many requests. Please try again later.'
    ): JsonResponse {
        return self::error(
            message: $message,
            statusCode: 429,
            errorCode: 'TOO_MANY_REQUESTS'
        );
    }

    /**
     * Server Error Response (500)
     * 
     * @param string $message Error message
     * @param string|null $errorCode Error code
     * @return JsonResponse
     */
    public static function serverError(
        string $message = 'Internal server error',
        ?string $errorCode = null
    ): JsonResponse {
        return self::error(
            message: $message,
            statusCode: 500,
            errorCode: $errorCode ?? 'SERVER_ERROR'
        );
    }

    /**
     * Service Unavailable Response (503)
     * 
     * @param string $message Error message
     * @return JsonResponse
     */
    public static function serviceUnavailable(
        string $message = 'Service temporarily unavailable'
    ): JsonResponse {
        return self::error(
            message: $message,
            statusCode: 503,
            errorCode: 'SERVICE_UNAVAILABLE'
        );
    }

    /**
     * Collection Response (for multiple items)
     * 
     * @param array $items Collection of items
     * @param int $count Total count
     * @param string $message Success message
     * @return JsonResponse
     */
    public static function collection(
        array $items,
        int $count,
        string $message = 'Request successful'
    ): JsonResponse {
        return self::success(
            data: $items,
            message: $message,
            meta: [
                'count' => $count,
                'items' => count($items),
            ],
            statusCode: 200
        );
    }

    /**
     * Bulk Operation Response
     * 
     * @param int $total Total items processed
     * @param int $successful Successfully processed
     * @param int $failed Failed items
     * @param array|null $errors Error details
     * @param string $message Success message
     * @return JsonResponse
     */
    public static function bulkOperation(
        int $total,
        int $successful,
        int $failed = 0,
        ?array $errors = null,
        string $message = 'Bulk operation completed'
    ): JsonResponse {
        return self::success(
            data: null,
            message: $message,
            meta: [
                'bulk_operation' => [
                    'total' => $total,
                    'successful' => $successful,
                    'failed' => $failed,
                    'errors' => $errors,
                ]
            ]
        );
    }
}
