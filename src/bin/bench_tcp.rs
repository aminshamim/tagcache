/*!
 * TagCache TCP Protocol Benchmarking Tool
 * 
 * Author: Md. Aminul Islam Sarker <aminshamim@gmail.com>
 * GitHub: https://github.com/aminshamim/tagcache
 * LinkedIn: https://www.linkedin.com/in/aminshamim/
 * 
 * Simple TCP benchmark for TagCache custom protocol
 * Usage: cargo run --release --bin bench_tcp -- [--host 127.0.0.1] [--port 1984] [--conns 32] [--duration 10] [--keys 100] [--mode get|put] [--ttl 60000]
 * It pre-populates keys (for GET mode) then measures ops/sec and latency stats.
 */

use std::{env, time::{Duration, Instant}, sync::{Arc, atomic::{AtomicU64, Ordering}}};
use tokio::{net::TcpStream, io::{AsyncWriteExt, AsyncBufReadExt, BufReader}};

struct Args {
    host: String,
    port: u16,
    conns: usize,
    duration: u64,
    keys: usize,
    mode: String,
    ttl: u64,
}

fn parse_args() -> Args {
    let mut a = Args { host: "127.0.0.1".into(), port: 1984, conns: 32, duration: 10, keys: 100, mode: "get".into(), ttl: 60_000 };
    let mut it = env::args().skip(1);
    while let Some(k) = it.next() {
        match k.as_str() {
            "--host" => if let Some(v) = it.next() { a.host = v; },
            "--port" => if let Some(v) = it.next() { a.port = v.parse().unwrap_or(a.port); },
            "--conns" => if let Some(v) = it.next() { a.conns = v.parse().unwrap_or(a.conns); },
            "--duration" => if let Some(v) = it.next() { a.duration = v.parse().unwrap_or(a.duration); },
            "--keys" => if let Some(v) = it.next() { a.keys = v.parse().unwrap_or(a.keys); },
            "--mode" => if let Some(v) = it.next() { a.mode = v; },
            "--ttl" => if let Some(v) = it.next() { a.ttl = v.parse().unwrap_or(a.ttl); },
            _ => {}
        }
    }
    a
}

#[derive(Default, Clone)]
struct LatencyStats {
    samples: Arc<parking_lot::Mutex<Vec<u64>>>, // nanoseconds
}
impl LatencyStats {
    fn record(&self, ns: u64) { self.samples.lock().push(ns); }
    fn summarize(&self) -> (u64,u64,u64,u64,u64,u64,f64) {
        let mut v = self.samples.lock().clone();
        if v.is_empty() { return (0,0,0,0,0,0,0.0); }
        v.sort_unstable();
        let len = v.len();
        let pct = |p: f64| -> u64 { let idx = ((p/100.0)*(len as f64 -1.0)).round() as usize; v[idx] };
        let sum: u128 = v.iter().map(|&x| x as u128).sum();
        let avg = (sum / len as u128) as u64;
        (v[0], pct(50.0), pct(90.0), pct(95.0), pct(99.0), v[len-1], avg as f64)
    }
}

#[tokio::main]
async fn main() -> anyhow::Result<()> {
    let args = parse_args();
    println!("Benchmark config: host={} port={} conns={} duration={}s keys={} mode={} ttl_ms={}", args.host, args.port, args.conns, args.duration, args.keys, args.mode, args.ttl);

    // Pre-populate if GET mode
    if args.mode == "get" {
        let addr = format!("{}:{}", args.host, args.port);
        let stream = TcpStream::connect(&addr).await?;
        let (rhalf, mut whalf) = stream.into_split();
        let mut reader = BufReader::new(rhalf);
        let mut resp = String::new();
        for i in 0..args.keys {
            let k = format!("k{}", i);
            let line = format!("PUT\t{}\t{}\tbench\tvalue{}\n", k, args.ttl, i);
            whalf.write_all(line.as_bytes()).await?;
            resp.clear();
            reader.read_line(&mut resp).await?; // expect OK
        }
    }

    let stop_at = Instant::now() + Duration::from_secs(args.duration);
    let total = Arc::new(AtomicU64::new(0));
    let lat_stats = LatencyStats::default();

    let mut tasks = Vec::new();
    for id in 0..args.conns {
        let addr = format!("{}:{}", args.host, args.port);
        let total_c = total.clone();
        let lat_c = lat_stats.clone();
        let keys = args.keys;
        let mode = args.mode.clone();
        let ttl = args.ttl;
        let task = tokio::spawn(async move {
            if let Ok(stream) = TcpStream::connect(&addr).await {
                let mut reader = BufReader::new(stream);
                let mut buf = String::new();
                let mut key_idx = id % keys;
                while Instant::now() < stop_at {
                    buf.clear();
                    let (line, expect_value) = if mode == "get" {
                        let k = format!("k{}", key_idx);
                        key_idx = (key_idx + 1) % keys;
                        (format!("GET\t{}\n", k), true)
                    } else { // put
                        let k = format!("k{}", key_idx);
                        key_idx = (key_idx + 1) % keys;
                        (format!("PUT\t{}\t{}\tbench\tvalue\n", k, ttl), false)
                    };
                    let start = Instant::now();
                    if let Err(_) = reader.get_mut().write_all(line.as_bytes()).await { break; }
                    if reader.read_line(&mut buf).await.is_err() { break; }
                    let elapsed = start.elapsed().as_nanos() as u64;
                    lat_c.record(elapsed);
                    if expect_value && !buf.starts_with("VALUE") && !buf.starts_with("NF") {}
                    total_c.fetch_add(1, Ordering::Relaxed);
                }
            } // else connection failed; just return
        });
        tasks.push(task);
    }

    for t in tasks { let _ = t.await; }

    let ops = total.load(Ordering::Relaxed);
    let secs = args.duration as f64;
    let (min, p50, p90, p95, p99, max, avg) = lat_stats.summarize();
    let to_us = |ns: u64| ns as f64 / 1000.0;
    println!("Results:");
    println!("Total ops: {}", ops);
    println!("Throughput: {:.2} ops/sec", ops as f64 / secs);
    println!("Latency (microseconds): min {:.1} p50 {:.1} p90 {:.1} p95 {:.1} p99 {:.1} max {:.1} avg {:.1}",
        to_us(min), to_us(p50), to_us(p90), to_us(p95), to_us(p99), to_us(max), to_us(avg as u64));
    Ok(())
}
