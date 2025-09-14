--TEST--
Bulk put/get operations (pipeline)
--SKIPIF--
<?php
if (!extension_loaded('tagcache')) echo 'skip';
$fp=@fsockopen('127.0.0.1',1984,$e,$s,0.2); if(!$fp) echo 'skip';
?>
--FILE--
<?php
$h = tagcache_create();
$base='phpt_bulk_'.uniqid();
$items=[]; for($i=0;$i<5;$i++){ $items[$base.'_'.$i] = 'v'.$i; }
var_dump(tagcache_bulk_put($h,$items));
$keys=array_keys($items);
$r = tagcache_bulk_get($h,$keys);
ksort($r); ksort($items);
var_dump($r==$items);
?>
--EXPECTF--
int(5)
bool(true)
