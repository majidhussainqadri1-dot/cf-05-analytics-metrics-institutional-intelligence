# QA Evidence Law — 1.0.0-rc.3

Run:

```bash
bash scripts/qa.sh
bash scripts/verify-deterministic-build.sh
```

## Automated source suite

1. PHP syntax across plugin, test and build PHP files.
2. Existing executable domain/contract/statistics/security helper tests.
3. Dimension privacy, prohibited-combination and differencing-policy fixtures.
4. JSON contracts, manifest and composer validation.
5. Architecture, schema-table parity and `CF05-FR-001..035` traceability.
6. Three-plan consistency checks.
7. Secret and prohibited-runtime-primitive scans.
8. Release identity and contract-version alignment.
9. Deterministic byte-for-byte package rebuild, checksum, ZIP and SBOM/manifest integrity.

GitHub Actions executes the suite on PHP 8.1, 8.2 and 8.3. The exact workflow result attached to an exact commit is authoritative; documentation does not substitute for that evidence.

## Human/external acceptance not simulated by this suite

Hostinger staging, actual database migrations with representative data, real File 00/20/23/24/25/26 contracts, browser/device/RTL/accessibility, sustained load, penetration testing, provider sandboxes, backup/restore, rollback rehearsal and Founder acceptance remain separate gates.
