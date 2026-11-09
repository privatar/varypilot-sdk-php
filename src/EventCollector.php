<?php
namespace ABPlatform;
final class EventCollector {
 private $options; private $events=array(); private $consent;
 public function __construct($options) { $this->options=$options;$this->consent=!empty($options['consent']); }
 public function setConsent($value) { $this->consent=(bool)$value;if(!$this->consent){$this->events=array();} }
 public function add($event) { if(!$this->consent || count($this->events)>=1000){return false;} $event['visitorId']=$this->options['visitorId'];$event['eventId']=isset($event['eventId'])?$event['eventId']:bin2hex(random_bytes(16));$event['occurredAt']=gmdate('Y-m-d\TH:i:s\Z');$this->events[]=$event; return true; }
 public function flush() {
  if(!$this->consent){return true;}if(!function_exists('curl_init')){return false;}
  while(count($this->events)) {
   $batch=array();foreach(array_slice($this->events,0,100)as$event){$candidate=$batch;$candidate[]=$event;if(strlen(json_encode($candidate))>55000){break;}$batch=$candidate;}
   if(!$batch){return false;} $body=json_encode(array('projectKey'=>$this->options['projectKey'],'environment'=>$this->options['environment'],'events'=>$batch));$ok=false;
   for($attempt=0;$attempt<=min(3,$this->options['retryLimit']);$attempt++){
    $headers=array('Content-Type: application/json');if(!empty($this->options['serverKey'])){$headers[]='Authorization: Bearer '.$this->options['serverKey'];}
    $curl=curl_init(rtrim($this->options['endpoint'],'/').'/v1/events');curl_setopt_array($curl,array(CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT_MS=>200,CURLOPT_TIMEOUT_MS=>$this->options['timeoutMs'],CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>$body));curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    if($status===202){$ok=true;break;}if($status>=400&&$status<500&&$status!==429){break;}if($attempt<$this->options['retryLimit']){usleep(100000*(1<<$attempt));}
   }
   if(!$ok){return false;} $this->events=array_slice($this->events,count($batch));
  } return true;
 }
}
