<?php
namespace ABPlatform;
final class ConfigRepository {
 private $options; private $cache; private $config; private $etag = ''; private $fetched = 0; private $attempted = 0; private $access=array('required'=>false,'until'=>null);
 public function __construct($options) { $this->options = $options; $this->cache = new FileCache($options['cacheDir'], $options['endpoint'].'|'.$options['projectKey'].'|'.$options['environment']); $cached = $this->cache->read(); $this->attempted=is_array($cached)&&isset($cached['attempted'])?(int)$cached['attempted']:0; if ($cached && isset($cached['config']) && $this->valid($cached['config'])) { $this->config=$cached['config']; $this->etag=isset($cached['etag'])?$cached['etag']:''; $this->fetched=isset($cached['fetched'])?$cached['fetched']:0;if(isset($cached['access'])){$this->access=$cached['access'];} } }
 public function access() { return $this->access; }
 public function get() { return $this->config; }
 private function valid($config) { if (!is_array($config) || !isset($config['schemaVersion'],$config['projectKey'],$config['environment'],$config['version'],$config['experiments']) || !in_array($config['schemaVersion'],array(1,2),true) || $config['projectKey'] !== $this->options['projectKey'] || $config['environment'] !== $this->options['environment'] || !is_int($config['version']) || $config['version'] < 1 || !is_array($config['experiments'])) { return false; } $version=1; foreach($config['experiments'] as $e){if(array_key_exists('experience',$e)){$version=2;}}if($config['schemaVersion']!==$version){return false;}$evaluator=new Evaluator(); foreach ($config['experiments'] as $experiment) { if (!$evaluator->validExperiment($experiment)) { return false; } } return true; }
 public function refresh($force=false) {
  if (!$force && time()-$this->fetched < $this->options['configTtl']) { return true; }
  if(!function_exists('curl_init') || (!$force && time()-$this->attempted < $this->options['configTtl'])) {return false;}
  $lock=$this->cache->lock(); if (!$lock) { return false; }
  try {
   $latest=$this->cache->read(); if(!$force && is_array($latest) && isset($latest['attempted']) && time()-(int)$latest['attempted']<$this->options['configTtl']){if(isset($latest['config'])&&$this->valid($latest['config'])){$this->config=$latest['config'];if(isset($latest['access'])){$this->access=$latest['access'];}}return $this->config!==null;}
   $this->attempted=time();
   $curl=curl_init(rtrim($this->options['endpoint'],'/').'/v1/config/'.rawurlencode($this->options['projectKey']).'/'.rawurlencode($this->options['environment'])); $headers=array();
   curl_setopt_array($curl,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT_MS=>200,CURLOPT_TIMEOUT_MS=>$this->options['timeoutMs'],CURLOPT_HTTPHEADER=>$this->etag?array('If-None-Match: '.$this->etag):array(),CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$headers){ $parts=explode(':',$line,2);if(count($parts)===2){$headers[strtolower(trim($parts[0]))]=trim($parts[1]);}return strlen($line); }));
   $bytes=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
   if(isset($headers['x-varypilot-admission-required'])){$this->access['required']=$headers['x-varypilot-admission-required']==='true';$this->access['until']=!empty($headers['x-varypilot-access-until'])?strtotime($headers['x-varypilot-access-until']):null;}
   if ($status===304 && $this->config) { $this->fetched=time(); }
   elseif ($status===200 && is_string($bytes) && strlen($bytes)<=4194304 && $this->valid($candidate=json_decode($bytes,true))) { $this->config=$candidate;$this->etag=isset($headers['etag'])?$headers['etag']:'';$this->fetched=time(); }
   else { $this->cache->write(array('config'=>$this->config,'etag'=>$this->etag,'fetched'=>$this->fetched,'attempted'=>$this->attempted,'access'=>$this->access));return false; }
   $this->cache->write(array('config'=>$this->config,'etag'=>$this->etag,'fetched'=>$this->fetched,'attempted'=>$this->attempted,'access'=>$this->access));return true;
  } catch (\Throwable $error) { return false; } finally { $this->cache->unlock($lock); }
 }
}
