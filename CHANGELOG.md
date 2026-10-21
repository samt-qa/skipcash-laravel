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
