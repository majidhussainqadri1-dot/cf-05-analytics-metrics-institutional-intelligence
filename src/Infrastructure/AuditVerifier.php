<?php
declare(strict_types=1);
namespace Sabri\AnalyticsIntelligence\Infrastructure;
final class AuditVerifier
{
    private Database $db;
    public function __construct(Database $db){$this->db=$db;}
    /** @return array<string,mixed> */
    public function verify(int $pageSize=10000):array
    {
        $table=$this->db->table('audit_log');$pageSize=max(100,min(50000,$pageSize));$previous=str_repeat('0',64);$checked=0;$lastId=0;$failures=[];
        while(true){
            $rows=$this->db->wpdb()->get_results($this->db->wpdb()->prepare("SELECT * FROM `{$table}` WHERE id>%d ORDER BY id ASC LIMIT %d",$lastId,$pageSize),ARRAY_A);
            if(!is_array($rows)){$failures[]=['id'=>null,'code'=>'audit_query_failed'];break;} if($rows===[])break;
            foreach($rows as $row){$checked++;$lastId=(int)$row['id'];if(!hash_equals($previous,(string)$row['previous_hash'])){$failures[]=['id'=>$lastId,'code'=>'previous_hash_mismatch'];break 2;}
                $material=implode('|',[$row['event_uuid'],(string)$row['actor_user_id'],$row['actor_type'],$row['action'],$row['object_type'],(string)$row['object_ref'],(string)$row['purpose'],$row['result'],(string)$row['trace_id'],(string)$row['context_json'],$row['previous_hash'],$row['created_at']]);
                $expected=hash('sha256',$material);if(!hash_equals($expected,(string)$row['record_hash'])){$failures[]=['id'=>$lastId,'code'=>'record_hash_mismatch'];break 2;}$previous=(string)$row['record_hash'];
            } if(count($rows)<$pageSize)break;
        }
        $head=$this->db->wpdb()->get_var("SELECT last_hash FROM `{$this->db->table('audit_state')}` WHERE id=1");
        if(!is_string($head)||preg_match('/^[a-f0-9]{64}$/',$head)!==1)$failures[]=['id'=>null,'code'=>'audit_state_missing_or_invalid'];
        elseif($failures===[]&&!hash_equals($previous,$head))$failures[]=['id'=>null,'code'=>'head_hash_mismatch'];
        return ['status'=>$failures===[]?'verified':'failed','checked_records'=>$checked,'last_hash'=>$previous,'failures'=>$failures,'verified_at'=>gmdate('c')];
    }
}
