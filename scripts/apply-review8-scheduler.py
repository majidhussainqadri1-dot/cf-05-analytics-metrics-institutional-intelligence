#!/usr/bin/env python3
from pathlib import Path

# Round 8 audit completed before correction. Frozen defects:
# R8-01 scheduled Future evidence was not idempotent across cron retries/overlap.
# R8-02 scheduled execution did not re-check persisted independent approval integrity.
# R8-03 scheduled evidence omitted feature row-version/config-hash provenance.
# R8-04 QA had no invariant preventing regression of the above controls.

p=Path('src/Domain/FutureFeatureService.php'); s=p.read_text(encoding='utf-8')
start=s.find('    public function scheduledTick():void\n')
end=s.find('    private function begin():bool\n', start)
if start < 0 or end < 0: raise SystemExit('scheduledTick block not found')
new=r'''    public function scheduledTick():void
    {
        if(!FutureActivationService::isApproved()||!RuntimeGate::queryEnabled())return;
        $wpdb=$this->db->wpdb();
        $rows=$wpdb->get_results("SELECT feature_id,approved_by,requested_by,row_version,config_hash FROM `{$this->db->table('future_features')}` WHERE state='active' AND feature_id IN ('CF05-FUT-036','CF05-FUT-037')",ARRAY_A);
        $bucket=gmdate('Y-m-d\\TH:00:00\\Z');
        foreach(is_array($rows)?$rows:[] as $candidate){
            if(!is_array($candidate))continue;
            $featureId=(string)($candidate['feature_id']??'');
            if((int)($candidate['approved_by']??0)<1||(int)$candidate['approved_by']===(int)($candidate['requested_by']??0))continue;
            if(!$this->begin())continue;
            try {
                $row=$wpdb->get_row($wpdb->prepare('SELECT state,approved_by,requested_by,row_version,config_hash FROM `'.$this->db->table('future_features').'` WHERE feature_id=%s FOR UPDATE',$featureId),ARRAY_A);
                if(!is_array($row)||(string)$row['state']!=='active'||(int)($row['approved_by']??0)<1||(int)$row['approved_by']===(int)($row['requested_by']??0)){ $this->rollback(); continue; }
                $configHash=(string)($row['config_hash']??'');$rowVersion=(int)($row['row_version']??0);
                if($rowVersion<1||preg_match('/^[a-f0-9]{64}$/',$configHash)!==1){ $this->rollback(); continue; }
                $uuid=$this->scheduledEvidenceUuid($featureId,$bucket,$rowVersion,$configHash);
                $evidence=['automatic_external_delivery'=>false,'scheduled_bucket'=>$bucket,'feature_row_version'=>$rowVersion,'config_hash'=>$configHash];
                $inserted=$wpdb->query($wpdb->prepare(
                    'INSERT IGNORE INTO `'.$this->db->table('intelligence_alerts').'` (alert_uuid,feature_id,severity,state,title,evidence_json,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s)',
                    $uuid,$featureId,'info','evidence_ready','Scheduled Future-40 evaluation window',Json::canonical($evidence),$this->db->now(),$this->db->now()
                ));
                if($inserted===false){ $this->rollback(); continue; }
                if($inserted===0){ $this->rollback(); continue; }
                if(!(new AuditLogger($this->db))->logInOpenTransaction('future_scheduled_evidence_created','intelligence_alert',$uuid,'success',$evidence+['feature_id'=>$featureId],'future40_operations',null,null,'system')){ $this->rollback(); continue; }
                if(!$this->commit())continue;
            } catch (\Throwable $error) {
                $this->rollback();
            }
        }
    }

    private function scheduledEvidenceUuid(string $featureId,string $bucket,int $rowVersion,string $configHash):string
    {
        $hex=substr(hash('sha256',$featureId.'|'.$bucket.'|'.$rowVersion.'|'.$configHash),0,32);
        $hex[12]='5';
        $hex[16]=dechex((hexdec($hex[16])&0x3)|0x8);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20,12);
    }

'''
s=s[:start]+new+s[end:];p.write_text(s,encoding='utf-8')

p=Path('scripts/future40-check.py');s=p.read_text(encoding='utf-8')
anchor='# Review-5 API/governance invariants.\n'
if anchor not in s: raise SystemExit('review check anchor missing')
insert="""# Review-8 scheduler/idempotency invariants.\nfor token in ['scheduledEvidenceUuid','scheduled_bucket','INSERT IGNORE','feature_row_version','config_hash']:\n    if token not in service: errors.append(f'review8_scheduler_guard_missing:{token}')\nif \"SELECT state,approved_by,requested_by,row_version,config_hash\" not in service:\n    errors.append('review8_scheduler_approval_recheck_missing')\n"""
s=s.replace(anchor,insert+anchor,1);p.write_text(s,encoding='utf-8')

Path('docs/REVIEW-ROUND-8.md').write_text("""# CF-05 Review Round 8 — Scheduler Idempotency and Provenance\n\nAudit completed in full before corrections; the defect ledger was frozen first.\n\n## Defects found\n1. Hourly Future-40 scheduled evidence could duplicate during cron retry or overlap.\n2. Scheduled execution trusted `state=active` without re-checking persisted independent-approval integrity.\n3. Scheduled evidence did not pin the feature row version and approved configuration hash.\n4. Automated QA did not guard scheduler idempotency/provenance.\n\n## Corrections\n- Added deterministic UUID identity per feature/hour/config-version and `INSERT IGNORE` replay safety.\n- Re-checks active state, independent approver/requester separation, row version and config hash under row lock.\n- Scheduled audit/evidence now records time bucket, row version and config hash.\n- Added static QA invariants.\n\nNo external delivery or autonomous decision was introduced. Repository/source evidence only; staging/live remain separate.\n""",encoding='utf-8')
print('Review-8 scheduler idempotency corrections applied.')
