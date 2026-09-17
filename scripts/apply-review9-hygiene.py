#!/usr/bin/env python3
from pathlib import Path

# Round 9 audit completed before corrections. Frozen defect ledger:
# R9-01 Three retired contents:write one-shot workflows remained retriggerable.
# R9-02 Their stale mutation scripts remained capable of replaying obsolete patches/payloads.
# R9-03 QA had no repository-hygiene gate preventing retired mutation tooling/markers from returning.
# R9-04 Future-40/changelog/status documentation had drifted behind the Round 5-8 governance hardening.

root=Path('.')
retired=[
    '.github/workflows/apply-future40.yml',
    '.github/workflows/apply-review40.yml',
    '.github/workflows/finalize-coding.yml',
    'scripts/apply-future40.py',
    'scripts/apply-review40.py',
    'scripts/finalize-coding.py',
]
for name in retired:
    p=root/name
    if not p.is_file(): raise SystemExit(f'missing retired tooling expected by audit: {name}')
    p.unlink()

hygiene=r'''#!/usr/bin/env python3
from pathlib import Path
import sys

root=Path(__file__).resolve().parents[1]
errors=[]
retired=[
    '.github/workflows/apply-future40.yml',
    '.github/workflows/apply-review40.yml',
    '.github/workflows/finalize-coding.yml',
    'scripts/apply-future40.py',
    'scripts/apply-review40.py',
    'scripts/finalize-coding.py',
]
for rel in retired:
    if (root/rel).exists(): errors.append('retired_mutation_tooling_present:'+rel)
marker=root/'.codex'
if marker.exists(): errors.append('one_shot_marker_directory_present:.codex')
# Only the ordinary read/test/build CI is permitted to remain as a persistent
# workflow after source closure. Future review automation must be explicitly
# introduced, executed and self-removed inside its governed correction round.
workflow_dir=root/'.github/workflows'
allowed={'ci.yml'}
if workflow_dir.is_dir():
    for p in workflow_dir.iterdir():
        if p.is_file() and p.name not in allowed:
            errors.append('unexpected_persistent_workflow:'+p.name)
if errors:
    print('\n'.join(errors),file=sys.stderr);sys.exit(1)
print('Repository hygiene check passed: no retired mutation workflows/scripts or one-shot markers remain.')
'''
(root/'scripts/repository-hygiene-check.py').write_text(hygiene,encoding='utf-8')

p=root/'scripts/qa.sh';s=p.read_text(encoding='utf-8')
anchor='python3 scripts/future40-check.py\n'
if anchor not in s: raise SystemExit('qa anchor missing')
s=s.replace(anchor,anchor+'python3 scripts/repository-hygiene-check.py\n',1);p.write_text(s,encoding='utf-8')

p=root/'docs/FUTURE-40.md';s=p.read_text(encoding='utf-8')
old='''- `FutureFeatureService` provides configuration, row-versioned lifecycle, independent approval, activation gating, run evidence, incident evidence and scheduled internal evidence generation.\n- `FutureRestController` exposes governed list/configure/transition/run and incident endpoints.\n- New derivative-only tables persist feature governance, run evidence, incidents, scenarios/research metadata, internal alerts, transparency records and privacy budget state.\n'''
new='''- `FutureFeatureService` provides configuration, row-versioned lifecycle, independent approval, activation gating, run evidence, incident evidence and idempotent scheduled internal evidence generation pinned to feature row-version/config-hash provenance.\n- `FutureArtifactStore` binds active scenario, privacy-budget, research-workspace and transparency runs to governed persistent derivative artifacts; research workspaces require bounded expiry.\n- `FutureRestController` exposes governed list/configure/transition/run and incident endpoints through the same request-size, trace and idempotency discipline as the base API.\n- New derivative-only tables persist feature governance, run evidence, incidents, scenarios/research metadata, internal alerts, transparency records and privacy budget state; derivative Future evidence is governed by explicit retention windows.\n'''
if old not in s: raise SystemExit('Future-40 source map anchor missing')
s=s.replace(old,new,1);p.write_text(s,encoding='utf-8')

p=root/'CHANGELOG.md';s=p.read_text(encoding='utf-8')
anchor='## 1.0.0-rc.6 — 2026-09-16\n\n'
if anchor not in s: raise SystemExit('changelog anchor missing')
addition='''- Hardened Future-40 API mutation idempotency, request limits, trace/error contracts, actor boundaries and global activation atomicity.\n- Bound active Future runs to governed persistent artifacts and configuration/schema provenance.\n- Added explicit Future derivative retention, bounded research-workspace expiry, and idempotent scheduled evidence with independent-approval rechecks.\n- Removed retired one-shot mutation workflows/scripts and added a permanent repository-hygiene QA gate.\n'''
s=s.replace(anchor,anchor+addition,1);p.write_text(s,encoding='utf-8')

p=root/'docs/IMPLEMENTATION-STATUS.md';s=p.read_text(encoding='utf-8')
old='''The governed Future-40 source implementation immediately preceding this evidence-only status commit is `d3e815d806ebf6cd86955a02ba05362605851d29` (`feat: implement governed CF-05 Future-40 expansion`). That implementation contains executable handlers and QA coverage for `CF05-FUT-001..CF05-FUT-040`, 45 governed tables in total, Future-40 activation-evidence gates, independent approval controls, aggregate/advisory-only invariants and deterministic packaging. The implementation workflow completed full source QA and deterministic package verification before committing the source expansion.\n'''
new='''The initial governed Future-40 expansion was introduced by `d3e815d806ebf6cd86955a02ba05362605851d29`; subsequent governed review/fix rounds hardened API mutation safety, activation atomicity, actor boundaries, persistent artifact binding, derivative retention/research expiry, and scheduled-evidence idempotency/provenance. The exact current repository HEAD, not the initial expansion commit, is the source-truth identity for any present-tense verification. The branch continues to contain executable handlers and QA coverage for `CF05-FUT-001..CF05-FUT-040`, 45 governed tables, activation-evidence gates, independent approval controls, aggregate/advisory-only invariants and deterministic packaging.\n'''
if old not in s: raise SystemExit('implementation-status anchor missing')
s=s.replace(old,new,1);p.write_text(s,encoding='utf-8')

(root/'docs/REVIEW-ROUND-9.md').write_text('''# CF-05 Review Round 9 — Repository Hygiene and Documentation Parity\n\nAudit completed fully before corrections; the defect ledger was frozen first.\n\n## Defects found\n1. Retired `contents: write` one-shot workflows for Future-40, forty-round recovery and coding finalization remained retriggerable.\n2. Their stale patch/recovery scripts remained in the source tree and could replay obsolete transformations.\n3. Full QA had no repository-hygiene invariant preventing retired mutation tooling or `.codex` markers from returning.\n4. Future-40 source-map/changelog/status documentation had not incorporated the later Round 5-8 governance hardening.\n\n## Corrections\n- Removed all six retired mutation workflow/script artifacts.\n- Added `repository-hygiene-check.py` to mandatory source QA; persistent workflow allowlist is now only `ci.yml`.\n- Updated Future-40 source map, changelog and status text to match the hardened current architecture without claiming staging/live completion.\n\nRepository/source evidence only; staging, deployment and operational state remain separate.\n''',encoding='utf-8')
print('Review-9 repository hygiene and documentation parity corrections applied.')
