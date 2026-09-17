#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]

def edit(path, old, new, count=1):
    p=root/path; s=p.read_text(encoding='utf-8')
    if old not in s: raise SystemExit(f'target missing: {path}: {old[:100]!r}')
    p.write_text(s.replace(old,new,count),encoding='utf-8')

# FUT-038 creates persistent governance records, so a read-only transparency capability cannot authorize execution.
edit('src/Domain/FutureFeatureRegistry.php',
"['CF05-FUT-038','Analytics Transparency Center','ai_intelligence','NEXT','standard','smai_view_transparency','Public/staff disclosure of approved aggregate analytics usage.']",
"['CF05-FUT-038','Analytics Transparency Center','ai_intelligence','NEXT','standard','smai_manage_future_intelligence','Public/staff disclosure of approved aggregate analytics usage.']")

p=root/'src/Domain/FutureFeatureService.php'; s=p.read_text(encoding='utf-8')
# Defense in depth: domain service itself enforces the governing capability, not REST alone.
s=s.replace("        if ($actorUserId < 1) return new WP_Error('smai_future_actor_required', 'An authenticated actor is required.', ['status'=>403]);\n        $definition = FutureFeatureRegistry::get($featureId);\n        if ($definition === null) return new WP_Error('smai_future_unknown', 'Unknown future feature.', ['status' => 404]);",
"        if ($actorUserId < 1) return new WP_Error('smai_future_actor_required', 'An authenticated actor is required.', ['status'=>403]);\n        $definition = FutureFeatureRegistry::get($featureId);\n        if ($definition === null) return new WP_Error('smai_future_unknown', 'Unknown future feature.', ['status' => 404]);\n        if (!user_can($actorUserId, 'smai_manage_future_intelligence')) return new WP_Error('smai_future_forbidden','Future feature configuration is not authorized.',['status'=>403]);",1)
# transition has action-specific authorization after action/reason validation
needle="        $reason=Text::truncate(trim($reason),500);if($reason==='')return new WP_Error('smai_future_reason_required','A governance reason is required.',['status'=>400]);\n"
replacement=needle+"        $requiredCapability=in_array($action,['approve','activate'],true)?'smai_approve_future_intelligence':'smai_manage_future_intelligence';\n        if(!user_can($actorUserId,$requiredCapability))return new WP_Error('smai_future_forbidden','Future feature lifecycle transition is not authorized.',['status'=>403]);\n"
if needle not in s: raise SystemExit('transition auth target missing')
s=s.replace(needle,replacement,1)
# run authorization using registry capability
needle="        $definition=FutureFeatureRegistry::get($featureId);if($definition===null)return new WP_Error('smai_future_unknown','Unknown future feature.',['status'=>404]);\n"
replacement=needle+"        $requiredCapability=(string)($definition['capability']??'');if($requiredCapability===''||!user_can($actorUserId,$requiredCapability))return new WP_Error('smai_future_forbidden','Future feature execution is not authorized.',['status'=>403]);\n"
if needle not in s: raise SystemExit('run auth target missing')
s=s.replace(needle,replacement,1)
# incidents reject silent unsupported fields and enforce management capability in the domain layer.
needle="        if ($actorUserId < 1) return new WP_Error('smai_future_actor_required', 'An authenticated actor is required.', ['status'=>403]);\n        $summary=Text::truncate(trim((string)($payload['summary']??'')),255);$severity=strtoupper((string)($payload['severity']??'SEV-4'));\n"
replacement="        if ($actorUserId < 1) return new WP_Error('smai_future_actor_required', 'An authenticated actor is required.', ['status'=>403]);\n        if(!user_can($actorUserId,'smai_manage_quality'))return new WP_Error('smai_future_forbidden','Analytics incident creation is not authorized.',['status'=>403]);\n        if(array_diff(array_keys($payload),['summary','severity','evidence'])!==[])return new WP_Error('smai_future_invalid_incident','Analytics incident contains unsupported fields.',['status'=>400]);\n        if(isset($payload['evidence'])&&!is_array($payload['evidence']))return new WP_Error('smai_future_invalid_incident','Analytics incident evidence must be an object.',['status'=>400]);\n        $summary=Text::truncate(trim((string)($payload['summary']??'')),255);$severity=strtoupper((string)($payload['severity']??'SEV-4'));\n"
if needle not in s: raise SystemExit('incident target missing')
s=s.replace(needle,replacement,1)
# Close TOCTOU window: revalidate global/base gates after locking each scheduled feature.
needle="                $row=$wpdb->get_row($wpdb->prepare('SELECT state,approved_by,requested_by,row_version,config_hash FROM `'.$this->db->table('future_features').'` WHERE feature_id=%s FOR UPDATE',$featureId),ARRAY_A);\n                if(!is_array($row)||(string)$row['state']!=='active'||(int)($row['approved_by']??0)<1||(int)$row['approved_by']===(int)($row['requested_by']??0)){ $this->rollback(); continue; }\n"
replacement=needle+"                if(!FutureActivationService::isApproved()||!RuntimeGate::queryEnabled()||!RuntimeGate::schemaReady()){ $this->rollback(); continue; }\n"
if needle not in s: raise SystemExit('scheduled gate target missing')
s=s.replace(needle,replacement,1)
p.write_text(s,encoding='utf-8')

(root/'scripts/future40-authorization-invariants-check.py').write_text("""#!/usr/bin/env python3
from pathlib import Path
import sys
r=Path(__file__).resolve().parents[1]
s=(r/'src/Domain/FutureFeatureService.php').read_text(encoding='utf-8')
g=(r/'src/Domain/FutureFeatureRegistry.php').read_text(encoding='utf-8')
e=[]
if "CF05-FUT-038','Analytics Transparency Center','ai_intelligence','NEXT','standard','smai_view_transparency'" in g:e.append('FUT-038 persistent mutation is still authorized by a read-only capability')
if "requiredCapability=(string)($definition['capability']??'')" not in s:e.append('FutureFeatureService run lacks domain-layer capability enforcement')
if "smai_manage_future_intelligence')) return new WP_Error('smai_future_forbidden'" not in s:e.append('future configuration lacks domain-layer capability enforcement')
if "array_diff(array_keys($payload),['summary','severity','evidence'])" not in s:e.append('incident schema silently accepts unsupported fields')
marker="if(!FutureActivationService::isApproved()||!RuntimeGate::queryEnabled()||!RuntimeGate::schemaReady())"
if marker not in s:e.append('scheduled Future-40 execution does not recheck gates after feature lock')
if e:print('\\n'.join(e),file=sys.stderr);sys.exit(1)
print('Future-40 authorization invariants check passed.')
""",encoding='utf-8')
qa=root/'scripts/qa.sh'; q=qa.read_text(encoding='utf-8'); needle='python3 scripts/deletion-retention-restore-invariants-check.py\n'
if 'future40-authorization-invariants-check.py' not in q:
    if needle not in q: raise SystemExit('qa target missing')
    qa.write_text(q.replace(needle,needle+'python3 scripts/future40-authorization-invariants-check.py\n',1),encoding='utf-8')
(root/'docs/REVIEW-ROUND-18.md').write_text("""# Review Round 18 — Future-40 Runtime, Artifacts and Authorization\n\nThe complete Future-40 registry/service/activation/artifact/REST execution surface was audited before corrections began.\n\n## Confirmed defects\n1. `FutureFeatureService` relied on REST permission callbacks for configuration, lifecycle and feature execution; direct domain-service invocation did not enforce the governing capability.\n2. FUT-038 (Analytics Transparency Center) used the read-only `smai_view_transparency` capability even though an active run persists a new transparency governance record.\n3. Analytics incident creation silently ignored unsupported top-level payload fields instead of enforcing a closed incident contract.\n4. `scheduledTick()` checked the global Future-40/base runtime gate before selecting candidates but did not recheck those gates after locking an individual feature, leaving a disable-vs-scheduled-run race window.\n\n## Corrections\nDomain-layer authorization now mirrors the governing capability model; FUT-038 execution requires management authority; incident payloads are closed-schema; scheduled evidence generation revalidates Future-40 approval, base runtime and schema readiness after the feature lock; permanent QA invariants were added.\n\nNo staging/live state is asserted by this source review.\n""",encoding='utf-8')
print('Review 18 corrections applied.')
