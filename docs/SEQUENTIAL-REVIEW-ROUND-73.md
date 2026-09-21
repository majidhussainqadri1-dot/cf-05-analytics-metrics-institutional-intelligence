# Sequential Review Round 73 — Public contract and executable-validator parity

This round followed the required discipline: all public JSON contracts, executable validators and emitted integration manifests were audited first; the defect ledger was frozen before corrections began. Full repository QA must pass before Round 74 begins.

## Frozen defect ledger (9)
1. The public dataset contract allowed retention up to 3650 days while the executable validator allowed only 730.
2. The dataset JSON schema advertised unsupported top-level fields and omitted top-level fields accepted by the executable validator.
3. The dataset `fields` object was essentially untyped in the public schema even though runtime requires a closed field-definition contract.
4. The experiment JSON schema rejected runtime-supported `involves_minors` and `medical_context` fields because `additionalProperties` is false.
5. Experiment audience, metric and design structures were under-specified publicly, allowing payloads the executable validator rejects.
6. The executable experiment validator itself loosely coerced design integers, did not validate `minimum_practical_effect`/base-dimension values rigorously, and advertised an unused top-level design `allocation` field.
7. The Future-40 public feature schema did not match the actual list output: registry capability/purpose/default-state and persisted state/configuration/version metadata were absent or contradicted.
8. The runtime module manifest omitted `activation_approved` and `truth_status`, despite both being required by its public schema.
9. Source QA had no dedicated public-contract/runtime parity gate, allowing these mismatches to survive.

## Corrections
- Dataset schema now matches executable top-level fields, 730-day retention bound and closed typed field definitions.
- Experiment schema now models audience, metric specifications, variants/design and minor/medical flags; the executable validator now uses strict integer/numeric/list/object validation for the corresponding fields.
- Future-40 list schema now matches registry plus persisted list metadata while preserving default-disabled/human-governed/non-autonomous invariants.
- IntegrationRegistry now emits the required activation and lifecycle truth fields without claiming staging/live/operational completion.
- Added `scripts/public-contract-parity-check.py` and integrated it into `scripts/qa.sh`.

## Truth boundary
Repository-source contract parity and automated-QA evidence only. Contract correctness does not establish that any external consumer is already upgraded or that deployed integrations match this branch.
