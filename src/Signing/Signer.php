<?php

declare(strict_types=1);

namespace Samt\SkipCash\Signing;

use SensitiveParameter;

/**
 * Builds and verifies SkipCash HMAC-SHA256 signatures.
 *
 * Algorithm (identical for requests and webhooks):
 *   1. Take the ordered field list for the operation.
 *   2. Keep fields whose value is neither null nor an empty string.
 *   3. Join as "Key=Value" pairs separated by commas (no spaces).
 *   4. HMAC-SHA256 with the secret, raw binary output.
 *   5. Base64-encode.
 */
final class Signer
{
    /**
     * @param  list<string>  $orderedFields
     * @param  array<string, mixed>  $data
     */
    public function sign(array $orderedFields, array $data, #[SensitiveParameter] string $secret): string
    {
        $payload = $this->payload($orderedFields, $data);

        return base64_encode(hash_hmac('sha256', $payload, $secret, true));
    }

    /**
     * Constant-time comparison of a provided signature against the expected one.
     *
     * @param  list<string>  $orderedFields
     * @param  array<string, mixed>  $data
     */
    public function verify(array $orderedFields, array $data, #[SensitiveParameter] string $secret, string $provided): bool
    {
        $expected = $this->sign($orderedFields, $data, $secret);

        return hash_equals($expected, trim($provided));
    }

    /**
     * The exact string that gets hashed. Exposed for debugging and tests.
     *
     * @param  list<string>  $orderedFields
     * @param  array<string, mixed>  $data
     */
    public function payload(array $orderedFields, array $data): string
    {
        $parts = [];

        foreach ($orderedFields as $field) {
            $value = $data[$field] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $parts[] = $field.'='.$this->stringify($value);
        }

        return implode(',', $parts);
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value), is_string($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            default => (string) json_encode($value),
        };
    }
}
