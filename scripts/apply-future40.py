#!/usr/bin/env python3
from __future__ import annotations
import json,pathlib,re
ROOT=pathlib.Path(__file__).resolve().parents[1]

def read(path:str)->str:return (ROOT/path).read_text(encoding='utf-8')
def write(path:str,text:str)->None:(ROOT/path).write_text(text,encoding='utf-8')
def replace_once(path:str,old:str,new:str,label:str)->None:
    text=read(path)
    if old not in text:
        if new in text:return
        raise SystemExit(f'Future40 apply failed: {label} anchor missing in {path}')
    write(path,text.replace(old,new,1))
def replace_all(path:str,old:str,new:str)->None:
    text=read(path)
    if old in text:write(path,text.replace(old,new))

def fix_engine()->None:
    path='src/Domain/Future40Engine.php'
    bad="""        $scan=function(mixed $value,int $depth=0) use (&$scan,$forbidden):void {
            if($depth>6)throw new \\InvalidArgumentException('Future feature input is too deeply nested.');
            if(!is_array($value))return;
            foreach($value as $k=>$v){$key=strtolower((string)$k;);}
        };
"""
    text=read(path)
    if bad in text:write(path,text.replace(bad,'',1))

def patch_database()->None:
    replace_once('src/Infrastructure/Database.php',
"""        'rate_limits', 'idempotency_keys', 'dashboard_definitions', 'dashboard_widgets',
""",
"""        'rate_limits', 'idempotency_keys', 'dashboard_definitions', 'dashboard_widgets',
        'future_features', 'future_runs', 'analytics_incidents', 'scenario_models',
        'research_workspaces', 'intelligence_alerts', 'transparency_records', 'privacy_budgets',
""",'database Future40 tables')

def patch_schema()->None:
    tables=r'''        $sql[] = "CREATE TABLE {$p}future_features (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            feature_id varchar(32) NOT NULL,
            title varchar(190) NOT NULL,
            category varchar(64) NOT NULL,
            phase varchar(32) NOT NULL,
            risk_class varchar(32) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'disabled',
            config_json longtext NOT NULL,
            config_hash char(64) NOT NULL,
            requested_by bigint unsigned NOT NULL,
            approved_by bigint unsigned NULL,
            row_version bigint unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY feature_id (feature_id),
            KEY state_phase (state,phase),
            KEY category (category)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}future_runs (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            run_uuid char(36) NOT NULL,
            feature_id varchar(32) NOT NULL,
            mode varchar(16) NOT NULL,
            request_hash char(64) NOT NULL,
            result_json longtext NOT NULL,
            result_hash char(64) NOT NULL,
            actor_user_id bigint unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY run_uuid (run_uuid),
            KEY feature_time (feature_id,created_at),
            KEY actor_time (actor_user_id,created_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}analytics_incidents (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            incident_uuid char(36) NOT NULL,
            severity varchar(16) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'open',
            summary varchar(255) NOT NULL,
            evidence_json longtext NOT NULL,
            owner_user_id bigint unsigned NOT NULL,
            resolved_by bigint unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            resolved_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY incident_uuid (incident_uuid),
            KEY severity_state (severity,state),
            KEY owner_state (owner_user_id,state)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}scenario_models (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            scenario_uuid char(36) NOT NULL,
            feature_id varchar(32) NOT NULL,
            name varchar(190) NOT NULL,
            definition_json longtext NOT NULL,
            result_json longtext NULL,
            owner_user_id bigint unsigned NOT NULL,
            row_version bigint unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY scenario_uuid (scenario_uuid),
            KEY feature_owner (feature_id,owner_user_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}research_workspaces (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            workspace_uuid char(36) NOT NULL,
            title varchar(190) NOT NULL,
            purpose varchar(500) NOT NULL,
            datasets_json longtext NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'draft',
            owner_user_id bigint unsigned NOT NULL,
            approved_by bigint unsigned NULL,
            expires_at datetime NULL,
            row_version bigint unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY workspace_uuid (workspace_uuid),
            KEY owner_state (owner_user_id,state),
            KEY expires_at (expires_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}intelligence_alerts (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            alert_uuid char(36) NOT NULL,
            feature_id varchar(32) NOT NULL,
            severity varchar(16) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'evidence_ready',
            title varchar(190) NOT NULL,
            evidence_json longtext NOT NULL,
            audience_json longtext NULL,
            created_at datetime NOT NULL,
            acknowledged_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY alert_uuid (alert_uuid),
            KEY feature_state (feature_id,state),
            KEY severity_time (severity,created_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}transparency_records (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            record_uuid char(36) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'draft',
            purpose varchar(500) NOT NULL,
            metric_ids_json longtext NOT NULL,
            data_classes_json longtext NOT NULL,
            retention_summary varchar(500) NOT NULL,
            approved_by bigint unsigned NULL,
            published_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY record_uuid (record_uuid),
            KEY state_published (state,published_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$p}privacy_budgets (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            budget_key char(64) NOT NULL,
            project_uuid char(36) NOT NULL,
            metric_id varchar(190) NOT NULL,
            epsilon_allocated decimal(20,10) NOT NULL DEFAULT 0,
            epsilon_spent decimal(20,10) NOT NULL DEFAULT 0,
            row_version bigint unsigned NOT NULL DEFAULT 1,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY budget_key (budget_key),
            KEY project_metric (project_uuid,metric_id)
        ) {$charset};";

'''
    marker="        update_option('smai_schema_migration_error', ["
    text=read('src/Infrastructure/SchemaMigrator.php')
    if 'CREATE TABLE {$p}future_features (' not in text:
        if marker not in text:raise SystemExit('Future40 apply failed: schema insertion anchor missing')
        write('src/Infrastructure/SchemaMigrator.php',text.replace(marker,tables+marker,1))

def patch_activator()->None:
    path='src/Infrastructure/Activator.php'
    replace_once(path,"""        foreach (['smai_daily_retention','smai_run_jobs','smai_schedule_reports','smai_access_expiry'] as $hook) {
""","""        foreach (['smai_daily_retention','smai_run_jobs','smai_schedule_reports','smai_access_expiry','smai_future_intelligence_tick'] as $hook) {
""",'deactivation schedule')
    replace_once(path,"""        add_option('smai_provider_exit_state', 'ready', '', false);
""","""        add_option('smai_provider_exit_state', 'ready', '', false);
        add_option('smai_future40_state', 'disabled', '', false);
        add_option('smai_future40_approved', '0', '', false);
        add_option('smai_future40_evidence_hash', '', '', false);
""",'future defaults')
    replace_once(path,"""            'smai_manage_deletions','smai_restore','smai_audit','smai_activate_runtime',
""","""            'smai_manage_deletions','smai_restore','smai_audit','smai_activate_runtime',
            'smai_manage_future_intelligence','smai_run_future_intelligence','smai_approve_future_intelligence','smai_view_transparency',
""",'future capabilities all')
    replace_once(path,"""                'caps' => ['read','smai_view_insights','smai_query_metrics'],
""","""                'caps' => ['read','smai_view_insights','smai_query_metrics','smai_run_future_intelligence'],
""",'analyst future cap')
    replace_once(path,"""                'caps' => ['read','smai_view_insights','smai_manage_catalog','smai_manage_quality','smai_manage_backfills','smai_manage_providers','smai_ingest_events'],
""","""                'caps' => ['read','smai_view_insights','smai_manage_catalog','smai_manage_quality','smai_manage_backfills','smai_manage_providers','smai_ingest_events','smai_manage_future_intelligence'],
""",'steward future cap')
    replace_once(path,"""                'caps' => ['read','smai_view_insights','smai_approve_catalog','smai_activate_runtime'],
""","""                'caps' => ['read','smai_view_insights','smai_approve_catalog','smai_activate_runtime','smai_approve_future_intelligence'],
""",'approver future cap')
    replace_once(path,"""                'caps' => ['read','smai_view_insights','smai_audit'],
""","""                'caps' => ['read','smai_view_insights','smai_audit','smai_view_transparency'],
""",'auditor transparency cap')
    replace_once(path,"""            ['smai_access_expiry', time() + 15 * MINUTE_IN_SECONDS, 'hourly'],
""","""            ['smai_access_expiry', time() + 15 * MINUTE_IN_SECONDS, 'hourly'],
            ['smai_future_intelligence_tick', time() + 20 * MINUTE_IN_SECONDS, 'hourly'],
""",'future schedule')

def patch_plugin()->None:
    path='src/Plugin.php'
    replace_once(path,"use Sabri\\AnalyticsIntelligence\\Domain\\AccessProjectService;\n","use Sabri\\AnalyticsIntelligence\\Domain\\AccessProjectService;\nuse Sabri\\AnalyticsIntelligence\\Domain\\FutureFeatureService;\n",'future service import')
    replace_once(path,"use Sabri\\AnalyticsIntelligence\\Http\\GovernanceRestController;\n","use Sabri\\AnalyticsIntelligence\\Http\\FutureRestController;\nuse Sabri\\AnalyticsIntelligence\\Http\\GovernanceRestController;\n",'future controller import')
    replace_once(path,"        (new GovernanceRestController($database))->register();\n","        (new GovernanceRestController($database))->register();\n        (new FutureRestController($database))->register();\n",'future controller register')
    replace_once(path,"""        add_action('smai_access_expiry', static function () use ($database): void {
            (new AccessProjectService($database))->expireDue();
        });
""","""        add_action('smai_access_expiry', static function () use ($database): void {
            (new AccessProjectService($database))->expireDue();
        });
        add_action('smai_future_intelligence_tick', static function () use ($database): void {
            (new FutureFeatureService($database))->scheduledTick();
        });
""",'future tick hook')

def patch_release()->None:
    main='sabri-analytics-institutional-intelligence.php'
    replace_all(main,'1.0.0-rc.5','1.0.0-rc.6')
    replace_all(main,"define('SMAI_SCHEMA_VERSION', '1.3.0');","define('SMAI_SCHEMA_VERSION', '1.4.0');")
    replace_all(main,"define('SMAI_CONTRACT_VERSION', '1.3.0');","define('SMAI_CONTRACT_VERSION', '1.4.0');")
    text=read(main);anchor="define('SMAI_URL', plugin_dir_url(__FILE__));\n";addition="define('SMAI_URL', plugin_dir_url(__FILE__));\nif (!defined('SMAI_FUTURE40_EVIDENCE_SHA256')) {\n    define('SMAI_FUTURE40_EVIDENCE_SHA256', '');\n}\n"
    if 'SMAI_FUTURE40_EVIDENCE_SHA256' not in text:
        if anchor not in text:raise SystemExit('Future40 apply failed: main plugin constant anchor missing')
        write(main,text.replace(anchor,addition,1))
    manifest_path=ROOT/'MANIFEST.json';m=json.loads(manifest_path.read_text(encoding='utf-8'))
    m['version']='1.0.0-rc.6';m['schema_version']='1.4.0';m['contract_version']='1.4.0'
    sc=m.setdefault('source_completion',{});sc['requirements']='CF05-FR-001..CF05-FR-035 plus three-plan harmonization, 40 fresh review/fix rounds, and CF05-FUT-001..CF05-FUT-040 Future-40 expansion';sc['future40_features_coded']=40
    contracts=m.setdefault('public_contracts',[])
    if 'contracts/future-feature.schema.json' not in contracts:contracts.append('contracts/future-feature.schema.json')
    cfg=m.setdefault('required_private_configuration_for_activation',[])
    if 'SMAI_FUTURE40_EVIDENCE_SHA256' not in cfg:cfg.append('SMAI_FUTURE40_EVIDENCE_SHA256')
    manifest_path.write_text(json.dumps(m,indent=2,ensure_ascii=False)+'\n',encoding='utf-8')
    replace_all('scripts/build-package.py',"version='1.0.0-rc.5'","version='1.0.0-rc.6'")
    replace_all('scripts/build-package.py',"urn:uuid:cf05-analytics-1-0-0-rc-5","urn:uuid:cf05-analytics-1-0-0-rc-6")
    replace_all('scripts/build-package.py',"'schema_version':'1.3.0'","'schema_version':'1.4.0'")
    replace_all('scripts/build-package.py',"'contract_version':'1.3.0'","'contract_version':'1.4.0'")
    build=read('scripts/build-package.py')
    if "'future40_features_coded':40" not in build:
        build=build.replace("'review_rounds_completed':40,","'review_rounds_completed':40,'future40_features_coded':40,",1);write('scripts/build-package.py',build)
    replace_all('scripts/verify-deterministic-build.sh','1.0.0-rc.5','1.0.0-rc.6')
    replace_all('scripts/package-parity.py',"version='1.0.0-rc.5'","version='1.0.0-rc.6'")
    replace_all('scripts/architecture-check.py',"('1.0.0-rc.5','1.3.0','1.3.0')","('1.0.0-rc.6','1.4.0','1.4.0')")
    replace_all('scripts/cross-plan-check.py',"manifest.get('contract_version') != '1.3.0'","manifest.get('contract_version') != '1.4.0'")
    replace_all('scripts/cross-plan-check.py','manifest contract version is not 1.3.0','manifest contract version is not 1.4.0')
    replace_all('scripts/cross-plan-check.py',"manifest.get('version') != '1.0.0-rc.5'","manifest.get('version') != '1.0.0-rc.6'")
    replace_all('scripts/cross-plan-check.py','manifest version is not 1.0.0-rc.5','manifest version is not 1.0.0-rc.6')
    for path in ['README.md','readme.txt','docs/IMPLEMENTATION-STATUS.md','docs/FORTY-ROUND-COMPLETION.md','docs/CODING-COMPLETION-REPORT.md']:replace_all(path,'1.0.0-rc.5','1.0.0-rc.6')
    for path in ['docs/IMPLEMENTATION-STATUS.md','docs/FORTY-ROUND-COMPLETION.md','docs/CODING-COMPLETION-REPORT.md']:
        replace_all(path,'schema `1.3.0`','schema `1.4.0`');replace_all(path,'Database schema: `1.3.0`','Database schema: `1.4.0`');replace_all(path,'contract `1.3.0`','contract `1.4.0`');replace_all(path,'Public contract family: `1.3.0`','Public contract family: `1.4.0`')
    changelog=read('CHANGELOG.md')
    if '## 1.0.0-rc.6 — 2026-09-16' not in changelog:
        block="# Changelog\n\n## 1.0.0-rc.6 — 2026-09-16\n\n- Added governed CF-05 Future-40 expansion (`CF05-FUT-001..CF05-FUT-040`) with executable aggregate/advisory handlers.\n- Added Future-40 lifecycle, independent approval, activation evidence, dry-run execution, incident evidence and scheduled internal evidence controls.\n- Added Future-40 persistence, public feature contract, REST governance routes, privacy/re-identification safeguards and executable coverage for all 40 features.\n- Raised database schema and contract family to `1.4.0`; Future-40 remains disabled by default and does not imply staging/live/operational acceptance.\n\n"
        changelog=block+(changelog[len('# Changelog\n\n'):] if changelog.startswith('# Changelog\n\n') else changelog);write('CHANGELOG.md',changelog)
    status=read('docs/IMPLEMENTATION-STATUS.md')
    if 'Future-40' not in status:write('docs/IMPLEMENTATION-STATUS.md',status+"\nFuture-40: `CF05-FUT-001..CF05-FUT-040` source-coded and automated-QA covered; all Future-40 runtime features remain disabled until separate activation evidence/approval and environment gates pass.\n")
    readme=read('README.md')
    if 'Future-40' not in readme:write('README.md',readme+"\n## Future-40 expansion\n\n`CF05-FUT-001..CF05-FUT-040` are source-coded behind independent approval and exact activation-evidence gates. They remain aggregate-only, advisory, human-governed and disabled by default. See `docs/FUTURE-40.md`.\n")
    rt=read('docs/THREE-PLAN-TRACEABILITY.md')
    if 'Future-40 expansion' not in rt:write('docs/THREE-PLAN-TRACEABILITY.md',rt+"\n## Future-40 expansion\n\n`CF05-FUT-001..CF05-FUT-040` extend only the approved CF-05 derivative/aggregate analytics boundary. They do not acquire native domain truth, individual surveillance, unrestricted raw-data authority or autonomous clinical/financial/moderation decisions. Activation remains evidence-bound and separate from source coding.\n")

def patch_qa()->None:
    path='scripts/qa.sh';text=read(path)
    if 'php tests/future40.php' not in text:text=text.replace('php tests/privacy-policy.php\n','php tests/privacy-policy.php\nphp tests/future40.php\n',1)
    if 'python3 scripts/future40-check.py' not in text:text=text.replace('python3 scripts/schema-contract-check.py\n','python3 scripts/schema-contract-check.py\npython3 scripts/future40-check.py\n',1)
    write(path,text)
    path='scripts/architecture-check.py';text=read(path)
    if "'src/Domain/FutureFeatureRegistry.php'" not in text:
        text=text.replace(" 'src/Infrastructure/HealthService.php':['degradation_reasons','overdue_deletion_jobs','production_complete'],\n"," 'src/Infrastructure/HealthService.php':['degradation_reasons','overdue_deletion_jobs','production_complete'],\n 'src/Domain/FutureFeatureRegistry.php':['CF05-FUT-001','CF05-FUT-040','autonomous_decision'],\n 'src/Domain/Future40Engine.php':['CF05-FUT-001','CF05-FUT-040','advisory_only'],\n 'docs/FUTURE-40.md':['CF05-FUT-001','CF05-FUT-040','Status boundary'],\n",1)
    if 'missing_future_feature' not in text:
        insertion="\nfuture=(root/'docs/FUTURE-40.md').read_text(encoding='utf-8') if (root/'docs/FUTURE-40.md').is_file() else ''\nfor i in range(1,41):\n fid=f'CF05-FUT-{i:03d}'\n if fid not in future: errors.append(f'missing_future_feature:{fid}')\n"
        text=text.replace("reviews=(root/'docs/REVIEW-ROUNDS-07-46.md').read_text(encoding='utf-8') if (root/'docs/REVIEW-ROUNDS-07-46.md').is_file() else ''\n",insertion+"\nreviews=(root/'docs/REVIEW-ROUNDS-07-46.md').read_text(encoding='utf-8') if (root/'docs/REVIEW-ROUNDS-07-46.md').is_file() else ''\n",1)
    text=text.replace("if len(tables) < 37: errors.append(f'table_count_too_small:{len(tables)}')","if len(tables) < 45: errors.append(f'table_count_too_small:{len(tables)}')")
    text=text.replace("print(f'Architecture check passed: 35 requirements, 40 review rounds, {len(tables)} governed tables.')","print(f'Architecture check passed: 35 requirements, 40 review rounds, 40 Future-40 features, {len(tables)} governed tables.')")
    write(path,text)
    path='scripts/cross-plan-check.py';text=read(path)
    if "'src/Http/FutureRestController.php'" not in text:text=text.replace("    'src/Http/GovernanceRestController.php',\n","    'src/Http/GovernanceRestController.php',\n    'src/Http/FutureRestController.php',\n    'docs/FUTURE-40.md',\n",1)
    if "'CF05-FUT-040'" not in text:text=text.replace("for marker in ['SSH-PMP-2026-v3.0', 'Consolidated All-Chats Recovered Directive Register 2.1', 'CF05-FR-001', 'CF05-FR-035', 'Islamic privacy', 'Hostinger staging']:","for marker in ['SSH-PMP-2026-v3.0', 'Consolidated All-Chats Recovered Directive Register 2.1', 'CF05-FR-001', 'CF05-FR-035', 'Islamic privacy', 'Hostinger staging', 'CF05-FUT-040']:")
    write(path,text)

def main()->None:
    fix_engine();patch_database();patch_schema();patch_activator();patch_plugin();patch_release();patch_qa();print('CF-05 Future-40 source expansion applied: rc.6 / schema 1.4.0 / contract 1.4.0.')
if __name__=='__main__':main()
