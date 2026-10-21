#!/usr/bin/env python3
from pathlib import Path
import json, sys

root=Path(__file__).resolve().parents[1]
errors=[]

dataset=json.loads((root/'contracts/dataset-definition.schema.json').read_text(encoding='utf-8'))
experiment=json.loads((root/'contracts/experiment-definition.schema.json').read_text(encoding='utf-8'))
future=json.loads((root/'contracts/future-feature.schema.json').read_text(encoding='utf-8'))
module=json.loads((root/'contracts/module-manifest.schema.json').read_text(encoding='utf-8'))
exp_validator=(root/'src/Contracts/ExperimentDefinitionValidator.php').read_text(encoding='utf-8')
integration=(root/'src/Infrastructure/IntegrationRegistry.php').read_text(encoding='utf-8')

expected_dataset={'dataset_id','dataset_version','name','owner_module','grain','privacy_class','retention_days','region_code','provider_id','sources','fields','description','quality_policy','historical_semantics','owner_contact'}
actual_dataset=set(dataset.get('properties',{}))
if actual_dataset != expected_dataset:
    errors.append('dataset public schema property set differs from executable validator contract')
if dataset.get('properties',{}).get('retention_days',{}).get('maximum') != 730:
    errors.append('dataset retention maximum differs from executable validator')
field_schema=dataset.get('properties',{}).get('fields',{})
if field_schema.get('additionalProperties') is not False or not field_schema.get('patternProperties'):
    errors.append('dataset public field contract is not closed/typed')

for key in ['involves_minors','medical_context']:
    if key not in experiment.get('properties',{}):
        errors.append('experiment public schema omits '+key)
for key in ['audience','design']:
    if experiment.get('properties',{}).get(key,{}).get('additionalProperties') is not False:
        errors.append('experiment '+key+' contract is not closed')
for key in ['primary_metrics','guardrails']:
    if not isinstance(experiment.get('properties',{}).get(key,{}).get('items'),dict):
        errors.append('experiment '+key+' items are not typed')
for token in ["!is_int($minimumSample)","!is_int($durationDays)","invalid_audience_exclusion","invalid_base_dimension"]:
    if token not in exp_validator:
        errors.append('experiment executable validator missing typed contract guard: '+token)
if "'allocation'" in exp_validator.split("$allowedDesign =",1)[1].split(";",1)[0]:
    errors.append('experiment validator still advertises unused top-level design allocation')

future_required=set(future.get('required',[]))
for key in ['capability','purpose','default_state','state','configured','row_version','approved_by','updated_at']:
    if key not in future_required:
        errors.append('Future-40 public list contract omits '+key)
if future.get('properties',{}).get('default_state',{}).get('const') != 'disabled':
    errors.append('Future-40 default-disabled contract missing')

for token in ["'activation_approved' => RuntimeGate::activationApproved()","'truth_status' => [","'staging_accepted' => false","'live_deployed' => false","'operational' => false"]:
    if token not in integration:
        errors.append('runtime module manifest missing required truth field: '+token)
required_module=set(module.get('required',[]))
for key in ['activation_approved','truth_status']:
    if key not in required_module:
        errors.append('module manifest schema missing '+key)

if errors:
    for error in errors:
        print('FAIL:',error,file=sys.stderr)
    sys.exit(1)
print('Public contract/runtime parity check passed.')
