--TEST--
TagCache extension bulk and search
--SKIPIF--
<?php if (!extension_loaded('tagcache')) echo 'skip'; ?>
--FILE--
<?php
$c = TagCache::create(['host'=>'127.0.0.1','port'=>1984]);
@$c->flush();
$data = [ 'bulk:1'=>1, 'bulk:2'=>2, 'bulk:3'=>3 ];
$written = $c->mSet($data);
var_dump($written >= 3);
$got = $c->mGet(array_keys($data)); ksort($got); var_dump(array_keys($got));
foreach($data as $k=>$v){ $c->set($k.':t', $v, ['tA','tB']); }
$any = $c->searchAny(['tA','tB']); sort($any); var_dump(count($any) >= 3);
$all = $c->searchAll(['tA','tB']); sort($all); var_dump(count($all) >= 3);
?>
--EXPECTF--
bool(true)
array(3) {
  [0]=>
  string(6) "bulk:1"
  [1]=>
  string(6) "bulk:2"
  [2]=>
  string(6) "bulk:3"
}
bool(true)
bool(true)
