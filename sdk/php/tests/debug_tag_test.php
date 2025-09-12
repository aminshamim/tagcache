<?php declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use TagCache\Client;
use TagCache\Config;

echo "=== Debug Tag Test ===\n";

$config = new Config([
    'mode' => 'http',
    'http' => [
        'base_url' => 'http://localhost:8080',
        'timeout_ms' => 10000,
    ]
]);

$client = new Client($config);

// Login
echo "Logging in...\n";
if (!$client->login('umF6zQOspeAWvyZF', 'hmH4KJP1PT9oQIGBpkpLdrgu')) {
    echo "❌ Login failed\n";
    exit(1);
}
echo "✅ Login successful\n";

// Create a unique tag
$tag = 'debug-tag-' . uniqid();
echo "Using tag: $tag\n";

// Create tagged keys
echo "Creating tagged keys...\n";
$keys = [];
for ($i = 1; $i <= 5; $i++) {
    $key = "debug-test:$i:" . uniqid();
    $keys[] = $key;
    echo "Creating key: $key\n";
    
    if (!$client->put($key, "value-$i", [$tag, 'debug-ops'], 300000)) {
        echo "❌ Failed to put key $key\n";
        exit(1);
    }
    echo "✅ Created key $key\n";
}

echo "\nWaiting 1 second...\n";
sleep(1);

// Search for keys by tag
echo "Searching for keys with tag: $tag\n";
$foundKeys = $client->keysByTag($tag);
echo "Found " . count($foundKeys) . " keys: " . implode(', ', $foundKeys) . "\n";

if (count($foundKeys) < 5) {
    echo "❌ Not all keys found by tag (expected 5, got " . count($foundKeys) . ")\n";
    
    // Debug: check if keys exist individually
    echo "\nChecking individual keys:\n";
    foreach ($keys as $key) {
        $value = $client->get($key);
        if ($value) {
            echo "✅ Key $key exists with value: $value\n";
        } else {
            echo "❌ Key $key not found\n";
        }
    }
    
    // Debug: try search with different parameters
    echo "\nTrying raw search:\n";
    $searchResult = $client->search(['tag_any' => [$tag]]);
    echo "Raw search result: " . json_encode($searchResult) . "\n";
    
} else {
    echo "✅ All keys found by tag\n";
}

// Cleanup
echo "\nCleaning up...\n";
foreach ($keys as $key) {
    $client->delete($key);
}
echo "✅ Cleanup complete\n";
