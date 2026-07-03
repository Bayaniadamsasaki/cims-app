<?php

namespace App\Helpers;

use Illuminate\Http\JsonResponse;

class ResponseHelper
{
    public static function jsonResponse($success, $message, $data, $statusCode): JsonResponse
    {
        return response()->json([
            'status' => $success,
            'message' => $message,
            'data' => $data
        ], $statusCode);
    }

    public static function success($success, $message, $data, $statusCode): JsonResponse
    {
        return response()->json([
            'status' => $success,
            'message' => $message,
            'data' => $data
        ], $statusCode);
    }

    public static function error($error, $message, $statusCode): JsonResponse
    {
        return response()->json([
            'status' => $error,
            'message' => $message
        ], $statusCode);
    }
}