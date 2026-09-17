#!/usr/bin/env python3
from pathlib import Path
import sys
s=(Path(__file__).resolve().parents[1]/'src/Domain/ExperimentService.php').read_text(encoding='utf-8')
e=[]
for action in ['experiment_created','experiment_transition','experiment_assignment_recorded','experiment_analysis_created','experiment_analysis_published']:
    if f"audit->log('{action}'" in s: e.append(f'{action} still uses nested/best-effort audit logging')
if "audit->log('decision_recorded'" in s or "audit->log('decision_outcome_recorded'" in s: e.append('decision mutation audit is not atomic')
commit_pos=s.find("if ($wpdb->query('COMMIT') === false)")
signal_pos=s.find("do_action('smai_experiment_guardrail_breached'")
if signal_pos != -1 and (commit_pos == -1 or signal_pos < commit_pos): e.append('guardrail signal can escape before durable analysis commit')
if e: print('\n'.join(e),file=sys.stderr);sys.exit(1)
print('Experiment governance invariants check passed.')
