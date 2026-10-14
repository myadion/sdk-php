# Changelog

All notable changes to `myadion/sdk` are listed here. The project follows [Semantic Versioning](https://semver.org/); before 1.0, a minor version may break compatibility.

## 0.2.0 - 2026-10-14

- Adion Pay embedded form: `ui_mode` (`hosted` or `embedded`) and `return_url` when creating a session; an embedded session comes back with its own `client_secret` and no `url`. Your page hands the `client_secret` to `https://pay.adioncloud.com/v1/adion-pay.js`.

## 0.1.0 - 2026-10-14

First release.

- `MyAdion\Client`: access token obtained and renewed automatically (`client_credentials`), `x-api-project` and `x-api-org` headers, retries with backoff on 429, 5xx and network errors when safe, automatic `Idempotency-Key` on `POST /v1/pay/sessions`, configurable timeouts, PSR-18 transport (Guzzle or built-in cURL).
- Adion Pay: sessions, payments, refunds, webhook endpoints and their deliveries.
- SMS: send and retrieve.
- `request()` and `requestCollection()` for every other route, with lazy pagination.
- `MyAdion\Webhook::constructEvent()`: signature checked in constant time, timestamp tolerance.
- Typed errors: `AuthenticationError`, `PermissionError`, `InvalidRequestError` (per-field errors), `RateLimitError`, `ApiError`, `ApiConnectionError`, `SignatureVerificationError`.
- Optional Laravel bridge: service provider, `MyAdion` facade, `config/myadion.php`, token kept in the cache.
