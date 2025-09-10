<?php
require_once '/Users/arogga/Documents/GitHub/tagcache/sdk/php/vendor/autoload.php';

use TagCache\Client;
use TagCache\Config;

$config = Config::fromEnv();
$client = new Client($config);

echo "Testing PHP SDK put with tags...\n";

// Test simple case
$key = 'debug-test-simple';
$tag = 'debug-tag-simple';
$value = 'debug-value';

echo "PUT: key='$key', tag='$tag', value='$value'\n";
$result = $client->putWithTag($key, $value, $tag, 30000);
echo "PUT result: " . ($result ? 'true' : 'false') . "\n";

// Check if key exists
echo "GET: key='$key'\n";
$getValue = $client->get($key);
echo "GET result: " . json_encode($getValue) . "\n";

// Check tag lookup
echo "GET_KEYS_BY_TAG: tag='$tag'\n";
$keys = $client->getKeysByTag($tag);
echo "Found keys: " . json_encode($keys) . "\n";

// Test case with colons
$key2 = 'debug:test:colons';
$tag2 = 'debug-tag-colons';

echo "\nTesting with colons...\n";
echo "PUT: key='$key2', tag='$tag2', value='$value'\n";
$result2 = $client->putWithTag($key2, $value, $tag2, 30000);
echo "PUT result: " . ($result2 ? 'true' : 'false') . "\n";

echo "GET: key='$key2'\n";
$getValue2 = $client->get($key2);
echo "GET result: " . json_encode($getValue2) . "\n";

echo "GET_KEYS_BY_TAG: tag='$tag2'\n";
$keys2 = $client->getKeysByTag($tag2);
echo "Found keys: " . json_encode($keys2) . "\n";
