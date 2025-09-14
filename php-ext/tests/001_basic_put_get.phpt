--TEST--
TagCache extension basic put/get scalar and array
--SKIPIF--
<?php if (!extension_loaded('tagcache')) echo 'skip'; ?>
--FILE--
<?php
$c = TagCache::create(['host'=>'127.0.0.1','port'=>1984]);
var_dump($c->set('ext:test:1', 'hello'));
var_dump($c->get('ext:test:1'));
var_dump($c->set('ext:test:2', ['a'=>1,'b'=>true]));
var_dump($c->get('ext:test:2'));
?>
--EXPECTF--
bool(true)
string(5) "hello"
bool(true)
array(2) {
  ["a"]=>
  int(1)
  ["b"]=>
  bool(true)
}
