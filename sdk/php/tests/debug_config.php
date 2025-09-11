<?php
require_once '/Users/arogga/Documents/GitHub/tagcache/sdk/php/vendor/autoload.php';

use TagCache\Config;

echo "Testing config loading...\n";

$config = Config::fromEnv();
echo "Config loaded:\n";
var_dump($config);
echo "- Username: " . ($config->auth['username'] ?? 'null') . "\n";
echo "- Password: " . (isset($config->auth['password']) && $config->auth['password'] !== '' ? '[set]' : 'null/empty') . "\n";

// Check credential file
$credFile = '/Users/arogga/Documents/GitHub/tagcache/credential.txt';
if (file_exists($credFile)) {
    echo "\nCredential file exists at: $credFile\n";
    echo "Contents:\n";
    echo file_get_contents($credFile);
} else {
    echo "\nCredential file not found at: $credFile\n";
}

// Check environment variables
echo "\nEnvironment variables:\n";
echo "- TAGCACHE_USERNAME: " . (getenv('TAGCACHE_USERNAME') ?: 'not set') . "\n";
echo "- TAGCACHE_PASSWORD: " . (getenv('TAGCACHE_PASSWORD') ?: 'not set') . "\n";
