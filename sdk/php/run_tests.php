#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * TagCache PHP SDK Test Runner
 * 
 * Runs all test suites with proper environment setup
 */

require_once __DIR__ . '/vendor/autoload.php';

use TagCache\Client;
use TagCache\Config;

// Color output functions
function colorOutput($text, $color) {
    $colors = [
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'magenta' => "\033[35m",
        'cyan' => "\033[36m",
        'white' => "\033[37m",
        'reset' => "\033[0m"
    ];
    
    return $colors[$color] . $text . $colors['reset'];
}

function success($text) { echo colorOutput("✓ $text", 'green') . "\n"; }
function error($text) { echo colorOutput("✗ $text", 'red') . "\n"; }
function info($text) { echo colorOutput("ℹ $text", 'blue') . "\n"; }
function warning($text) { echo colorOutput("⚠ $text", 'yellow') . "\n"; }

// Setup
echo colorOutput("TagCache PHP SDK Test Suite Runner\n", 'magenta');
echo str_repeat("=", 50) . "\n\n";

// Check environment
info("Checking environment...");

$httpUrl = $_ENV['TAGCACHE_HTTP_URL'] ?? 'http://localhost:8080';
$tcpHost = $_ENV['TAGCACHE_TCP_HOST'] ?? 'localhost';
$tcpPort = (int)($_ENV['TAGCACHE_TCP_PORT'] ?? 1984);

echo "HTTP URL: $httpUrl\n";
echo "TCP Host: $tcpHost:$tcpPort\n";

// Check server availability
info("Checking server availability...");

// HTTP check
$httpAvailable = false;
$ch = curl_init($httpUrl . '/health');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 3);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    $httpAvailable = true;
    success("HTTP server available");
} else {
    warning("HTTP server not available (tests will be skipped)");
}

// TCP check
$tcpAvailable = false;
$socket = @fsockopen($tcpHost, $tcpPort, $errno, $errstr, 2);
if ($socket) {
    $tcpAvailable = true;
    fclose($socket);
    success("TCP server available");
} else {
    warning("TCP server not available (TCP tests will be skipped)");
}

echo "\n";

// Test suites to run
$testSuites = [
    'unit' => 'Unit Tests',
    'transport' => 'Transport Tests',
    'feature' => 'Feature Tests',
];

if ($httpAvailable || $tcpAvailable) {
    $testSuites['integration'] = 'Integration Tests';
}

if ($httpAvailable) {
    $testSuites['performance'] = 'Performance Tests';
}

$totalTests = 0;
$failedTests = 0;
$results = [];

// Run test suites
foreach ($testSuites as $suite => $name) {
    info("Running $name...");
    
    $command = "./vendor/bin/phpunit --testsuite=$suite --colors=always";
    
    $startTime = microtime(true);
    ob_start();
    system($command, $exitCode);
    $output = ob_get_clean();
    $duration = microtime(true) - $startTime;
    
    $results[$suite] = [
        'name' => $name,
        'exit_code' => $exitCode,
        'duration' => $duration,
        'output' => $output
    ];
    
    if ($exitCode === 0) {
        success("$name passed (" . number_format($duration, 2) . "s)");
    } else {
        error("$name failed (" . number_format($duration, 2) . "s)");
        $failedTests++;
    }
    
    $totalTests++;
    echo "\n";
}

// Run static analysis
info("Running static analysis (PHPStan)...");

$startTime = microtime(true);
ob_start();
system('./vendor/bin/phpstan analyse src --level=5 --no-progress', $exitCode);
$output = ob_get_clean();
$duration = microtime(true) - $startTime;

$results['phpstan'] = [
    'name' => 'PHPStan Static Analysis',
    'exit_code' => $exitCode,
    'duration' => $duration,
    'output' => $output
];

if ($exitCode === 0) {
    success("Static analysis passed (" . number_format($duration, 2) . "s)");
} else {
    warning("Static analysis found issues (" . number_format($duration, 2) . "s)");
    echo $output . "\n";
}

$totalTests++;
echo "\n";

// Summary
echo colorOutput("Test Summary\n", 'magenta');
echo str_repeat("-", 30) . "\n";

foreach ($results as $suite => $result) {
    $status = $result['exit_code'] === 0 ? 
        colorOutput("PASS", 'green') : 
        colorOutput("FAIL", 'red');
    
    printf("%-25s %s %6.2fs\n", 
        $result['name'], 
        $status, 
        $result['duration']
    );
}

echo "\n";

if ($failedTests === 0) {
    success("All tests passed! 🎉");
    exit(0);
} else {
    error("$failedTests out of $totalTests test suites failed");
    
    // Show failed test details
    foreach ($results as $suite => $result) {
        if ($result['exit_code'] !== 0) {
            echo colorOutput("\n=== {$result['name']} Output ===\n", 'yellow');
            echo $result['output'] . "\n";
        }
    }
    
    exit(1);
}
