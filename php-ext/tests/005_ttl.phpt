--TEST--
TTL expiration basic check (short delay)
--SKIPIF--
<?php
if (!extension_loaded('tagcache')) echo 'skip';
$fp=@fsockopen('127.0.0.1',1984,$e,$s,0.2); if(!$fp) echo 'skip';
?>
--FILE--
<?php
$h = tagcache_create();
$key='phpt_ttl_'.uniqid();
tagcache_put($h,$key,'temp',[],1); // 1 ms TTL (server interprets ms)
usleep(5000); // 5 ms
var_dump(tagcache_get($h,$key));
?>
--EXPECTF--
NULL
