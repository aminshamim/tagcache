<?php

echo "=== Precise Segfault Debugging ===\n\n";

$client = \TagCache::create([
    'mode' => 'tcp',
    'host' => '127.0.0.1',
    'port' => 1984,
    'pool_size' => 36,
    'timeout_ms' => 10000,
]);

echo "Testing the exact pattern from reproduce_timeout.php...\n";

// Test the exact problematic loop
$local_cache = [];

for ($i = 0; $i < 200; $i++) {
    echo "Iteration $i... ";
    
    $key = "user:session:" . ($i % 50);
    $value = json_encode([
        'user_id' => $i % 50,
        'session_data' => str_repeat('x', 500),
        'timestamp' => time(),
    ]);
    $tags = ['session', 'user:' . ($i % 50)];
    
    // 70% reads, 30% writes
    if ($i % 10 < 7) {
        // Read operation
        if (array_key_exists($key, $local_cache)) {
            echo "local hit\n";
        } else {
            $value_read = $client->get($key);
            if ($value_read !== null) {
                $local_cache[$key] = $value_read;
                echo "remote hit\n";
            } else {
                echo "miss\n";
            }
        }
    } else {
        // Write operation
        $result = $client->set($key, $value, $tags, 3600 * 1000);
        if ($result) {
            $local_cache[$key] = $value;
            echo "set ok\n";
        } else {
            echo "set failed\n";
        }
    }
    
    // THIS is the problematic part - bulk operations every 25 iterations
    if ($i % 25 == 0) {
        echo "  Doing bulk operation... ";
        $bulk_keys = [];
        for ($j = 0; $j < 5; $j++) {
            $bulk_keys[] = "bulk:" . (($i + $j) % 20);
        }
        
        // This might be where the segfault happens
        $bulk_results = $client->mGet($bulk_keys);
        echo "bulk done (" . count($bulk_results) . " results)\n";
    }
    
    // Tag invalidation every 50 iterations
    if ($i % 50 == 0 && $i > 0) {
        echo "  Invalidating tags... ";
        $count = $client->invalidateTagsAny(['session']);
        echo "invalidated $count items\n";
        $local_cache = [];
    }
}

echo "✅ Loop completed without segfault!\n";

?>