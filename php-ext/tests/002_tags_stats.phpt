--TEST--
TagCache extension tags and stats
--SKIPIF--
<?php if (!extension_loaded('tagcache')) echo 'skip'; ?>
--FILE--
<?php
$c = TagCache::create(['host'=>'127.0.0.1','port'=>1984]);
@$c->flush();
for($i=0;$i<3;$i++) { $c->set("ext:tag:$i", $i, ['group']); }
$keys = $c->keysByTag('group'); sort($keys); var_dump($keys);
$stat = $c->stats(); var_dump(isset($stat['hits']), isset($stat['puts']));
?>
--EXPECTF--
array(3) {
  [0]=>
  string(9) "ext:tag:0"
  [1]=>
  string(9) "ext:tag:1"
  [2]=>
  string(9) "ext:tag:2"
}
bool(true)
bool(true)
