# Contributing

1. Fork and create a branch from `main`.
2. `composer install`
3. Make your change with tests. Field names and ordering that come from the
   SkipCash documentation must be copied verbatim into tests, never normalised.
4. `composer check` must pass (Pint, PHPStan, PHPUnit).
5. Open a pull request describing the behaviour change and the documentation
   page it is based on.

Breaking changes to the public API (`Samt\SkipCash\SkipCash`, request/response
objects, events) require a major version bump.
