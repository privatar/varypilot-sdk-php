<?php
namespace ABPlatform;
final class Client {
 private $previewSession=null; private $options; private $repository; private $collector; private $evaluator; private $exposures=array(); private $admittedUntil=0; private $initialized=false;
 public function __construct($options) {
  foreach(array('projectKey','environment','visitorId','endpoint','cacheDir')as$key){if(empty($options[$key])||!is_string($options[$key])){throw new \InvalidArgumentException('Missing SDK option '.$key);}}
  $url=parse_url($options['endpoint']);if(!$url || !isset($url['scheme'],$url['host']) || ($url['scheme']!=='https' && !($url['scheme']==='http' && in_array($url['host'],array('localhost','127.0.0.1'),true)))){throw new \InvalidArgumentException('SDK endpoints require HTTPS');}
  $this->options=array_merge(array('timeoutMs'=>800,'configTtl'=>60,'retryLimit'=>1,'consent'=>false,'autoRefresh'=>true,'preview'=>false,'overrides'=>array()),$options);$this->resolvePreview();if($this->options['preview']===true){$this->options['consent']=false;}try{$this->repository=new ConfigRepository($this->options);}catch(\Throwable $error){$this->repository=null;}$this->collector=new EventCollector($this->options);$this->evaluator=new Evaluator();if($this->repository && $this->options['autoRefresh']){$this->repository->refresh();}
 }
 private function authorizeAdmission() {
  if($this->options['preview']===true){return;}
  $access=$this->repository?$this->repository->access():array();
  if(empty($access['required'])){return;}
  if($this->admittedUntil>time()){return;}$this->admittedUntil=0;if(!function_exists('curl_init')){return;}
  $curl=curl_init(rtrim($this->options['endpoint'],'/').'/v1/admission');$headers=array('Content-Type: application/json');if(!empty($this->options['serverKey'])){$headers[]='Authorization: Bearer '.$this->options['serverKey'];}
  curl_setopt_array($curl,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode(array('projectKey'=>$this->options['projectKey'],'environment'=>$this->options['environment'],'visitorId'=>$this->options['visitorId'])),CURLOPT_CONNECTTIMEOUT_MS=>200,CURLOPT_TIMEOUT_MS=>$this->options['timeoutMs']));
  $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);$data=is_string($body)?json_decode($body,true):null;if($status===200&&is_array($data)&&!empty($data['allowed'])&&isset($data['expiresAt'])){$this->admittedUntil=(int)floor($data['expiresAt']/1000);}
 }
 private function accessAllowed() { $a=$this->repository?$this->repository->access():array();return $this->initialized&& ($this->options['preview']===true || ((empty($a['until'])||$a['until']>time())&&(empty($a['required'])||$this->admittedUntil>time()))); }
 public function init() { $ok=$this->repository?$this->repository->refresh():false;$this->authorizeAdmission();$this->initialized=true;return $ok; }
 public function refresh() { $ok=$this->repository?$this->repository->refresh(true):false;$this->authorizeAdmission();return $ok; }
 public function getVariant($key,$fallback='A') { if(!$this->accessAllowed()){return $fallback;} $config=$this->getConfig();if(!$config){return $fallback;}foreach($config['experiments']as$experiment){if($experiment['key']===$key){$overrides=$this->getOverrides();if(isset($overrides[$key])){return $overrides[$key];}if(!Experience::eligible($experiment,$this->options)){return $fallback;}$variant=$this->evaluator->getVariant($experiment,$this->options['visitorId']);return $variant===null?$fallback:$variant;}}return $fallback; }
 /** Validated preview overrides. Applications must authorize preview before enabling it. */
 public function getOverrides() {
  $valid=array();
  if($this->options['preview']!==true || !is_array($this->options['overrides'])){return $valid;}
  $config=$this->getConfig();
  foreach($config['experiments']??array() as $experiment){
   $override=$this->options['overrides'][$experiment['key']]??null;
   foreach($experiment['variants'] as $variant){if($variant['key']===$override){$valid[$experiment['key']]=$override;}}
  }
  return $valid;
 }
 public function setAttributes($attributes) { if(is_array($attributes)){$this->options['attributes']=$attributes;} }
 public function getPayload($key) { $variant=$this->getVariant($key,'');foreach(($this->getConfig()['experiments']??array())as$e){if($e['key']===$key){foreach($e['variants']as$v){if($v['key']===$variant){return $v['payload']??array();}}}}return null; }
 public function getConfig() { return $this->previewSession ? $this->previewSession['config'] : ($this->repository ? $this->repository->get() : null); }
 public function getPreview() { return $this->previewSession; }
 private function resolvePreview() {
  $token=$this->options['previewToken']??'';if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token)||!function_exists('curl_init')){return;}
  $url=parse_url($this->options['url']??'');if(!$url||empty($url['scheme'])||empty($url['host'])){return;}
  $origin=$url['scheme'].'://'.$url['host'].(isset($url['port'])?':'.$url['port']:'');
  $curl=curl_init(rtrim($this->options['endpoint'],'/').'/v1/preview');
  curl_setopt_array($curl,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>array('Content-Type: application/json'),CURLOPT_POSTFIELDS=>json_encode(array('token'=>$token,'projectKey'=>$this->options['projectKey'],'environment'=>$this->options['environment'],'origin'=>$origin)),CURLOPT_CONNECTTIMEOUT_MS=>500,CURLOPT_TIMEOUT_MS=>3000));
  $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);$data=is_string($body)?json_decode($body,true):null;
  if($status!==200||!is_array($data)||!isset($data['config'],$data['expiresAt'])||strtotime($data['expiresAt'])<=time()||$data['config']['projectKey']!==$this->options['projectKey']||$data['config']['environment']!==$this->options['environment']){return;}
  $this->previewSession=$data;$this->options['preview']=true;$this->options['overrides']=array_merge($data['overrides']??array(),is_array($this->options['overrides'])?$this->options['overrides']:array());
 }
 public function trackExposure($key,$variant) { if($this->options['preview']===true || !$this->accessAllowed()){return;} $config=$this->getConfig();if(!$config){return;}foreach($config['experiments']as$e){$identity=$e['id'].':'.$e['allocationVersion'];if($e['key']===$key && Experience::eligible($e,$this->options) && !isset($this->exposures[$identity]) && $this->evaluator->getVariant($e,$this->options['visitorId'])===$variant){if($this->collector->add(array('type'=>'exposure','experimentId'=>$e['id'],'variant'=>$variant,'allocationVersion'=>$e['allocationVersion']))){$this->exposures[$identity]=true;}}} }
 public function track($name,$properties=array()) { if($this->options['preview']===true || !$this->accessAllowed()){return;} $event=array('type'=>'conversion','name'=>$name);foreach(array('eventId','value','currency')as$key){if(isset($properties[$key])){$event[$key]=$properties[$key];}}$this->collector->add($event); }
 public function setConsent($granted) { $this->collector->setConsent($granted && $this->options['preview']!==true);if(!$granted){$this->exposures=array();} }
 public function flush() { return $this->collector->flush(); }
}
