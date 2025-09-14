--TEST--
Basic set/get round trip
--SKIPIF--
<?php
if (!extension_loaded('tagcache')) echo 'skip';
$fp = @fsockopen('127.0.0.1', 1984, $e, $s, 0.2);
if (!$fp) { echo 'skip'; }
?>
--FILE--
<?php
$h = tagcache_create();
$key = 'phpt_basic_'.uniqid();
var_dump(tagcache_put($h, $key, 'hello'));
var_dump(tagcache_get($h, $key));
?>
--EXPECTF--
bool(true)
string(5) "hello"
