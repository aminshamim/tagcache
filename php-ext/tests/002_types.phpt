--TEST--
Scalar type round trips and null/bool markers
--SKIPIF--
<?php
if (!extension_loaded('tagcache')) echo 'skip';
$fp=@fsockopen('127.0.0.1',1984,$e,$s,0.2); if(!$fp) echo 'skip';
?>
--FILE--
<?php
$h = tagcache_create();
$base = 'phpt_types_'.uniqid();
$cases = [
  'str' => 'value',
  'int' => 123456,
  'float' => 3.14159,
  'null' => null,
  'true' => true,
  'false' => false,
];
foreach($cases as $suffix=>$val){
  $k = $base.'_'.$suffix;
  tagcache_put($h,$k,$val);
  $out = tagcache_get($h,$k);
  var_dump($suffix,$val,$out,$val===$out);
}
?>
--EXPECTF--
string(3) "str"
string(5) "value"
string(5) "value"
bool(true)
string(3) "int"
int(123456)
int(123456)
bool(true)
string(5) "float"
float(3.14159)%r\n(float\(3\.14159\)|float\(3\.1416\))%r
bool(true)
string(4) "null"
NULL
NULL
bool(true)
string(4) "true"
bool(true)
bool(true)
bool(true)
string(5) "false"
bool(false)
bool(false)
bool(true)
