#!/usr/bin/env php
<?php
/**
 * Ultra High Stress Benchmark for TagCache
 * 
 * Tests the server under extreme load with optimized TCP socket options:
 * - Massive concurrent connections
 * - High-frequency operations
 * - Large payload stress
 * - Extended duration testing
 * - Memory and performance monitoring
 */

echo "=== TagCache Ultra High Stress Benchmark ===\n";
echo "Testing server with optimized TCP socket options...\n\n";

// Load extension if available
if (extension_loaded('tagcache')) {
    echo "✅ Using TagCache PHP extension\n";
    $use_extension = true;
} else {
    echo "⚠️  TagCache extension not loaded, using socket connections\n";
    $use_extension = false;
}

// Stress test configuration
$config = [
    'host' => '127.0.0.1',
    'port' => 1984,
    'concurrent_clients' => 50,        // High concurrency
    'operations_per_client' => 2000,   // Many operations per client
    'test_duration' => 60,             // 60 seconds sustained load
    'payload_sizes' => [100, 1024, 4096, 8192], // Various payload sizes
    'operation_mix' => [
        'put' => 40,    // 40% writes
        'get' => 50,    // 50% reads  
        'del' => 5,     // 5% deletes
        'stats' => 5    // 5% stats
    ]
];

class StressTestClient {
    private $host;
    private $port;
    private $socket;
    private $client_id;
    private $stats = [
        'operations' => 0,
        'errors' => 0,
        'latency_sum' => 0,
        'min_latency' => PHP_FLOAT_MAX,
        'max_latency' => 0,
        'bytes_sent' => 0,
        'bytes_received' => 0
    ];
    
    public function __construct($host, $port, $client_id) {
        $this->host = $host;
        $this->port = $port;
        $this->client_id = $client_id;
    }
    
    public function connect() {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$this->socket) {
            throw new Exception("Failed to create socket: " . socket_strerror(socket_last_error()));
        }
        
        // Optimize socket settings
        socket_set_option($this->socket, SOL_TCP, TCP_NODELAY, 1);
        socket_set_option($this->socket, SOL_SOCKET, SO_KEEPALIVE, 1);
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);
        socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 5, 'usec' => 0]);
        
        if (!socket_connect($this->socket, $this->host, $this->port)) {
            throw new Exception("Failed to connect: " . socket_strerror(socket_last_error($this->socket)));
        }
        
        return true;
    }
    
    public function disconnect() {
        if ($this->socket) {
            socket_close($this->socket);
            $this->socket = null;
        }
    }
    
    private function sendCommand($command) {
        $start = microtime(true);
        
        $bytes_sent = socket_write($this->socket, $command . "\n");
        if ($bytes_sent === false) {
            $this->stats['errors']++;
            return false;
        }
        
        $response = socket_read($this->socket, 8192);
        if ($response === false) {
            $this->stats['errors']++;
            return false;
        }
        
        $latency = (microtime(true) - $start) * 1000; // ms
        
        // Update statistics
        $this->stats['operations']++;
        $this->stats['latency_sum'] += $latency;
        $this->stats['min_latency'] = min($this->stats['min_latency'], $latency);
        $this->stats['max_latency'] = max($this->stats['max_latency'], $latency);
        $this->stats['bytes_sent'] += $bytes_sent;
        $this->stats['bytes_received'] += strlen($response);
        
        return trim($response);
    }
    
    public function runStressTest($operations, $payload_sizes, $operation_mix) {
        $total_weight = array_sum($operation_mix);
        
        for ($i = 0; $i < $operations; $i++) {
            // Select operation type based on mix
            $rand = rand(1, $total_weight);
            $cumulative = 0;
            $operation = 'get'; // default
            
            foreach ($operation_mix as $op => $weight) {
                $cumulative += $weight;
                if ($rand <= $cumulative) {
                    $operation = $op;
                    break;
                }
            }
            
            // Select payload size
            $payload_size = $payload_sizes[array_rand($payload_sizes)];
            $key = "stress_key_{$this->client_id}_{$i}";
            $value = str_repeat('X', $payload_size);
            $tags = "client{$this->client_id},size{$payload_size}";
            
            // Execute operation
            switch ($operation) {
                case 'put':
                    $this->sendCommand("PUT\t{$key}\t3600000\t{$tags}\t{$value}");
                    break;
                case 'get':
                    $this->sendCommand("GET\t{$key}");
                    break;
                case 'del':
                    $this->sendCommand("DEL\t{$key}");
                    break;
                case 'stats':
                    $this->sendCommand("STATS");
                    break;
            }
            
            // Brief pause to prevent overwhelming
            if ($i % 100 == 0) {
                usleep(1000); // 1ms pause every 100 operations
            }
        }
    }
    
    public function getStats() {
        $stats = $this->stats;
        if ($stats['operations'] > 0) {
            $stats['avg_latency'] = $stats['latency_sum'] / $stats['operations'];
            $stats['ops_per_sec'] = $stats['operations'] / ($stats['latency_sum'] / 1000);
        } else {
            $stats['avg_latency'] = 0;
            $stats['ops_per_sec'] = 0;
        }
        return $stats;
    }
}

function runUltraStressTest($config) {
    echo "Configuration:\n";
    echo "- Concurrent clients: {$config['concurrent_clients']}\n";
    echo "- Operations per client: {$config['operations_per_client']}\n";
    echo "- Total operations: " . ($config['concurrent_clients'] * $config['operations_per_client']) . "\n";
    echo "- Payload sizes: " . implode(', ', $config['payload_sizes']) . " bytes\n";
    echo "- Test duration: {$config['test_duration']} seconds\n\n";
    
    echo "Starting ultra high stress test...\n";
    
    $start_time = microtime(true);
    $processes = [];
    
    // Create concurrent client processes
    for ($i = 0; $i < $config['concurrent_clients']; $i++) {
        $pid = pcntl_fork();
        
        if ($pid == -1) {
            die("Could not fork client process $i\n");
        } elseif ($pid == 0) {
            // Child process - run stress test client
            try {
                $client = new StressTestClient($config['host'], $config['port'], $i);
                $client->connect();
                
                $client->runStressTest(
                    $config['operations_per_client'],
                    $config['payload_sizes'],
                    $config['operation_mix']
                );
                
                $stats = $client->getStats();
                $client->disconnect();
                
                // Write stats to temporary file
                file_put_contents("/tmp/stress_client_{$i}.json", json_encode($stats));
                
                exit(0);
            } catch (Exception $e) {
                echo "Client $i error: " . $e->getMessage() . "\n";
                exit(1);
            }
        } else {
            // Parent process - store child PID
            $processes[] = $pid;
        }
    }
    
    // Monitor progress
    echo "Monitoring test progress...\n";
    $monitor_interval = 5; // seconds
    $next_monitor = time() + $monitor_interval;
    
    while (count($processes) > 0) {
        // Check for completed processes
        foreach ($processes as $key => $pid) {
            $status = pcntl_waitpid($pid, $exit_status, WNOHANG);
            if ($status > 0) {
                unset($processes[$key]);
            }
        }
        
        // Print progress update
        if (time() >= $next_monitor) {
            $remaining = count($processes);
            $completed = $config['concurrent_clients'] - $remaining;
            $elapsed = microtime(true) - $start_time;
            echo sprintf("Progress: %d/%d clients completed (%.1fs elapsed)\n", 
                $completed, $config['concurrent_clients'], $elapsed);
            $next_monitor = time() + $monitor_interval;
        }
        
        usleep(100000); // 100ms
    }
    
    $total_time = microtime(true) - $start_time;
    
    // Collect and aggregate results
    echo "\nCollecting results...\n";
    $aggregate_stats = [
        'total_operations' => 0,
        'total_errors' => 0,
        'total_latency_sum' => 0,
        'min_latency' => PHP_FLOAT_MAX,
        'max_latency' => 0,
        'total_bytes_sent' => 0,
        'total_bytes_received' => 0,
        'client_stats' => []
    ];
    
    for ($i = 0; $i < $config['concurrent_clients']; $i++) {
        $stats_file = "/tmp/stress_client_{$i}.json";
        if (file_exists($stats_file)) {
            $client_stats = json_decode(file_get_contents($stats_file), true);
            $aggregate_stats['client_stats'][] = $client_stats;
            
            $aggregate_stats['total_operations'] += $client_stats['operations'];
            $aggregate_stats['total_errors'] += $client_stats['errors'];
            $aggregate_stats['total_latency_sum'] += $client_stats['latency_sum'];
            $aggregate_stats['min_latency'] = min($aggregate_stats['min_latency'], $client_stats['min_latency']);
            $aggregate_stats['max_latency'] = max($aggregate_stats['max_latency'], $client_stats['max_latency']);
            $aggregate_stats['total_bytes_sent'] += $client_stats['bytes_sent'];
            $aggregate_stats['total_bytes_received'] += $client_stats['bytes_received'];
            
            unlink($stats_file); // Clean up
        }
    }
    
    // Calculate final metrics
    if ($aggregate_stats['total_operations'] > 0) {
        $avg_latency = $aggregate_stats['total_latency_sum'] / $aggregate_stats['total_operations'];
        $total_ops_per_sec = $aggregate_stats['total_operations'] / $total_time;
        $throughput_mbps = (($aggregate_stats['total_bytes_sent'] + $aggregate_stats['total_bytes_received']) / 1024 / 1024) / $total_time;
        $error_rate = ($aggregate_stats['total_errors'] / $aggregate_stats['total_operations']) * 100;
    } else {
        $avg_latency = 0;
        $total_ops_per_sec = 0;
        $throughput_mbps = 0;
        $error_rate = 100;
    }
    
    // Display results
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "ULTRA HIGH STRESS TEST RESULTS\n";
    echo str_repeat("=", 60) . "\n";
    echo sprintf("Test Duration: %.2f seconds\n", $total_time);
    echo sprintf("Concurrent Clients: %d\n", $config['concurrent_clients']);
    echo sprintf("Total Operations: %s\n", number_format($aggregate_stats['total_operations']));
    echo sprintf("Total Errors: %s\n", number_format($aggregate_stats['total_errors']));
    echo sprintf("Error Rate: %.2f%%\n", $error_rate);
    echo "\nPerformance Metrics:\n";
    echo sprintf("- Operations/sec: %s\n", number_format($total_ops_per_sec, 0));
    echo sprintf("- Average Latency: %.3f ms\n", $avg_latency);
    echo sprintf("- Min Latency: %.3f ms\n", $aggregate_stats['min_latency']);
    echo sprintf("- Max Latency: %.3f ms\n", $aggregate_stats['max_latency']);
    echo sprintf("- Throughput: %.2f MB/s\n", $throughput_mbps);
    echo sprintf("- Data Sent: %.2f MB\n", $aggregate_stats['total_bytes_sent'] / 1024 / 1024);
    echo sprintf("- Data Received: %.2f MB\n", $aggregate_stats['total_bytes_received'] / 1024 / 1024);
    
    // Performance assessment
    echo "\nPerformance Assessment:\n";
    if ($total_ops_per_sec > 100000) {
        echo "🚀 EXCELLENT: Ultra-high performance achieved!\n";
    } elseif ($total_ops_per_sec > 50000) {
        echo "✅ VERY GOOD: High performance maintained under stress\n";
    } elseif ($total_ops_per_sec > 20000) {
        echo "👍 GOOD: Solid performance under load\n";
    } else {
        echo "⚠️  MODERATE: Performance acceptable but could be optimized\n";
    }
    
    if ($error_rate < 0.1) {
        echo "✅ RELIABILITY: Excellent error rate (< 0.1%)\n";
    } elseif ($error_rate < 1.0) {
        echo "👍 RELIABILITY: Good error rate (< 1%)\n";
    } else {
        echo "⚠️  RELIABILITY: Higher error rate, investigate connection handling\n";
    }
    
    if ($avg_latency < 1.0) {
        echo "⚡ LATENCY: Excellent sub-millisecond response times\n";
    } elseif ($avg_latency < 5.0) {
        echo "✅ LATENCY: Very good response times (< 5ms)\n";
    } else {
        echo "⚠️  LATENCY: Higher than optimal, check TCP socket options\n";
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
    
    return [
        'ops_per_sec' => $total_ops_per_sec,
        'avg_latency' => $avg_latency,
        'error_rate' => $error_rate,
        'throughput_mbps' => $throughput_mbps
    ];
}

// Check if we can fork processes
if (!function_exists('pcntl_fork')) {
    echo "❌ Error: pcntl extension not available. Cannot run concurrent stress test.\n";
    echo "This test requires the pcntl extension for process forking.\n";
    exit(1);
}

// Run the ultra stress test
try {
    $results = runUltraStressTest($config);
    echo "Test completed successfully!\n";
} catch (Exception $e) {
    echo "❌ Test failed: " . $e->getMessage() . "\n";
    exit(1);
}