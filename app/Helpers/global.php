<?php

if (!function_exists('extractAmount')) {
    function extractAmount($amount) {
        preg_match('/\d+(\.\d+)?/', $amount, $matches);
        return isset($matches[0]) ? (float)$matches[0] : null;
    }
}

if (!function_exists('extractCurrency')) {
    function extractCurrency($amount) {
        preg_match('/^[A-Za-z$]+/', $amount, $matches);
        return $matches[0] ?? null;
    }
}

if (!function_exists('buildResponse')) {
    function buildResponse(string $status, string $message, int $statusCode = 200)
    {
        return response()->json([
            'status' => $status,
            'message' => $message,
        ], $statusCode);
    }
}
