# Upgrade to 0.2

This release is prepared locally and has not been published yet.

- Invoice creation automatically adds a stable key for retries within one call. Persist and pass an explicit idempotency key when retrying an order across separate calls.
- Test keys send `SatLane-Version: 2026-10-05`. Use `contractVersion: null` only when deliberately targeting the legacy API contract.
- Sandbox simulation adds an action ID. Supply `action_id` or the second argument of `simulatePaid` when repeating the same scenario across calls.
- Timeline and webhook-management methods are dashboard-only. They now raise `LogicException` with guidance instead of issuing unauthenticatable API-key requests. Use invoice retrieval and the merchant dashboard.
- Unsafe mutations without idempotency are not automatically retried. Network errors and malformed JSON are surfaced explicitly.
- Injected transports now honor `ClientInterface`; the configured base URL, timeout and disabled automatic HTTP exceptions apply per request.
- Public snapshot calls omit Authorization. Signature parsing rejects malformed and duplicate fields.

The raw response remains an array, preserving current API fields. No USDT, Lightning or WalletConnect feature is included.
