<?php
namespace ABPlatform;
final class FileCache {
 private $path;
 public function __construct($directory, $identity) {
  if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) { throw new \RuntimeException('Cannot create SDK cache'); }
  $this->path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . hash('sha256', $identity) . '.json';
 }
 public function read() { $bytes = @file_get_contents($this->path); if ($bytes === false || strlen($bytes) > 4194304) { return null; } $data = json_decode($bytes, true); return is_array($data) ? $data : null; }
 public function write($data) { $temp = tempnam(dirname($this->path), '.varypilot-'); if ($temp === false) { return false; } @chmod($temp, 0600); $ok = file_put_contents($temp, json_encode($data), LOCK_EX) !== false && @rename($temp, $this->path); if (!$ok) { @unlink($temp); } return $ok; }
 public function lock() { $handle = @fopen($this->path . '.lock', 'c'); if (!$handle) { return false; } @chmod($this->path . '.lock', 0600); if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); return false; } return $handle; }
 public function unlock($handle) { flock($handle, LOCK_UN); fclose($handle); }
}
