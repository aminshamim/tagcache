<?php
// Simulate from sdk/php/src directory
$configDir = '/Users/arogga/Documents/GitHub/tagcache/sdk/php/src';
echo "Config dir: $configDir\n";
echo "Path calculation: " . $configDir . '/../../../credential.txt' . "\n";
echo "Realpath: " . realpath($configDir . '/../../../credential.txt') . "\n";
echo "File exists: " . (file_exists($configDir . '/../../../credential.txt') ? 'yes' : 'no') . "\n";
