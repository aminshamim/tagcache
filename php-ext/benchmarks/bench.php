#!/usr/bin/env php
<?php
if (!extension_loaded('tagcache')) { fwrite(STDERR, "tagcache extension not loaded\n"); exit(1);} 
$options = getopt('', ['host::','port::','mode::','ops::','duration::']);
$host = $options['host'] ?? '127.0.0.1';
$port = (int)($options['port'] ?? 1984);
$ops  = (int)($options['ops'] ?? 0); // if 0 use duration
$duration = (int)($options['duration'] ?? 5);
$h = tagcache_create(['host'=>$host,'port'=>$port]);
$keyCount = 1000;
$values = [];
for($i=0;$i<$keyCount;$i++){ $values[$i] = $i; tagcache_put($h, "b:key:$i", $i); }
$start = microtime(true); $n=0; $deadline = $start + $duration;
while(true){
    $k = "b:key:".($n % $keyCount);
    $v = tagcache_get($h, $k);
    $n++;
    if ($ops>0 && $n >= $ops) break;
    if ($ops==0 && microtime(true) >= $deadline) break;
}
$elapsed = microtime(true)-$start;
$throughput = $n / max(1e-6,$elapsed);
printf("Ops: %d\nElapsed: %.4f s\nThroughput: %.2f ops/sec\n", $n, $elapsed, $throughput);
