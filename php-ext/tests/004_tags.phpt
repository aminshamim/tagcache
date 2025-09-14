--TEST--
Tag association and search (any/all)
--SKIPIF--
<?php
if (!extension_loaded('tagcache')) echo 'skip';
$fp=@fsockopen('127.0.0.1',1984,$e,$s,0.2); if(!$fp) echo 'skip';
?>
--FILE--
<?php
$h = tagcache_create();
$k1='phpt_tag_'.uniqid(); $k2=$k1.'_b'; $k3=$k1.'_c';
tagcache_put($h,$k1,'A',[ 'red','blue']);
tagcache_put($h,$k2,'B',[ 'blue','green']);
tagcache_put($h,$k3,'C',[ 'green','yellow']);
$any = tagcache_search_any($h,['red','yellow']); sort($any);
$all = tagcache_search_all($h,['blue','green']); sort($all);
var_dump(in_array($k1,$any), in_array($k3,$any));
var_dump($all == [$k2]);
?>
--EXPECTF--
bool(true)
bool(true)
bool(true)
