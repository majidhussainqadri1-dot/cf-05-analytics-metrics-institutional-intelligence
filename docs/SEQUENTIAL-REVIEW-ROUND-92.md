# Sequential Review Round 92 — Idempotency replay and bearer-secret persistence

The complete idempotency lifecycle, mutation helper, export token issuance, completed-request replay and idempotency table persistence were reviewed before any correction. The defect ledger was frozen first.

## Frozen defect ledger (2)
1. Completed mutation responses were stored verbatim in `idempotency_keys.response_json`. Export creation responses contain the one-time `download_token`, so the bearer secret was duplicated in plaintext even though the export table correctly stores only its hash.
2. Idempotent replay read the stored response as plaintext and therefore had no protected-at-rest path for responses containing bearer secrets.

## Corrections after review completion
- Idempotency completion now detects secret-bearing response keys recursively.
- Secret-bearing replay payloads are authenticated-encrypted with the configured export key and bound to the exact idempotency identity hash.
- Replay decrypts and verifies that envelope before returning the original response.
- Missing/rotated keys fail closed rather than exposing or fabricating a token.
- Added permanent static security invariants preventing plaintext secret-bearing replay persistence.

## Truth boundary
This is repository persistence hardening. It does not prove encryption-key configuration, database-at-rest controls, deployed idempotency rows or live secret rotation behavior.
