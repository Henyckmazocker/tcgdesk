<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Log\LoggerInterface;

/**
 * Validation Middleware
 * Validates required fields in request
 */
class ValidationMiddleware implements MiddlewareInterface
{
    private array $requiredFields;

    public function __construct(
        private readonly LoggerInterface $logger,
        array $requiredFields = []
    ) {
        $this->requiredFields = $requiredFields;
    }

    /**
     * Set configuration for this middleware instance
     *
     * @param array $config Configuration array with 'required' key
     */
    public function setConfig(array $config): void
    {
        if (isset($config['required'])) {
            $this->requiredFields = $config['required'];
        }
    }

    public function handle(array $request, callable $next): array
    {
        // Validate required fields in request data
        $missingFields = [];
        foreach ($this->requiredFields as $field) {
            $data = $request['data'] ?? [];
            if (!isset($data[$field]) || $data[$field] === '') {
                $missingFields[] = $field;
            }
        }

        if (!empty($missingFields)) {
            $this->logger->warning('Validation failed - Missing required fields', [
                'action'         => $request['action'] ?? 'unknown',
                'missing_fields' => $missingFields,
                'user_id'        => $request['user_id'] ?? 'unknown',
            ]);

            return [
                'status'    => 'error',
                'message'   => 'Missing required fields: ' . implode(', ', $missingFields),
                'http_code' => 400,
            ];
        }

        // Pass to next middleware
        return $next($request);
    }
}
