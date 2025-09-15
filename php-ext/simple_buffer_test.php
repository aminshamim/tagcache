<?php
echo "=== Simple Buffer Test ===\n";

$tc = new TagCache(null, ['host' => '127.0.0.1', 'port' => 8007, 'pool_size' => 1]);

// Test with progressively larger data
for ($size = 1024; $size <= 1024*1024; $size *= 2) {
    $data = str_repeat('x', $size);
    echo "Testing " . number_format($size/1024, 1) . "KB... ";
    
    $result = $tc->set("test_$size", $data, ['tag1', 'tag2']);
    echo $result ? "OK" : "FAIL";
    echo "\n";
    
    // Clear to save memory
    $tc->delete("test_$size");
}

echo "Closing connection...\n";
$tc->close();
echo "Test completed successfully!\n";
?>