# Contributing

1. Work on a branch; do not push directly to `main` after bootstrap.
2. Preserve canonical ownership and conditional-runtime boundaries.
3. Assign stable requirement references where applicable.
4. Add tests for every behavior change and negative path.
5. Run `bash scripts/qa.sh`.
6. Complete review → fix → fresh adversarial review → fix → full retest.
7. Never commit secrets, real event payloads or personal data.
8. A green CI result is not staging or production acceptance.
