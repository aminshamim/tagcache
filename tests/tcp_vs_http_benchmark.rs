//! TCP vs HTTP Protocol Performance Comparison Tests
//! 
//! This test compares the performance characteristics of TagCache's TCP and HTTP protocols
//! under identical workload conditions to determine which is faster for different scenarios.
//! 
//! Run with: `cargo test --release -- tcp_vs_http --ignored --nocapture`

use std::time::{Duration, Instant};
use std::sync::Arc;
use std::sync::atomic::{AtomicU64, Ordering};
use std::thread;
use std::io::{BufRead, BufReader, Write};
use std::net::TcpStream;
use hdrhistogram::Histogram;
use rand::{Rng, distributions::Alphanumeric};

/// Helper to generate random alphanumeric strings
fn random_string(len: usize) -> String {
    rand::thread_rng().sample_iter(&Alphanumeric).take(len).map(char::from).collect()
}

/// Results from a protocol benchmark
#[derive(Debug, Clone)]
struct BenchmarkResult {
    protocol: String,
    total_operations: u64,
    duration_secs: f64,
    ops_per_second: f64,
    latency_p50_us: u64,
    latency_p95_us: u64,
    latency_p99_us: u64,
    latency_max_us: u64,
    hits: u64,
    misses: u64,
    hit_ratio: f64,
    bytes_sent: u64,
    bytes_received: u64,
}

impl BenchmarkResult {
    fn new(protocol: &str) -> Self {
        Self {
            protocol: protocol.to_string(),
            total_operations: 0,
            duration_secs: 0.0,
            ops_per_second: 0.0,
            latency_p50_us: 0,
            latency_p95_us: 0,
            latency_p99_us: 0,
            latency_max_us: 0,
            hits: 0,
            misses: 0,
            hit_ratio: 0.0,
            bytes_sent: 0,
            bytes_received: 0,
        }
    }
    
    fn print_summary(&self) {
        println!("\n🚀 {} Protocol Results:", self.protocol);
        println!("═══════════════════════════════════════════");
        println!("📊 Throughput:     {:>12.0} ops/sec", self.ops_per_second);
        println!("📦 Total Ops:      {:>12}", self.total_operations);
        println!("⏱️  Duration:       {:>12.2} seconds", self.duration_secs);
        println!("📈 Hit Ratio:      {:>12.2}%", self.hit_ratio * 100.0);
        println!("🎯 Hits/Misses:    {:>12} / {}", self.hits, self.misses);
        println!("⚡ Latency P50:    {:>12} μs", self.latency_p50_us);
        println!("⚡ Latency P95:    {:>12} μs", self.latency_p95_us);
        println!("⚡ Latency P99:    {:>12} μs", self.latency_p99_us);
        println!("⚡ Latency Max:    {:>12} μs", self.latency_max_us);
        println!("📡 Data Sent:      {:>12} bytes", self.bytes_sent);
        println!("📨 Data Received:  {:>12} bytes", self.bytes_received);
    }
}

/// TCP Protocol Benchmark
fn benchmark_tcp_protocol(
    server_addr: &str,
    duration_secs: u64,
    concurrent_connections: usize,
    key_space: u64,
    value_size: usize,
    get_ratio: f64,
    put_ratio: f64,
) -> BenchmarkResult {
    let mut result = BenchmarkResult::new("TCP");
    
    let stop_at = Instant::now() + Duration::from_secs(duration_secs);
    let ops_counter = Arc::new(AtomicU64::new(0));
    let hits_counter = Arc::new(AtomicU64::new(0));
    let misses_counter = Arc::new(AtomicU64::new(0));
    let bytes_sent_counter = Arc::new(AtomicU64::new(0));
    let bytes_received_counter = Arc::new(AtomicU64::new(0));
    let latency_hist = Arc::new(parking_lot::Mutex::new(Histogram::<u64>::new(3).unwrap()));
    
    let value_template = "V".repeat(value_size);
    
    // Spawn worker threads
    let mut handles = Vec::new();
    for thread_id in 0..concurrent_connections {
        let server = server_addr.to_string();
        let stop = stop_at.clone();
        let ops = ops_counter.clone();
        let hits = hits_counter.clone();
        let misses = misses_counter.clone();
        let bytes_sent = bytes_sent_counter.clone();
        let bytes_recv = bytes_received_counter.clone();
        let hist = latency_hist.clone();
        let val_template = value_template.clone();
        
        handles.push(thread::spawn(move || {
            let mut rng = rand::thread_rng();
            
            // Connect to TCP server
            let mut stream = match TcpStream::connect(&server) {
                Ok(s) => s,
                Err(e) => {
                    eprintln!("TCP connection failed for thread {}: {}", thread_id, e);
                    return;
                }
            };
            
            stream.set_nodelay(true).ok();
            let reader_stream = stream.try_clone().unwrap();
            let mut reader = BufReader::new(reader_stream);
            let mut response_buf = String::new();
            
            while Instant::now() < stop {
                let op_start = Instant::now();
                let r: f64 = rng.gen();
                
                if r < get_ratio {
                    // GET operation
                    let key_id = rng.gen_range(0..key_space);
                    let cmd = format!("GET\tkey_{}\n", key_id);
                    
                    let cmd_bytes = cmd.len() as u64;
                    if stream.write_all(cmd.as_bytes()).is_err() { break; }
                    bytes_sent.fetch_add(cmd_bytes, Ordering::Relaxed);
                    
                    response_buf.clear();
                    if reader.read_line(&mut response_buf).is_err() { break; }
                    bytes_recv.fetch_add(response_buf.len() as u64, Ordering::Relaxed);
                    
                    if response_buf.starts_with("VALUE") {
                        hits.fetch_add(1, Ordering::Relaxed);
                    } else {
                        misses.fetch_add(1, Ordering::Relaxed);
                    }
                } else if r < get_ratio + put_ratio {
                    // PUT operation
                    let key_id = rng.gen_range(0..key_space);
                    let tag = format!("tag_{}", key_id % 100);
                    let cmd = format!("PUT\tkey_{}\t-\t{}\t{}\n", key_id, tag, val_template);
                    
                    let cmd_bytes = cmd.len() as u64;
                    if stream.write_all(cmd.as_bytes()).is_err() { break; }
                    bytes_sent.fetch_add(cmd_bytes, Ordering::Relaxed);
                    
                    response_buf.clear();
                    if reader.read_line(&mut response_buf).is_err() { break; }
                    bytes_recv.fetch_add(response_buf.len() as u64, Ordering::Relaxed);
                } else {
                    // DEL operation
                    let key_id = rng.gen_range(0..key_space);
                    let cmd = format!("DEL\tkey_{}\n", key_id);
                    
                    let cmd_bytes = cmd.len() as u64;
                    if stream.write_all(cmd.as_bytes()).is_err() { break; }
                    bytes_sent.fetch_add(cmd_bytes, Ordering::Relaxed);
                    
                    response_buf.clear();
                    if reader.read_line(&mut response_buf).is_err() { break; }
                    bytes_recv.fetch_add(response_buf.len() as u64, Ordering::Relaxed);
                }
                
                let latency_ns = op_start.elapsed().as_nanos() as u64;
                hist.lock().record(latency_ns).ok();
                ops.fetch_add(1, Ordering::Relaxed);
            }
        }));
    }
    
    // Wait for all threads to complete
    for handle in handles {
        handle.join().ok();
    }
    
    // Collect results
    result.total_operations = ops_counter.load(Ordering::Relaxed);
    result.duration_secs = duration_secs as f64;
    result.ops_per_second = result.total_operations as f64 / result.duration_secs;
    result.hits = hits_counter.load(Ordering::Relaxed);
    result.misses = misses_counter.load(Ordering::Relaxed);
    result.hit_ratio = if result.hits + result.misses > 0 {
        result.hits as f64 / (result.hits + result.misses) as f64
    } else { 0.0 };
    result.bytes_sent = bytes_sent_counter.load(Ordering::Relaxed);
    result.bytes_received = bytes_received_counter.load(Ordering::Relaxed);
    
    let hist = latency_hist.lock();
    result.latency_p50_us = hist.value_at_quantile(0.50) / 1000;
    result.latency_p95_us = hist.value_at_quantile(0.95) / 1000;
    result.latency_p99_us = hist.value_at_quantile(0.99) / 1000;
    result.latency_max_us = hist.max() / 1000;
    
    result
}

/// HTTP Protocol Benchmark
fn benchmark_http_protocol(
    base_url: &str,
    duration_secs: u64,
    concurrent_connections: usize,
    key_space: u64,
    value_size: usize,
    get_ratio: f64,
    put_ratio: f64,
) -> BenchmarkResult {
    let mut result = BenchmarkResult::new("HTTP");
    
    let stop_at = Instant::now() + Duration::from_secs(duration_secs);
    let ops_counter = Arc::new(AtomicU64::new(0));
    let hits_counter = Arc::new(AtomicU64::new(0));
    let misses_counter = Arc::new(AtomicU64::new(0));
    let bytes_sent_counter = Arc::new(AtomicU64::new(0));
    let bytes_received_counter = Arc::new(AtomicU64::new(0));
    let latency_hist = Arc::new(parking_lot::Mutex::new(Histogram::<u64>::new(3).unwrap()));
    
    let value_template = "V".repeat(value_size);
    let auth_header = "Authorization: Basic YWRtaW46cGFzc3dvcmQ="; // admin:password in base64
    
    // Spawn worker threads
    let mut handles = Vec::new();
    for thread_id in 0..concurrent_connections {
        let base_url = base_url.to_string();
        let stop = stop_at.clone();
        let ops = ops_counter.clone();
        let hits = hits_counter.clone();
        let misses = misses_counter.clone();
        let bytes_sent = bytes_sent_counter.clone();
        let bytes_recv = bytes_received_counter.clone();
        let hist = latency_hist.clone();
        let val_template = value_template.clone();
        let auth = auth_header.to_string();
        
        handles.push(thread::spawn(move || {
            let mut rng = rand::thread_rng();
            let client = reqwest::blocking::Client::new();
            
            while Instant::now() < stop {
                let op_start = Instant::now();
                let r: f64 = rng.gen();
                
                if r < get_ratio {
                    // GET operation
                    let key_id = rng.gen_range(0..key_space);
                    let url = format!("{}/get/key_{}", base_url, key_id);
                    
                    match client.get(&url)
                        .header("Authorization", "Basic YWRtaW46cGFzc3dvcmQ=")
                        .send() {
                        Ok(response) => {
                            let status = response.status();
                            match response.text() {
                                Ok(body) => {
                                    bytes_sent.fetch_add(url.len() as u64 + auth.len() as u64 + 50, Ordering::Relaxed); // approximate
                                    bytes_recv.fetch_add(body.len() as u64, Ordering::Relaxed);
                                    
                                    if status.is_success() && body.contains("value") {
                                        hits.fetch_add(1, Ordering::Relaxed);
                                    } else {
                                        misses.fetch_add(1, Ordering::Relaxed);
                                    }
                                }
                                Err(_) => continue,
                            }
                        }
                        Err(_) => continue,
                    }
                } else if r < get_ratio + put_ratio {
                    // PUT operation
                    let key_id = rng.gen_range(0..key_space);
                    let tag = format!("tag_{}", key_id % 100);
                    let payload = serde_json::json!({
                        "key": format!("key_{}", key_id),
                        "value": val_template,
                        "tags": [tag],
                        "ttl_ms": null
                    });
                    
                    match client.post(&format!("{}/put", base_url))
                        .header("Authorization", "Basic YWRtaW46cGFzc3dvcmQ=")
                        .header("Content-Type", "application/json")
                        .json(&payload)
                        .send() {
                        Ok(response) => {
                            match response.text() {
                                Ok(body) => {
                                    let payload_str = payload.to_string();
                                    bytes_sent.fetch_add(payload_str.len() as u64 + auth.len() as u64 + 100, Ordering::Relaxed);
                                    bytes_recv.fetch_add(body.len() as u64, Ordering::Relaxed);
                                }
                                Err(_) => continue,
                            }
                        }
                        Err(_) => continue,
                    }
                } else {
                    // DELETE operation (invalidate key)
                    let key_id = rng.gen_range(0..key_space);
                    let payload = serde_json::json!({
                        "key": format!("key_{}", key_id)
                    });
                    
                    match client.post(&format!("{}/invalidate-key", base_url))
                        .header("Authorization", "Basic YWRtaW46cGFzc3dvcmQ=")
                        .header("Content-Type", "application/json")
                        .json(&payload)
                        .send() {
                        Ok(response) => {
                            match response.text() {
                                Ok(body) => {
                                    let payload_str = payload.to_string();
                                    bytes_sent.fetch_add(payload_str.len() as u64 + auth.len() as u64 + 100, Ordering::Relaxed);
                                    bytes_recv.fetch_add(body.len() as u64, Ordering::Relaxed);
                                }
                                Err(_) => continue,
                            }
                        }
                        Err(_) => continue,
                    }
                }
                
                let latency_ns = op_start.elapsed().as_nanos() as u64;
                hist.lock().record(latency_ns).ok();
                ops.fetch_add(1, Ordering::Relaxed);
            }
        }));
    }
    
    // Wait for all threads to complete
    for handle in handles {
        handle.join().ok();
    }
    
    // Collect results
    result.total_operations = ops_counter.load(Ordering::Relaxed);
    result.duration_secs = duration_secs as f64;
    result.ops_per_second = result.total_operations as f64 / result.duration_secs;
    result.hits = hits_counter.load(Ordering::Relaxed);
    result.misses = misses_counter.load(Ordering::Relaxed);
    result.hit_ratio = if result.hits + result.misses > 0 {
        result.hits as f64 / (result.hits + result.misses) as f64
    } else { 0.0 };
    result.bytes_sent = bytes_sent_counter.load(Ordering::Relaxed);
    result.bytes_received = bytes_received_counter.load(Ordering::Relaxed);
    
    let hist = latency_hist.lock();
    result.latency_p50_us = hist.value_at_quantile(0.50) / 1000;
    result.latency_p95_us = hist.value_at_quantile(0.95) / 1000;
    result.latency_p99_us = hist.value_at_quantile(0.99) / 1000;
    result.latency_max_us = hist.max() / 1000;
    
    result
}

/// Compare TCP vs HTTP performance with identical workloads
#[test]
#[ignore]
fn tcp_vs_http_performance_comparison() {
    println!("\n🔥 TagCache Protocol Performance Comparison");
    println!("═══════════════════════════════════════════════════════════════");
    
    // Test configuration (can be overridden with env vars)
    let duration_secs = std::env::var("DURATION_SECS")
        .ok().and_then(|v| v.parse().ok()).unwrap_or(30);
    let concurrent_connections = std::env::var("CONNECTIONS")
        .ok().and_then(|v| v.parse().ok()).unwrap_or(8);
    let key_space = std::env::var("KEY_SPACE")
        .ok().and_then(|v| v.parse().ok()).unwrap_or(10000);
    let value_size = std::env::var("VALUE_SIZE")
        .ok().and_then(|v| v.parse().ok()).unwrap_or(100);
    let get_ratio = std::env::var("GET_RATIO")
        .ok().and_then(|v| v.parse().ok()).unwrap_or(0.7);
    let put_ratio = std::env::var("PUT_RATIO")
        .ok().and_then(|v| v.parse().ok()).unwrap_or(0.25);
    
    let tcp_addr = std::env::var("TCP_ADDR").unwrap_or_else(|_| "127.0.0.1:1984".to_string());
    let http_addr = std::env::var("HTTP_ADDR").unwrap_or_else(|_| "http://127.0.0.1:8080".to_string());
    
    println!("⚙️  Test Configuration:");
    println!("   Duration:       {} seconds", duration_secs);
    println!("   Connections:    {}", concurrent_connections);
    println!("   Key Space:      {} keys", key_space);
    println!("   Value Size:     {} bytes", value_size);
    println!("   Operation Mix:  {:.0}% GET, {:.0}% PUT, {:.0}% DEL", 
             get_ratio * 100.0, put_ratio * 100.0, (1.0 - get_ratio - put_ratio) * 100.0);
    println!("   TCP Address:    {}", tcp_addr);
    println!("   HTTP Address:   {}", http_addr);
    
    // Pre-populate some data via TCP for fair comparison
    println!("\n📦 Pre-populating cache with {} keys...", key_space / 2);
    if let Ok(mut stream) = TcpStream::connect(&tcp_addr) {
        let mut reader = BufReader::new(stream.try_clone().unwrap());
        let mut buf = String::new();
        let value = "V".repeat(value_size);
        
        for i in 0..(key_space / 2) {
            let cmd = format!("PUT\tkey_{}\t-\ttag_{}\t{}\n", i, i % 100, value);
            stream.write_all(cmd.as_bytes()).ok();
            buf.clear();
            reader.read_line(&mut buf).ok();
        }
        println!("✅ Pre-population complete");
    } else {
        println!("❌ Could not connect to TCP server for pre-population");
    }
    
    println!("\n🚀 Starting benchmarks...\n");
    
    // Run TCP benchmark
    println!("🔧 Running TCP Protocol Benchmark...");
    let tcp_result = benchmark_tcp_protocol(
        &tcp_addr, 
        duration_secs, 
        concurrent_connections, 
        key_space, 
        value_size, 
        get_ratio, 
        put_ratio
    );
    
    // Brief pause between tests
    thread::sleep(Duration::from_secs(2));
    
    // Run HTTP benchmark
    println!("🌐 Running HTTP Protocol Benchmark...");
    let http_result = benchmark_http_protocol(
        &http_addr, 
        duration_secs, 
        concurrent_connections, 
        key_space, 
        value_size, 
        get_ratio, 
        put_ratio
    );
    
    // Display results
    tcp_result.print_summary();
    http_result.print_summary();
    
    // Performance comparison
    println!("\n📊 Performance Comparison");
    println!("═══════════════════════════════════════════");
    
    let throughput_ratio = tcp_result.ops_per_second / http_result.ops_per_second;
    let latency_ratio_p50 = http_result.latency_p50_us as f64 / tcp_result.latency_p50_us as f64;
    let latency_ratio_p95 = http_result.latency_p95_us as f64 / tcp_result.latency_p95_us as f64;
    let bandwidth_ratio = (tcp_result.bytes_sent + tcp_result.bytes_received) as f64 / 
                         (http_result.bytes_sent + http_result.bytes_received) as f64;
    
    println!("🎯 Throughput:      TCP is {:.2}x faster ({:.0} vs {:.0} ops/sec)", 
             throughput_ratio, tcp_result.ops_per_second, http_result.ops_per_second);
    println!("⚡ Latency P50:     TCP is {:.2}x faster ({} vs {} μs)", 
             latency_ratio_p50, tcp_result.latency_p50_us, http_result.latency_p50_us);
    println!("⚡ Latency P95:     TCP is {:.2}x faster ({} vs {} μs)", 
             latency_ratio_p95, tcp_result.latency_p95_us, http_result.latency_p95_us);
    println!("📡 Bandwidth Efficiency: TCP uses {:.2}x less data ({} vs {} bytes)", 
             1.0 / bandwidth_ratio, 
             tcp_result.bytes_sent + tcp_result.bytes_received,
             http_result.bytes_sent + http_result.bytes_received);
    
    // Winner determination
    println!("\n🏆 Overall Winner: {}", 
             if throughput_ratio > 1.0 { "TCP Protocol" } else { "HTTP Protocol" });
    
    if throughput_ratio > 1.0 {
        println!("   • TCP shows {:.1}% higher throughput", (throughput_ratio - 1.0) * 100.0);
        println!("   • TCP shows {:.1}% lower latency", (latency_ratio_p50 - 1.0) * 100.0);
        println!("   • TCP uses {:.1}% less bandwidth", (1.0 - bandwidth_ratio) * 100.0);
    } else {
        println!("   • HTTP shows {:.1}% higher throughput", (1.0 / throughput_ratio - 1.0) * 100.0);
    }
    
    println!("\n💡 Recommendations:");
    if throughput_ratio > 1.5 {
        println!("   • Use TCP for high-performance applications");
        println!("   • Use TCP for latency-sensitive workloads");
        println!("   • Use TCP for bandwidth-constrained environments");
        println!("   • Use HTTP for ease of integration and debugging");
    } else if throughput_ratio > 1.1 {
        println!("   • TCP shows moderate performance advantage");
        println!("   • Choose based on integration requirements");
        println!("   • HTTP may be preferred for web applications");
        println!("   • TCP preferred for high-frequency operations");
    } else {
        println!("   • Both protocols show similar performance");
        println!("   • Choose HTTP for better tooling and debugging");
        println!("   • Choose TCP only if minimal overhead is critical");
    }
    
    println!("\n🔍 Test completed successfully!");
}

/// Quick single-operation latency test
#[test]
#[ignore]
fn single_operation_latency_comparison() {
    println!("\n⚡ Single Operation Latency Comparison");
    println!("══════════════════════════════════════════");
    
    let tcp_addr = "127.0.0.1:1984";
    let http_url = "http://127.0.0.1:8080";
    let iterations = 1000;
    
    // TCP latency test
    let mut tcp_latencies = Vec::new();
    if let Ok(mut stream) = TcpStream::connect(tcp_addr) {
        stream.set_nodelay(true).ok();
        let mut reader = BufReader::new(stream.try_clone().unwrap());
        let mut buf = String::new();
        
        for i in 0..iterations {
            let start = Instant::now();
            let cmd = format!("GET\tkey_{}\n", i);
            stream.write_all(cmd.as_bytes()).ok();
            buf.clear();
            reader.read_line(&mut buf).ok();
            tcp_latencies.push(start.elapsed().as_micros() as u64);
        }
    }
    
    // HTTP latency test
    let mut http_latencies = Vec::new();
    let client = reqwest::blocking::Client::new();
    
    for i in 0..iterations {
        let start = Instant::now();
        let url = format!("{}/get/key_{}", http_url, i);
        if client.get(&url)
            .header("Authorization", "Basic YWRtaW46cGFzc3dvcmQ=")
            .send().is_ok() {
            http_latencies.push(start.elapsed().as_micros() as u64);
        }
    }
    
    if !tcp_latencies.is_empty() && !http_latencies.is_empty() {
        tcp_latencies.sort();
        http_latencies.sort();
        
        let tcp_median = tcp_latencies[tcp_latencies.len() / 2];
        let http_median = http_latencies[http_latencies.len() / 2];
        
        println!("🔧 TCP Median Latency:    {} μs", tcp_median);
        println!("🌐 HTTP Median Latency:   {} μs", http_median);
        println!("📊 TCP is {:.2}x faster for single operations", 
                 http_median as f64 / tcp_median as f64);
    }
}
