<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Contracts;

final class MetricDefinitionValidator
{
    /** @param array<string,mixed> $definition @return array<int,string> */
    public function errors(array $definition): array
    {
        $errors=[];
        foreach(['metric_id','metric_version','name','owner_module','business_question','numerator','denominator','grain','window','timezone','dimensions','privacy_class','minimum_cohort','source','calculation'] as $key){if(!array_key_exists($key,$definition)){$errors[]='missing_'.$key;}}
        if(isset($definition['metric_id'])&&preg_match('/^[a-z][a-z0-9_.-]{2,189}$/',(string)$definition['metric_id'])!==1){$errors[]='invalid_metric_id';}
        if(isset($definition['metric_version'])&&preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/',(string)$definition['metric_version'])!==1){$errors[]='invalid_metric_version';}
        if(preg_match('/^[a-z0-9][a-z0-9_.-]{1,99}$/',(string)($definition['owner_module']??''))!==1){$errors[]='invalid_owner_module';}
        if(strlen(trim((string)($definition['name']??'')))<3||strlen((string)($definition['name']??''))>190){$errors[]='invalid_name';}
        if(strlen(trim((string)($definition['business_question']??'')))<8||strlen((string)($definition['business_question']??''))>2000){$errors[]='invalid_business_question';}
        if(!in_array((string)($definition['privacy_class']??''),['C1','C2','C3'],true)){$errors[]='invalid_privacy_class';}
        if((int)($definition['minimum_cohort']??0)<5||(int)($definition['minimum_cohort']??0)>1000000){$errors[]='minimum_cohort_too_small';}
        try{new \DateTimeZone((string)($definition['timezone']??''));}catch(\Throwable){$errors[]='invalid_timezone';}
        if(!is_array($definition['dimensions']??null)||count($definition['dimensions'])>20||count(array_unique(array_map('strval',(array)($definition['dimensions']??[]))))!==count((array)($definition['dimensions']??[]))){$errors[]='invalid_dimensions';}
        else{foreach($definition['dimensions'] as $dimension){if(!is_string($dimension)||preg_match('/^[a-z][a-z0-9_]{0,63}$/',$dimension)!==1){$errors[]='invalid_dimension';}}}
        $source=$definition['source']??null;
        if(!is_array($source)||preg_match('/^[a-z][a-z0-9_.-]{2,189}$/',(string)($source['dataset_id']??''))!==1||preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/',(string)($source['dataset_version']??''))!==1){$errors[]='invalid_source';}
        $calculation=$definition['calculation']??null;$type=is_array($calculation)?(string)($calculation['type']??''):'';
        if(!is_array($calculation)||!in_array($type,['count','sum','average','ratio'],true)){$errors[]='invalid_calculation';}
        elseif(in_array($type,['sum','average'],true)&&preg_match('/^[a-z][a-z0-9_]{0,63}$/',(string)($calculation['field']??''))!==1){$errors[]='invalid_calculation_field';}
        elseif($type==='ratio'){
            foreach(['numerator_filters','denominator_filters'] as $key){if(isset($calculation[$key])&&!is_array($calculation[$key])){$errors[]='invalid_'.$key;}elseif(is_array($calculation[$key]??null)){$errors=array_merge($errors,$this->filterErrors($calculation[$key],$key));}}
        }elseif(is_array($calculation['filters']??null)){$errors=array_merge($errors,$this->filterErrors($calculation['filters'],'filters'));}
        if(isset($definition['cohort_field'])&&preg_match('/^[a-z][a-z0-9_]{0,63}$/',(string)$definition['cohort_field'])!==1){$errors[]='invalid_cohort_field';}
        if(isset($definition['historical_semantics'])&&!in_array((string)$definition['historical_semantics'],['current','as_occurred'],true)){$errors[]='invalid_historical_semantics';}
        if(isset($definition['freshness_seconds'])&&((int)$definition['freshness_seconds']<60||(int)$definition['freshness_seconds']>366*86400)){$errors[]='invalid_freshness_seconds';}
        return array_values(array_unique($errors));
    }

    /** @param array<string,mixed> $definition */
    public function normalize(array $definition): array
    {
        if(isset($definition['dimensions'])&&is_array($definition['dimensions'])){$definition['dimensions']=array_values(array_unique(array_map('strval',$definition['dimensions'])));sort($definition['dimensions']);}
        $definition['historical_semantics']=(string)($definition['historical_semantics']??'current');
        $definition['freshness_seconds']=max(60,min(366*86400,(int)($definition['freshness_seconds']??86400)));
        if(is_array($definition['calculation']??null)){foreach(['filters','numerator_filters','denominator_filters'] as $key){if(is_array($definition['calculation'][$key]??null)){$definition['calculation'][$key]=array_values($definition['calculation'][$key]);}}}
        ksort($definition);return $definition;
    }

    /** @param array<int,mixed> $filters @return array<int,string> */
    private function filterErrors(array $filters,string $context):array
    {
        $errors=[];if(count($filters)>50){return['too_many_'.$context];}
        foreach($filters as $filter){if(!is_array($filter)||preg_match('/^[a-z][a-z0-9_]{0,63}$/',(string)($filter['field']??''))!==1||!in_array((string)($filter['operator']??''),['eq','neq','in','not_in','gt','gte','lt','lte','exists'],true)){$errors[]='invalid_'.$context.'_filter';}}
        return $errors;
    }
}
