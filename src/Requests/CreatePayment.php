<?php

declare(strict_types=1);

namespace Samt\SkipCash\Requests;

use DateTimeInterface;
use Samt\SkipCash\Exceptions\InvalidRequestException;
use Samt\SkipCash\Support\Amount;
use Samt\SkipCash\Support\Phone;
use Samt\SkipCash\Support\Uuid;

/**
 * Immutable, validated request for POST /api/v1/payments.
 *
 * Build with named arguments:
 *
 *   CreatePayment::make(
 *       amount: 250,
 *       firstName: 'Noor',
 *       lastName: 'Al Thani',
 *       phone: '55123456',
 *       email: 'noor@example.com',
 *       transactionId: $order->number,
 *   )
 *
 * Validation mirrors the documented constraints so malformed requests fail
 * locally with a clear message instead of an opaque API error.
 */
final class CreatePayment
{
    private const NAME_PATTERN = '/^[\p{L}\p{M}\p{N} .\'\-]+$/u';

    private const TRANSACTION_ID_PATTERN = '/^[A-Za-z0-9._\-]+$/';

    private const MAX_ADDRESS_TOTAL = 50;

    private static string $defaultLocalCountryCode = '+974';

    private static string $defaultFallbackEmailDomain = 'customers.example.com';

    /** @var array<int, string> */
    private array $custom = [];

    private ?string $uid = null;

    private ?string $returnUrl = null;

    private ?string $webhookUrl = null;

    private ?string $expiryDate = null;

    private ?string $subject = null;

    private ?string $description = null;

    private bool $onlyDebitCard = false;

    private ?string $tokenId = null;

    private ?RecurringPlan $recurring = null;

    private function __construct(
        public readonly string $amount,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $phone,
        public readonly string $email,
        public readonly ?string $transactionId,
        public readonly ?string $street,
        public readonly ?string $city,
        public readonly ?string $state,
        public readonly ?string $country,
        public readonly ?string $postalCode,
    ) {}

    /**
     * @param  int|float|string  $amount  QAR, e.g. 99.50
     * @param  string|null  $email  When null, "{phone}@{fallbackEmailDomain}" is used.
     * @param  string|null  $localCountryCode  Defaults to `skipcash.defaults.local_country_code`.
     * @param  string|null  $fallbackEmailDomain  Defaults to `skipcash.defaults.fallback_email_domain`.
     */
    public static function make(
        int|float|string $amount,
        string $firstName,
        string $lastName,
        string $phone,
        ?string $email = null,
        ?string $transactionId = null,
        ?string $street = null,
        ?string $city = null,
        ?string $state = null,
        ?string $country = null,
        ?string $postalCode = null,
        ?string $localCountryCode = null,
        ?string $fallbackEmailDomain = null,
    ): self {
        $localCountryCode ??= self::$defaultLocalCountryCode;
        $fallbackEmailDomain ??= self::$defaultFallbackEmailDomain;

        $normalizedPhone = Phone::normalize($phone, $localCountryCode);
        $email = $email !== null && trim($email) !== ''
            ? trim($email)
            : ltrim($normalizedPhone, '+').'@'.$fallbackEmailDomain;

        $request = new self(
            amount: Amount::format($amount),
            firstName: self::name($firstName, 'FirstName'),
            lastName: self::name($lastName, 'LastName'),
            phone: $normalizedPhone,
            email: self::email($email),
            transactionId: self::transactionId($transactionId),
            street: self::bounded($street, 'Street', 60),
            city: self::bounded($city, 'City', 40),
            state: self::bounded($state, 'State', 2),
            country: self::bounded($country, 'Country', 2),
            postalCode: self::bounded($postalCode, 'PostalCode', 5),
        );

        $request->assertAddressLength();

        return $request;
    }

    /**
     * Set the defaults used by make() when the caller does not pass them.
     * Called by the service provider from config/skipcash.php.
     */
    public static function useDefaults(string $localCountryCode, string $fallbackEmailDomain): void
    {
        self::$defaultLocalCountryCode = $localCountryCode;
        self::$defaultFallbackEmailDomain = ltrim($fallbackEmailDomain, '@');
    }

    // ------------------------------------------------------------------ //
    //  Fluent, immutable "with" modifiers
    // ------------------------------------------------------------------ //

    /** Force a specific Uid (useful for tests). A fresh UUID is generated otherwise. */
    public function withUid(string $uid): self
    {
        $clone = clone $this;
        $clone->uid = $uid;

        return $clone;
    }

    /**
     * Set one of Custom1..Custom10. Custom1 participates in the signature.
     *
     * @param  int<1, 10>  $slot
     */
    public function withCustom(int $slot, string $value): self
    {
        if ($slot < 1 || $slot > 10) {
            throw InvalidRequestException::field('Custom', 'slot must be between 1 and 10');
        }

        if (mb_strlen($value) > 300) {
            throw InvalidRequestException::field("Custom{$slot}", 'must not exceed 300 characters');
        }

        $clone = clone $this;
        $clone->custom[$slot] = $value;

        return $clone;
    }

    public function withReturnUrl(string $url): self
    {
        $clone = clone $this;
        $clone->returnUrl = self::url($url, 'ReturnUrl');

        return $clone;
    }

    public function withWebhookUrl(string $url): self
    {
        $clone = clone $this;
        $clone->webhookUrl = self::url($url, 'WebhookUrl');

        return $clone;
    }

    /** Date after which the payment link stops working. */
    public function expiresOn(DateTimeInterface|string $date): self
    {
        $clone = clone $this;
        $clone->expiryDate = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : self::isoDate($date, 'ExpiryDate');

        return $clone;
    }

    /** Text shown on the hosted payment page. */
    public function withSubject(string $subject, ?string $description = null): self
    {
        $clone = clone $this;
        $clone->subject = $subject;
        $clone->description = $description;

        return $clone;
    }

    /** Send the shopper straight to QPAY (Qatari debit cards only). */
    public function debitCardOnly(bool $only = true): self
    {
        $clone = clone $this;
        $clone->onlyDebitCard = $only;

        return $clone;
    }

    /** Charge a previously tokenised card (shopper still confirms with OTP). */
    public function withToken(string $tokenId): self
    {
        $clone = clone $this;
        $clone->tokenId = $tokenId;

        return $clone;
    }

    public function recurring(RecurringPlan $plan): self
    {
        $clone = clone $this;
        $clone->recurring = $plan;

        return $clone;
    }

    // ------------------------------------------------------------------ //
    //  Accessors
    // ------------------------------------------------------------------ //

    public function uid(): string
    {
        return $this->uid ??= Uuid::v4();
    }

    public function returnUrl(): ?string
    {
        return $this->returnUrl;
    }

    public function webhookUrl(): ?string
    {
        return $this->webhookUrl;
    }

    public function isRecurring(): bool
    {
        return $this->recurring !== null;
    }

    /**
     * Custom fields keyed by slot (1..10).
     *
     * @return array<int, string>
     */
    public function customFields(): array
    {
        $fields = $this->custom;
        ksort($fields);

        return $fields;
    }

    /**
     * Full request body. Keys use the wire casing from the documentation.
     *
     * @return array<string, string|bool>
     */
    public function toArray(string $keyId): array
    {
        $body = array_filter([
            'Uid' => $this->uid(),
            'KeyId' => $keyId,
            'Amount' => $this->amount,
            'FirstName' => $this->firstName,
            'LastName' => $this->lastName,
            'Phone' => $this->phone,
            'Email' => $this->email,
            'Street' => $this->street,
            'City' => $this->city,
            'State' => $this->state,
            'Country' => $this->country,
            'PostalCode' => $this->postalCode,
            'TransactionId' => $this->transactionId,
            'Custom1' => $this->custom[1] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');

        for ($slot = 2; $slot <= 10; $slot++) {
            if (isset($this->custom[$slot])) {
                $body["Custom{$slot}"] = $this->custom[$slot];
            }
        }

        foreach ([
            'ExpiryDate' => $this->expiryDate,
            'Subject' => $this->subject,
            'Description' => $this->description,
            'TokenId' => $this->tokenId,
            'ReturnUrl' => $this->returnUrl,
            'WebhookUrl' => $this->webhookUrl,
        ] as $key => $value) {
            if ($value !== null && $value !== '') {
                $body[$key] = $value;
            }
        }

        if ($this->onlyDebitCard) {
            $body['OnlyDebitCard'] = true;
        }

        if ($this->recurring !== null) {
            $body += $this->recurring->toArray();
        }

        return $body;
    }

    // ------------------------------------------------------------------ //
    //  Validation helpers
    // ------------------------------------------------------------------ //

    private static function name(string $value, string $field): string
    {
        $value = trim($value);

        if ($value === '') {
            throw InvalidRequestException::field($field, 'is required');
        }

        if (mb_strlen($value) > 60) {
            throw InvalidRequestException::field($field, 'must not exceed 60 characters');
        }

        if (! preg_match(self::NAME_PATTERN, $value)) {
            throw InvalidRequestException::field($field, 'must not contain special characters');
        }

        return $value;
    }

    private static function email(string $value): string
    {
        if (mb_strlen($value) > 255 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw InvalidRequestException::field('Email', 'must be a valid email address of at most 255 characters');
        }

        return $value;
    }

    private static function transactionId(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (strlen($value) > 40) {
            throw InvalidRequestException::field('TransactionId', 'must not exceed 40 characters');
        }

        if (! preg_match(self::TRANSACTION_ID_PATTERN, $value)) {
            throw InvalidRequestException::field('TransactionId', 'may only contain letters, digits, ".", "_" and "-"');
        }

        return $value;
    }

    private static function bounded(?string $value, string $field, int $max): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (mb_strlen($value) > $max) {
            throw InvalidRequestException::field($field, "must not exceed {$max} characters");
        }

        return $value;
    }

    private static function url(string $value, string $field): string
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false || ! preg_match('#^https?://#i', $value)) {
            throw InvalidRequestException::field($field, 'must be an absolute http(s) URL');
        }

        return $value;
    }

    private static function isoDate(string $value, string $field): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw InvalidRequestException::field($field, 'must be in YYYY-MM-DD format');
        }

        return $value;
    }

    private function assertAddressLength(): void
    {
        $total = mb_strlen(implode('', array_filter([
            $this->street, $this->city, $this->state, $this->country, $this->postalCode,
        ])));

        if ($total > self::MAX_ADDRESS_TOTAL) {
            throw InvalidRequestException::field('Address', 'combined length of Street, City, State, Country and PostalCode must not exceed 50 characters');
        }
    }
}
