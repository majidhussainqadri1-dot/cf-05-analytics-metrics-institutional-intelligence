# Sequential Review Round 35 — Public contracts and executable-validator consistency

This round followed the required discipline: the audit was completed first, the defect ledger was frozen, and only then were corrections applied. Full repository QA passed before the next round began.

## Frozen defect ledger (8)
1. Public contract ID dataset-definition.schema.json still declares 1.3.0 while repository contract family is 1.4.0.
2. Public contract ID event-envelope.schema.json still declares 1.3.0 while repository contract family is 1.4.0.
3. Public contract ID experiment-definition.schema.json still declares 1.3.0 while repository contract family is 1.4.0.
4. Public contract ID metric-response.schema.json still declares 1.3.0 while repository contract family is 1.4.0.
5. Public contract ID module-manifest.schema.json still declares 1.3.0 while repository contract family is 1.4.0.
6. Experiment JSON schema hypothesis bounds disagree with executable validator.
7. Experiment primary-metric count differs between public schema and validator.
8. Experiment public schema advertises enhanced_review input although validator derives/rejects it.

## Corrections
- Aligned public contract IDs with contract family 1.4.0.
- Aligned experiment JSON schema with executable validation and added strict-time regression coverage.

## Truth boundary
Repository-source review and automated QA only; this does not establish staging acceptance, deployed parity, live database state, live deployment, or operational verification.
