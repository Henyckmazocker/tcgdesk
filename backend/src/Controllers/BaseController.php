<?php

declare(strict_types=1);

namespace App\Controllers;

use InvalidArgumentException;

abstract class BaseController
{
    /**
     * Create a success response
     */
    protected function successResponse(string $message, ?array $data = null, int $httpCode = 200): array
    {
        return [
            'status'    => 'success',
            'message'   => $message,
            'data'      => $data,
            'http_code' => $httpCode,
        ];
    }

    /**
     * Create an error response
     */
    protected function errorResponse(string $message, int $httpCode = 400): array
    {
        return [
            'status'    => 'error',
            'message'   => $message,
            'data'      => null,
            'http_code' => $httpCode,
        ];
    }

    /**
     * Validate that required fields are present in input data
     */
    protected function validateRequiredFields(array $data, array $requiredFields): void
    {
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                throw new InvalidArgumentException("Field '{$field}' is required.");
            }
        }
    }
}
