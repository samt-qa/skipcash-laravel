<?php

declare(strict_types=1);

namespace Samt\SkipCash\Exceptions;

use Throwable;

/**
 * Thrown when SkipCash returns an error, a malformed body, or is unreachable.
 */
final class ApiException extends SkipCashException
{
    /**
     * @param  array<string, mixed>|null  $validationErrors
     * @param  array<string, mixed>|null  $body
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly int $returnCode = 0,
        public readonly int $errorCode = 0,
        public readonly ?array $validationErrors = null,
        public readonly ?array $body = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromResponse(int $httpStatus, array $body): self
    {
        $message = (string) ($body['errorMessage'] ?? $body['error'] ?? 'SkipCash API error');

        $validation = $body['validationErrors'] ?? null;
        if (is_array($validation) && $validation !== []) {
            $message .= ' Validation: '.json_encode($validation, JSON_UNESCAPED_UNICODE);
        }

        return new self(
            message: "SkipCash API request failed (HTTP {$httpStatus}): {$message}",
            httpStatus: $httpStatus,
            returnCode: (int) ($body['returnCode'] ?? 0),
            errorCode: (int) ($body['errorCode'] ?? 0),
            validationErrors: is_array($validation) ? $validation : null,
            body: $body,
        );
    }

    public static function malformed(int $httpStatus, string $raw): self
    {
        $snippet = mb_substr($raw, 0, 200);

        return new self("SkipCash returned a non-JSON response (HTTP {$httpStatus}): {$snippet}", $httpStatus);
    }

    public static function transport(Throwable $e): self
    {
        return new self('Could not reach SkipCash: '.$e->getMessage(), 0, 0, 0, null, null, $e);
    }

    public function isValidationError(): bool
    {
        return $this->validationErrors !== null && $this->validationErrors !== [];
    }
}
