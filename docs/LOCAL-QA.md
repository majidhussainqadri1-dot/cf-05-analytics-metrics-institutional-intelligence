# Local QA — 1.0.0-rc.4

Run:

```bash
bash scripts/qa.sh
bash scripts/verify-deterministic-build.sh
```

The suite covers PHP syntax, executable validators/privacy/crypto/transformation/statistical fixtures, all JSON contracts, 35 requirement IDs, 40 review IDs, three-plan traceability, schema/table parity, security primitives, secret patterns, release identity, deterministic ZIP, checksum, ZIP integrity and SBOM/package manifest creation and byte-for-byte source/package parity.

This is source-level evidence, not staging or operational acceptance.

Local deterministic ZIP SHA-256 after REV-46: `0525d499e3dea4cc263e2120c0d710c111f8593425293bd41c9f9c36740af765`.
