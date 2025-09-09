<?php
echo "Current working directory: " . getcwd() . "\n";
echo "__DIR__: " . __DIR__ . "\n";
echo "Path 1: " . realpath(__DIR__ . '/../../../../credential.txt') . "\n";
echo "Path 2: " . realpath(__DIR__ . '/../../../credential.txt') . "\n";  
echo "Path 3: " . realpath(getcwd() . '/credential.txt') . "\n";

echo "\nActual credential file location:\n";
echo "/Users/arogga/Documents/GitHub/tagcache/credential.txt exists: " . (file_exists('/Users/arogga/Documents/GitHub/tagcache/credential.txt') ? 'yes' : 'no') . "\n";
