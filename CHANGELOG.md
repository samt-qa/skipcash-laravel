# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres
to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Hosted-checkout payment creation with local validation of all documented constraints.
- Signed requests for refunds, subscription cancellation and card update.
- Verified webhook endpoint with idempotent inbox, monotonic state machine and optional API confirmation of `paid`.
- Queued webhook processing with backoff.
- `skipcash:reconcile` command.
- Tokenisation, recurring payments and QPAY debit-only flow.
- `SkipCash::fake()` test double with assertions.
- `CredentialsResolver` contract for multi-tenant applications.
- `PaymentStatus::InProgress` (`statusId` 12): the shopper has started paying (3-D Secure in progress). Treated as an open status; SkipCash resets it to `New` after 2 minutes.

### Fixed
- `sync()` and `skipcash:reconcile` now apply the remote status under a row lock inside a transaction, so a return-URL visit racing a webhook fires `PaymentPaid` once.
- Lifecycle events are dispatched after the transaction commits; a webhook whose listeners fail keeps the committed status and replays the events on SkipCash's retry.
- A failed attempt under a new payment id is only treated as a retry on an open link; a failed recurring instalment or a failure after the order was paid is recorded and fires `PaymentFailed`.
- The webhook inbox no longer deduplicates by `(payment_id, status_id)`; a second partial refund (`pending refund` again after `refunded`) is processed.

### Verified
- End-to-end against the SkipCash Sandbox: hosted checkout, 3-D Secure, `paid` webhook (signature, inbox, API confirmation, tokenisation), failed attempt on an existing link (`PaymentAttemptFailed`), return-URL parameters (`id`, `statusId`, `status`, `transId`, `custom1`), signature rejection (401), regression and duplicate handling, `skipcash:reconcile`.
