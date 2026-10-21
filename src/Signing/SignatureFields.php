<?php

declare(strict_types=1);

namespace Samt\SkipCash\Signing;

/**
 * Ordered field lists that participate in each HMAC signature.
 *
 * Order is mandatory. Only non-empty values are included. Field names are
 * case-sensitive and must match the wire format exactly.
 *
 * @see https://dev.skipcash.app/doc/ "Authentication", "Webhooks", "Refunds"
 */
final class SignatureFields
{
    /**
     * POST /api/v1/payments — signed with Key Secret.
     * Custom2..Custom10, TokenId, ReturnUrl, WebhookUrl, Subject, Description,
     * ExpiryDate, OnlyDebitCard and all recurring fields are body-only.
     */
    public const CREATE_PAYMENT = [
        'Uid',
        'KeyId',
        'Amount',
        'FirstName',
        'LastName',
        'Phone',
        'Email',
        'Street',
        'City',
        'State',
        'Country',
        'PostalCode',
        'TransactionId',
        'Custom1',
    ];

    /** Incoming webhook — signed with Webhook Key. */
    public const WEBHOOK = [
        'PaymentId',
        'Amount',
        'StatusId',
        'TransactionId',
        'Custom1',
        'VisaId',
    ];

    /** POST /api/v1/payments/refund — signed with Key Secret. Amount is body-only. */
    public const REFUND = [
        'Id',
        'KeyId',
    ];

    /** POST /api/v1/payments/subscription/cancel — signed with Key Secret. */
    public const CANCEL_SUBSCRIPTION = [
        'Id',
        'KeyId',
    ];

    /** POST /api/v1/payments/update-card — signed with Key Secret. */
    public const UPDATE_CARD = [
        'KeyId',
        'RecurringSubscriptionId',
    ];

    private function __construct() {}
}
