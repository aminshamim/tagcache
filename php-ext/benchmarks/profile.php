#!/usr/bin/env php
<?php
// Comprehensive benchmark to identify bottlenecks
if (!extension_loaded('tagcache')) { 
    fwrite(STDERR, "tagcache extension not loaded\n"); 
    exit(1);
} 

function bench_pattern($name, $callable, $duration = 3) {
    $start = microtime(true);
    $n = 0;
    $deadline = $start + $duration;
    
    while (microtime(true) < $deadline) {
        $callable($n);
        $n++;
    }
    
    $elapsed = microtime(true) - $start;
    $throughput = $n / max(1e-6, $elapsed);
    printf("%-25s: %8d ops in %.3fs = %8.0f ops/sec\n", $name, $n, $elapsed, $throughput);
    return $throughput;
}

echo "TagCache PHP Extension Performance Analysis\n";
echo "==========================================\n";

// Setup
$h = tagcache_create(['host'=>'127.0.0.1','port'=>1984]);

// Populate some test data
for($i=0; $i<100; $i++) {
    tagcache_put($h, "bench:$i", "value$i");
}

echo "\n1. SINGLE OPERATIONS:\n";

// Test different operation types
bench_pattern("GET (existing key)", function($n) use ($h) {
    tagcache_get($h, "bench:" . ($n % 100));
});

bench_pattern("GET (missing key)", function($n) use ($h) {
    tagcache_get($h, "missing:$n");
});

bench_pattern("PUT (small value)", function($n) use ($h) {
    tagcache_put($h, "test:$n", "x");
});

bench_pattern("PUT (medium value)", function($n) use ($h) {
    tagcache_put($h, "test:$n", str_repeat("x", 100));
});

bench_pattern("PUT (large value)", function($n) use ($h) {
    tagcache_put($h, "test:$n", str_repeat("x", 1000));
});

bench_pattern("PUT with tags", function($n) use ($h) {
    tagcache_put($h, "tagged:$n", "x", ["tag1", "tag2"]);
});

echo "\n2. BULK OPERATIONS:\n";

bench_pattern("BULK_GET (10 keys)", function($n) use ($h) {
    $keys = [];
    for($i = 0; $i < 10; $i++) {
        $keys[] = "bench:" . (($n + $i) % 100);
    }
    tagcache_bulk_get($h, $keys);
});

bench_pattern("BULK_PUT (10 items)", function($n) use ($h) {
    $items = [];
    for($i = 0; $i < 10; $i++) {
        $items["bulk:$n:$i"] = "value$i";
    }
    tagcache_bulk_put($h, $items);
});

echo "\n3. MIXED WORKLOAD:\n";

bench_pattern("Mixed GET/PUT (80/20)", function($n) use ($h) {
    if ($n % 5 == 0) {
        tagcache_put($h, "mixed:$n", "value$n");
    } else {
        tagcache_get($h, "bench:" . ($n % 100));
    }
});

echo "\n4. OBJECT-ORIENTED API:\n";

$tc = TagCache::create(['host'=>'127.0.0.1','port'=>1984]);

bench_pattern("OO GET", function($n) use ($tc) {
    $tc->get("bench:" . ($n % 100));
});

bench_pattern("OO PUT", function($n) use ($tc) {
    $tc->set("oo:$n", "x");
});

bench_pattern("OO mGet (10 keys)", function($n) use ($tc) {
    $keys = [];
    for($i = 0; $i < 10; $i++) {
        $keys[] = "bench:" . (($n + $i) % 100);
    }
    $tc->mGet($keys);
});

echo "\n5. CONNECTION OVERHEAD TEST:\n";

// Test connection creation overhead
bench_pattern("New handle per op", function($n) {
    $h_temp = tagcache_create(['host'=>'127.0.0.1','port'=>1984]);
    tagcache_get($h_temp, "bench:0");
    tagcache_close($h_temp);
});

echo "\nAnalysis complete. Key bottlenecks likely in:\n";
echo "- Connection pool efficiency\n";
echo "- Syscall frequency (send/recv per operation)\n";
echo "- Memory allocation (smart_str usage)\n";
echo "- Protocol overhead (command formatting)\n";