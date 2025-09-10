//! Performance / capacity characterization tests for TagCache.
//! These are not unit tests; they are manual, opt-in load tests.
//! Run with: `cargo test --release -- --ignored --nocapture`
//! Adjust environment variables to tune (NUM_SHARDS, TARGET_MEMORY_MB, etc.).

use std::time::{Duration, Instant};
use std::sync::Arc;
use rand::{Rng, distributions::Alphanumeric};
// The crate currently exposes types only in main.rs (no lib.rs). To reuse them in integration tests
// we include the main.rs file as a module. This avoids refactoring now; later you should extract
// core cache types into src/lib.rs and have main.rs depend on that.
#[path = "../src/main.rs"]
mod main_rs;
use main_rs::{Cache, Key, Tag};
use sysinfo::System;
use hdrhistogram::Histogram;
use std::sync::atomic::{AtomicU64, Ordering};
use std::thread;
use std::io::Write;

fn random_string(len: usize) -> String {
    rand::thread_rng().sample_iter(&Alphanumeric).take(len).map(char::from).collect()
}

fn approx_process_memory_mb() -> f64 {
    let mut sys = System::new();
    sys.refresh_processes();
    if let Some(p) = sys.process(sysinfo::get_current_pid().unwrap()) {
        return p.memory() as f64 / 1024.0; // KB -> MB
    }
    0.0
}

/// Population test: insert until reaching ~70% of target memory budget.
#[test]
#[ignore]
fn population_test() {
    let target_mb: f64 = std::env::var("TARGET_MEMORY_MB").ok().and_then(|v| v.parse().ok()).unwrap_or(512.0);
    let threshold_mb = target_mb * 0.70;
    let cache = Arc::new(Cache::new(std::env::var("NUM_SHARDS").ok().and_then(|v| v.parse().ok()).unwrap_or(16)));

    let mut last_report = Instant::now();
    let start = Instant::now();
    let mut inserted: u64 = 0;
    loop {
    let key = Key::new(format!("k{}", inserted));
        let value = random_string(64); // 64 bytes approximate
    let tag = Tag::new(format!("t{}", inserted % 10));
        cache.put(key, value, vec![tag], None);
        inserted += 1;
        if inserted % 10_000 == 0 {
            let mem = approx_process_memory_mb();
            if last_report.elapsed() > Duration::from_secs(2) {
                let rate = inserted as f64 / start.elapsed().as_secs_f64();
                println!("inserted={} mem_mb={:.1} rate_kops={:.1}", inserted, mem, rate/1000.0);
                last_report = Instant::now();
            }
            if mem >= threshold_mb { break; }
        }
    }
    let duration = start.elapsed();
    println!("FINAL inserted={} duration_s={:.2} avg_kops={:.1}", inserted, duration.as_secs_f64(), inserted as f64 / duration.as_secs_f64() / 1000.0);
}

/// Mixed workload: 90% get, 9% put, 1% invalidate(tag) measuring latency distributions.
#[test]
#[ignore]
fn mixed_workload_latency() {
    let cache = Arc::new(Cache::new(32));
    // Preload keys
    for i in 0..200_000u64 {
    let key = Key::new(format!("pre{i}"));
        let value = random_string(32);
    let tag = Tag::new(format!("grp{}", i % 100));
        cache.put(key, value, vec![tag], None);
    }
    let total_ops = std::env::var("OPS").ok().and_then(|v| v.parse().ok()).unwrap_or(1_000_000u64);
    let mut rng = rand::thread_rng();
    let mut hist_get = Histogram::<u64>::new(3).unwrap();
    let mut hist_put = Histogram::<u64>::new(3).unwrap();
    let mut hist_inv = Histogram::<u64>::new(3).unwrap();

    for op in 0..total_ops {
        let r: f64 = rng.gen();
        if r < 0.90 {
            // GET
            let k = rng.gen_range(0..200_000);
            let key = Key::new(format!("pre{k}"));
            let t0 = Instant::now();
            let _ = cache.get(&key);
            hist_get.record(t0.elapsed().as_nanos() as u64).ok();
        } else if r < 0.99 {
            // PUT
            let id = rng.gen_range(0..200_000);
            let key = Key::new(format!("dyn{id}"));
            let t0 = Instant::now();
            cache.put(key, random_string(32), vec![Tag::new(format!("grp{}", id % 100))], None);
            hist_put.record(t0.elapsed().as_nanos() as u64).ok();
        } else {
            // Invalidate tag
            let tag_id = rng.gen_range(0..100);
            let tag = Tag::new(format!("grp{tag_id}"));
            let t0 = Instant::now();
            let _ = cache.invalidate_tag(&tag);
            hist_inv.record(t0.elapsed().as_nanos() as u64).ok();
        }
        if op > 0 && op % 200_000 == 0 { println!("progress ops={op}"); }
    }

    println!("GET p50={}us p95={}us p99={}us", hist_get.value_at_quantile(0.50)/1000, hist_get.value_at_quantile(0.95)/1000, hist_get.value_at_quantile(0.99)/1000);
    println!("PUT p50={}us p95={}us p99={}us", hist_put.value_at_quantile(0.50)/1000, hist_put.value_at_quantile(0.95)/1000, hist_put.value_at_quantile(0.99)/1000);
    println!("INV p50={}us p95={}us p99={}us", hist_inv.value_at_quantile(0.50)/1000, hist_inv.value_at_quantile(0.95)/1000, hist_inv.value_at_quantile(0.99)/1000);
}

/// Large tag invalidation stress: many keys share one tag; measure single invalidate latency.
#[test]
#[ignore]
fn large_tag_invalidation() {
    let cache = Arc::new(Cache::new(32));
    let big_tag = Tag::new("huge");
    let big_n = std::env::var("BIG_TAG_KEYS").ok().and_then(|v| v.parse().ok()).unwrap_or(500_000usize);
    for i in 0..big_n { cache.put(Key::new(format!("bk{i}")), random_string(16), vec![big_tag.clone()], None); }
    // Add some other noise keys with different tags
    for i in 0..50_000 { cache.put(Key::new(format!("noise{i}")), random_string(16), vec![Tag::new(format!("t{}", i % 10))], None); }
    let t0 = Instant::now();
    let removed = cache.invalidate_tag(&big_tag);
    let dur = t0.elapsed();
    println!("removed={} time_ms={:.2} per_key_us={:.2}", removed, dur.as_secs_f64()*1000.0, dur.as_secs_f64()*1_000_000.0 / removed as f64);
}

/// Continuous mixed read/write/invalidate workload for a target duration (default 180s).
/// Env overrides:
///   DURATION_SECS (u64) - shorten for quick runs
///   THREADS (usize) - number of worker threads
///   KEY_SPACE (u64) - preloaded key space size
#[test]
#[ignore]
fn continuous_rw_invalidation() {
    let duration_secs: u64 = std::env::var("DURATION_SECS").ok().and_then(|v| v.parse().ok()).unwrap_or(180);
    let threads: usize = std::env::var("THREADS").ok().and_then(|v| v.parse().ok()).unwrap_or(8);
    let key_space: u64 = std::env::var("KEY_SPACE").ok().and_then(|v| v.parse().ok()).unwrap_or(500_000);
    let cache = Arc::new(Cache::new(64));

    // Preload key space with tags spread across groups to exercise invalidation.
    for i in 0..key_space { cache.put(Key::new(format!("pre{i}")), random_string(32), vec![Tag::new(format!("grp{}", i % 256))], None); }

    let stop_at = Instant::now() + Duration::from_secs(duration_secs);
    let ops_get = Arc::new(AtomicU64::new(0));
    let ops_put = Arc::new(AtomicU64::new(0));
    let ops_inv = Arc::new(AtomicU64::new(0));
    let hist_get = Arc::new(parking_lot::Mutex::new(Histogram::<u64>::new(3).unwrap()));
    let hist_put = Arc::new(parking_lot::Mutex::new(Histogram::<u64>::new(3).unwrap()));
    let hist_inv = Arc::new(parking_lot::Mutex::new(Histogram::<u64>::new(3).unwrap()));

    // Progress reporter thread (prints every 10s)
    {
        let og = ops_get.clone(); let op = ops_put.clone(); let oi = ops_inv.clone(); let start = Instant::now();
        thread::spawn(move || {
            let mut last_total = 0u64;
            while Instant::now() < stop_at { // stop when workload window ends
                thread::sleep(Duration::from_secs(10));
                let g = og.load(Ordering::Relaxed); let p = op.load(Ordering::Relaxed); let iv = oi.load(Ordering::Relaxed);
                let tot = g + p + iv; let delta = tot - last_total; last_total = tot;
                let elapsed = start.elapsed().as_secs_f64();
                println!("[progress] elapsed_s={:.0} total_ops={} delta_last_10s={} ops_per_sec_avg={:.0} mem_mb={:.1}",
                    elapsed.floor(), tot, delta, tot as f64 / elapsed.max(0.0001), approx_process_memory_mb());
                let _ = std::io::stdout().flush();
            }
        });
    }

    let mut handles = Vec::new();
    for t in 0..threads {
        let cache_cl = cache.clone();
        let stop = stop_at.clone();
        let og = ops_get.clone();
        let op = ops_put.clone();
        let oi = ops_inv.clone();
        let hg = hist_get.clone();
        let hp = hist_put.clone();
        let hi = hist_inv.clone();
        handles.push(thread::spawn(move || {
            let mut rng = rand::thread_rng();
            while Instant::now() < stop {
                let r: f64 = rng.gen();
                if r < 0.80 { // 80% gets
                    let k = rng.gen_range(0..key_space);
                    let key = Key::new(format!("pre{k}"));
                    let t0 = Instant::now();
                    let _ = cache_cl.get(&key);
                    let dur = t0.elapsed().as_nanos() as u64; og.fetch_add(1, Ordering::Relaxed); let _=hg.lock().record(dur);
                } else if r < 0.97 { // 17% puts
                    let id = rng.gen_range(0..key_space);
                    let key = Key::new(format!("dyn{id}_{t}"));
                    let t0 = Instant::now();
                    cache_cl.put(key, random_string(48), vec![Tag::new(format!("grp{}", id % 256))], None);
                    let dur = t0.elapsed().as_nanos() as u64; op.fetch_add(1, Ordering::Relaxed); let _=hp.lock().record(dur);
                } else { // 3% invalidations
                    let tag_id = rng.gen_range(0..256);
                    let t0 = Instant::now();
                    let _ = cache_cl.invalidate_tag(&Tag::new(format!("grp{tag_id}"))); // ignore count
                    let dur = t0.elapsed().as_nanos() as u64; oi.fetch_add(1, Ordering::Relaxed); let _=hi.lock().record(dur);
                }
            }
        }));
    }

    for h in handles { let _ = h.join(); }
    let total_get = ops_get.load(Ordering::Relaxed); let total_put = ops_put.load(Ordering::Relaxed); let total_inv = ops_inv.load(Ordering::Relaxed);
    let elapsed = duration_secs as f64;
    let ops_total = total_get + total_put + total_inv;
    println!("[summary] duration_s={} total_ops={} ops_per_sec={:.0} gets={} puts={} invs={} get_q50_us={} get_q95_us={} inv_q95_us={} mem_mb={:.1}",
        duration_secs,
        ops_total,
        ops_total as f64 / elapsed,
        total_get,
        total_put,
        total_inv,
        hist_get.lock().value_at_quantile(0.50)/1000,
        hist_get.lock().value_at_quantile(0.95)/1000,
        hist_inv.lock().value_at_quantile(0.95)/1000,
        approx_process_memory_mb());
    let _ = std::io::stdout().flush();
}

/// Large RAM (target ~5GB) test with huge values and tag invalidation.
/// Guarded by ALLOW_BIG_TEST=1 to avoid accidental OOM. You can scale down via TARGET_MEMORY_MB.
#[test]
#[ignore]
fn large_memory_huge_values_tag_invalidation() {
    if std::env::var("ALLOW_BIG_TEST").ok().as_deref() != Some("1") { println!("SKIP: set ALLOW_BIG_TEST=1 to run"); return; }
    let target_mb: f64 = std::env::var("TARGET_MEMORY_MB").ok().and_then(|v| v.parse().ok()).unwrap_or(5120.0);
    let threshold_mb = target_mb * 0.95; // aim to stay just under
    let cache = Arc::new(Cache::new(128));
    let baseline = approx_process_memory_mb();
    let big_tag = Tag::new("mega");
    // Choose value size (default 256 KB) adjustable.
    let value_size: usize = std::env::var("VALUE_SIZE").ok().and_then(|v| v.parse().ok()).unwrap_or(256 * 1024);
    let value = "X".repeat(value_size);
    let mut inserted = 0u64;
    let start = Instant::now();
    loop {
        let current_mem = approx_process_memory_mb();
        if current_mem - baseline >= threshold_mb { break; }
        cache.put(Key::new(format!("big{inserted}")), value.clone(), vec![big_tag.clone()], None);
        inserted += 1;
        if inserted % 1000 == 0 && inserted > 0 { println!("inserted={} mem_delta_mb={:.1}", inserted, current_mem - baseline); }
    }
    let load_time = start.elapsed();
    println!("Loaded keys={} total_time_s={:.2} avg_insert_us={:.2}", inserted, load_time.as_secs_f64(), load_time.as_micros() as f64 / inserted as f64);
    // Invalidate huge tag
    let t0 = Instant::now();
    let removed = cache.invalidate_tag(&big_tag);
    let dur = t0.elapsed();
    println!("Invalidated tag keys_removed={} time_ms={:.2} per_key_us={:.2} post_mem_mb={:.1}", removed, dur.as_secs_f64()*1000.0, dur.as_secs_f64()*1_000_000.0/removed.max(1) as f64, approx_process_memory_mb());
}

/// TCP protocol workload test against a running server (impacts live /stats).
/// Env vars:
///   SERVER_ADDR=127.0.0.1:1984 (target TCP port)
///   DURATION_SECS=60          (run duration)
///   CONNS=8                   (number of concurrent TCP connections)
///   KEY_SPACE=50000           (logical key id range for random ops)
///   PREPOPULATE_KEYS=10000    (initial keys to seed; 0 to skip)
///   GET_PCT=80 PUT_PCT=15 INV_PCT=5 (must sum to 100)
///   VALUE_SIZE=64             (bytes for generated value)
///   PIPELINE_DEPTH=1          (commands sent before reading responses; increases throughput)
/// Run: `SERVER_ADDR=127.0.0.1:1984 DURATION_SECS=30 cargo test --release --test perf_tests -- tcp_workload_live --ignored --nocapture`
#[test]
#[ignore]
fn tcp_workload_live() {
    use std::net::TcpStream;
    use std::io::{BufRead, BufReader, Write};
    let server_addr = std::env::var("SERVER_ADDR").unwrap_or_else(|_| "127.0.0.1:1984".to_string());
    let duration_secs: u64 = std::env::var("DURATION_SECS").ok().and_then(|v| v.parse().ok()).unwrap_or(60);
    let conns: usize = std::env::var("CONNS").ok().and_then(|v| v.parse().ok()).unwrap_or(8);
    let key_space: u64 = std::env::var("KEY_SPACE").ok().and_then(|v| v.parse().ok()).unwrap_or(50_000);
    let prepopulate: u64 = std::env::var("PREPOPULATE_KEYS").ok().and_then(|v| v.parse().ok()).unwrap_or(10_000);
    let get_pct: u32 = std::env::var("GET_PCT").ok().and_then(|v| v.parse().ok()).unwrap_or(80);
    let put_pct: u32 = std::env::var("PUT_PCT").ok().and_then(|v| v.parse().ok()).unwrap_or(15);
    let inv_pct: u32 = std::env::var("INV_PCT").ok().and_then(|v| v.parse().ok()).unwrap_or(5);
    assert_eq!(get_pct + put_pct + inv_pct, 100, "percentages must sum to 100");
    let value_size: usize = std::env::var("VALUE_SIZE").ok().and_then(|v| v.parse().ok()).unwrap_or(64);
    let pipeline_depth: usize = std::env::var("PIPELINE_DEPTH").ok().and_then(|v| v.parse().ok()).unwrap_or(1).max(1);

    // Helper: open a connection
    let open_conn = || -> Option<(TcpStream, BufReader<TcpStream>)> {
        match TcpStream::connect(&server_addr) { Ok(s) => { s.set_nodelay(true).ok(); let r = s.try_clone().unwrap(); Some((s, BufReader::new(r))) }, Err(e) => { println!("SKIP: cannot connect to {} ({})", server_addr, e); None } }
    };
    let (mut ctrl_w, mut ctrl_r) = match open_conn() { Some(v) => v, None => return };

    // Fetch initial stats via STATS
    let mut line = String::new();
    let read_line = |r: &mut BufReader<TcpStream>, buf: &mut String| { buf.clear(); r.read_line(buf).ok(); };
    let send = |w: &mut TcpStream, cmd: &str| { let _ = w.write_all(cmd.as_bytes()); let _ = w.write_all(b"\n"); };

    send(&mut ctrl_w, "STATS"); read_line(&mut ctrl_r, &mut line); let initial_stats = line.trim().to_string();
    println!("initial_stats={}", initial_stats);

    // Prepopulate (single connection for simplicity)
    if prepopulate > 0 {
        let val = "V".repeat(value_size);
        for i in 0..prepopulate { if i % 1000 == 0 { println!("prepopulate {}", i); }
            let key = format!("p{}", i);
            let tag = format!("grp{}", i % 256);
            // PUT <key> <ttl_ms> <tags> <value>
            let cmd = format!("PUT\t{}\t-\t{}\t{}", key, tag, val);
            send(&mut ctrl_w, &cmd); read_line(&mut ctrl_r, &mut line);
        }
        println!("prepopulate_done keys={}", prepopulate);
    }

    let stop_at = Instant::now() + Duration::from_secs(duration_secs);
    let ops_total = Arc::new(AtomicU64::new(0));
    let ops_get = Arc::new(AtomicU64::new(0));
    let ops_put = Arc::new(AtomicU64::new(0));
    let ops_inv = Arc::new(AtomicU64::new(0));
    let hits = Arc::new(AtomicU64::new(0));
    let misses = Arc::new(AtomicU64::new(0));

    // Spawn worker threads each with its own connection
    let mut handles = Vec::new();
    for _ in 0..conns {
        let server = server_addr.clone();
        let stop_clone = stop_at.clone();
        let og = ops_get.clone(); let opu = ops_put.clone(); let oi = ops_inv.clone(); let ot = ops_total.clone();
        let h = hits.clone(); let m = misses.clone();
        let pd = pipeline_depth;
        handles.push(thread::spawn(move || {
            let mut rng = rand::thread_rng();
            // open connection
            let mut conn = match TcpStream::connect(&server) { Ok(s) => s, Err(_) => return }; conn.set_nodelay(true).ok();
            let reader_stream = conn.try_clone().unwrap();
            let mut reader = BufReader::new(reader_stream);
            let mut buf = String::new();
            let send_line = |c: &mut TcpStream, text: &str| { let _ = c.write_all(text.as_bytes()); let _ = c.write_all(b"\n"); };
            while Instant::now() < stop_clone {
                // Pipeline a batch of commands before reading responses.
                // Track which indices are GETs to interpret responses.
                let mut is_get = smallvec::SmallVec::<[bool; 32]>::new();
                for _ in 0..pd {
                    if Instant::now() >= stop_clone { break; }
                    let r: u32 = rng.gen_range(0..100);
                    if r < get_pct { // GET
                        let k = rng.gen_range(0..key_space);
                        let cmd = format!("GET\tp{}", k % prepopulate.max(1));
                        send_line(&mut conn, &cmd);
                        is_get.push(true);
                        og.fetch_add(1, Ordering::Relaxed);
                    } else if r < get_pct + put_pct { // PUT
                        let id = rng.gen_range(0..key_space);
                        let reuse_existing = rng.gen_bool(0.5) && prepopulate > 0;
                        let key = if reuse_existing { format!("p{}", id % prepopulate.max(1)) } else { format!("k{}", id) };
                        let val: String = rand::thread_rng().sample_iter(&Alphanumeric).take(value_size.min(128)).map(char::from).collect();
                        let tag = format!("grp{}", id % 256);
                        let cmd = format!("PUT\t{}\t-\t{}\t{}", key, tag, val);
                        send_line(&mut conn, &cmd);
                        is_get.push(false);
                        opu.fetch_add(1, Ordering::Relaxed);
                    } else { // INV_TAG
                        let tag_id = rng.gen_range(0..256);
                        let cmd = format!("INV_TAG\tgrp{}", tag_id);
                        send_line(&mut conn, &cmd);
                        is_get.push(false);
                        oi.fetch_add(1, Ordering::Relaxed);
                    }
                }
                let _ = conn.flush();
                // Read responses for the batch
                for was_get in is_get {
                    buf.clear();
                    if reader.read_line(&mut buf).is_err() { return; }
                    if was_get {
                        if buf.starts_with("VALUE") { h.fetch_add(1, Ordering::Relaxed); } else if buf.starts_with("NF") { m.fetch_add(1, Ordering::Relaxed); }
                    }
                    ot.fetch_add(1, Ordering::Relaxed);
                }
            }
        }));
    }

    // Progress logging
    let progress_total = ops_total.clone();
    let progress_stop = stop_at.clone();
    thread::spawn(move || {
        let mut last = 0u64; let mut last_t = Instant::now();
        while Instant::now() < progress_stop {
            thread::sleep(Duration::from_secs(5));
            let now = Instant::now(); let tot = progress_total.load(Ordering::Relaxed); let delta = tot - last; let dt = now.duration_since(last_t).as_secs_f64();
            println!("[tcp-progress] elapsed_s={:.0} total_ops={} interval_ops={} interval_ops_per_sec={:.0}",
                (now - (progress_stop - Duration::from_secs(duration_secs))).as_secs_f64(), tot, delta, delta as f64 / dt.max(0.0001));
            last = tot; last_t = now;
        }
    });

    for h in handles { let _ = h.join(); }

    // Final STATS fetch
    send(&mut ctrl_w, "STATS"); line.clear(); read_line(&mut ctrl_r, &mut line); let final_stats = line.trim().to_string();
    println!("final_stats={}", final_stats);
    let total = ops_total.load(Ordering::Relaxed);
    let hval = hits.load(Ordering::Relaxed); let mval = misses.load(Ordering::Relaxed);
    let hr = if hval + mval > 0 { hval as f64 / (hval + mval) as f64 } else { 0.0 };
    println!("workload_summary duration_s={} total_ops={} get_ops={} put_ops={} inv_tag_ops={} tcp_hits={} tcp_misses={} tcp_hit_ratio={:.4}", duration_secs, total, ops_get.load(Ordering::Relaxed), ops_put.load(Ordering::Relaxed), ops_inv.load(Ordering::Relaxed), hval, mval, hr);
}

/// Massive dataset + hot set optimized workload to maximize hit ratio while still generating writes & invalidations.
/// Ignored by default. Enable with MASSIVE=1 to avoid accidental huge memory usage.
/// User intent: 2,000,000 ("20 lakh") rows, each ~10KB value, high hit rate, active writes + invalidations, minimal misses.
/// Environment variables:
///   MASSIVE=1              (required to run)
///   SERVER_ADDR=127.0.0.1:1984
///   TOTAL_KEYS=2000000     (2 million default; DANGER: requires ~20GB+ RAM with 10KB values plus overhead)
///   VALUE_SIZE=10000       (bytes per value)
///   HOT_RATIO=0.05         (fraction of keys forming the hot set heavily read; adjust for higher hit rate)
///   TAG_MOD=1000           (number of distinct tag groups)
///   CONNS=8                (parallel connections)
///   PIPELINE_DEPTH=32      (batch size per connection)
///   DURATION_SECS=60       (post-population workload duration)
///   GET_PCT=85 PUT_PCT=10 INV_PCT=5  (mix; must sum to 100)
///   MISS_PCT=1             (% of GETs forced to miss; set 0 to minimize misses further)
///   REPOP_PER_INVALIDATE=8 (PUT repairs after each tag invalidation to restore hot coverage)
///   WARM_PUT_PCT=20        (percentage of PUT ops that target warm (non-hot) keys to keep wider set resident)
/// Safety NOTE: TOTAL_KEYS * VALUE_SIZE dominates memory. 2,000,000 * 10,000 ~= 20 GB excluding allocator + metadata.
#[test]
#[ignore]
fn tcp_massive_dataset_hotset() {
    if std::env::var("MASSIVE").ok().as_deref() != Some("1") { eprintln!("Skipping tcp_massive_dataset_hotset (set MASSIVE=1 to run)"); return; }
    use rand::distributions::Alphanumeric;
    use rand::Rng;
    use std::io::{BufRead, Write};
    use std::net::TcpStream;
    use std::sync::atomic::{AtomicU64, Ordering};
    use std::sync::Arc;
    use std::thread;
    use std::time::{Duration, Instant};

    let server_addr = std::env::var("SERVER_ADDR").unwrap_or_else(|_| "127.0.0.1:1984".to_string());
    let total_keys: u64 = std::env::var("TOTAL_KEYS").ok().and_then(|v| v.parse().ok()).unwrap_or(2_000_000);
    let value_size: usize = std::env::var("VALUE_SIZE").ok().and_then(|v| v.parse().ok()).unwrap_or(10_000);
    let mut hot_ratio: f64 = std::env::var("HOT_RATIO").ok().and_then(|v| v.parse().ok()).unwrap_or(0.05);
    if hot_ratio < 0.0001 { hot_ratio = 0.0001; } else if hot_ratio > 0.99 { hot_ratio = 0.99; }
    let tag_mod: u64 = std::env::var("TAG_MOD").ok().and_then(|v| v.parse().ok()).unwrap_or(1000);
    let conns: usize = std::env::var("CONNS").ok().and_then(|v| v.parse().ok()).unwrap_or(8).max(1);
    let pipeline_depth: usize = std::env::var("PIPELINE_DEPTH").ok().and_then(|v| v.parse().ok()).unwrap_or(32).max(1);
    let duration_s: u64 = std::env::var("DURATION_SECS").ok().and_then(|v| v.parse().ok()).unwrap_or(60);
    let get_pct: u32 = std::env::var("GET_PCT").ok().and_then(|v| v.parse().ok()).unwrap_or(85);
    let put_pct: u32 = std::env::var("PUT_PCT").ok().and_then(|v| v.parse().ok()).unwrap_or(10);
    let inv_pct: u32 = std::env::var("INV_PCT").ok().and_then(|v| v.parse().ok()).unwrap_or(5);
    assert_eq!(get_pct + put_pct + inv_pct, 100, "GET_PCT+PUT_PCT+INV_PCT must sum to 100");
    let miss_pct: u32 = std::env::var("MISS_PCT").ok().and_then(|v| v.parse().ok()).unwrap_or(1).min(20); // clamp runaway
    let repop_per_inval: u32 = std::env::var("REPOP_PER_INVALIDATE").ok().and_then(|v| v.parse().ok()).unwrap_or(8);
    let repair_mode = std::sync::Arc::new(std::env::var("REPAIR_MODE").unwrap_or_else(|_| "targeted".into())); // targeted | random
    let repair_limit: u32 = std::env::var("REPAIR_LIMIT").ok().and_then(|v| v.parse().ok()).unwrap_or(128);
    let self_heal_on_miss: bool = std::env::var("SELF_HEAL_ON_MISS").ok().map(|v| v == "1" || v.eq_ignore_ascii_case("true")).unwrap_or(true);
    let min_hit_ratio: f64 = std::env::var("MIN_HIT_RATIO").ok().and_then(|v| v.parse().ok()).unwrap_or(0.80);
    let warm_put_pct: u32 = std::env::var("WARM_PUT_PCT").ok().and_then(|v| v.parse().ok()).unwrap_or(20).min(100);

    let hot_keys = (total_keys as f64 * hot_ratio).round() as u64;
    let value_template: String = if value_size <= 1024 { // build randomly if small
        rand::thread_rng().sample_iter(&Alphanumeric).take(value_size).map(char::from).collect()
    } else {
        // deterministic repeated pattern to avoid CPU cost
        let pattern = b"ABCDEFGHIJKLMNOPQRSTUVWXYZ012345"; // 32 bytes
        let mut s = String::with_capacity(value_size);
        while s.len() < value_size { let take = pattern.len().min(value_size - s.len()); s.push_str(std::str::from_utf8(&pattern[..take]).unwrap()); }
        s
    };

    eprintln!("[massive] server_addr={} total_keys={} (~{:.2} GB payload) value_size={} hot_keys={} tag_mod={} conns={} pipeline_depth={}",
              server_addr, total_keys, (total_keys as f64 * value_size as f64)/(1024.0*1024.0*1024.0), value_size, hot_keys, tag_mod, conns, pipeline_depth);
    eprintln!("[massive] mix: GET={} PUT={} (warm_put_pct={}%) INV={} miss_pct={} repop_per_inval={} repair_mode={} repair_limit={} self_heal_on_miss={} min_hit_ratio={}",
              get_pct, put_pct, warm_put_pct, inv_pct, miss_pct, repop_per_inval, repair_mode.as_str(), repair_limit, self_heal_on_miss, min_hit_ratio);

    // Prepopulation (parallel). WARNING: This will attempt to write TOTAL_KEYS entries; ensure system has memory.
    let next_key = Arc::new(AtomicU64::new(0));
    let puts_done = Arc::new(AtomicU64::new(0));
    let start_pop = Instant::now();
    let mut threads = Vec::new();
    for _ in 0..conns {
        let server = server_addr.clone();
        let nk = next_key.clone();
        let vt = value_template.clone();
        let td = puts_done.clone();
        let pd = pipeline_depth;
        threads.push(thread::spawn(move || {
            let mut stream = match TcpStream::connect(&server) { Ok(s) => s, Err(_) => { return; } };
            stream.set_nodelay(true).ok();
            let mut reader = std::io::BufReader::new(stream.try_clone().unwrap());
            let mut line = String::new();
            loop {
                let mut batch = 0usize;
                while batch < pd {
                    let id = nk.fetch_add(1, Ordering::Relaxed);
                    if id >= total_keys { break; }
                    let tag = id % tag_mod;
                    let cmd = format!("PUT\tp{}\t-\tgrp{}\t{}", id, tag, vt);
                    if stream.write_all(cmd.as_bytes()).is_err() { return; }
                    if stream.write_all(b"\n").is_err() { return; }
                    batch += 1;
                }
                if batch == 0 { break; }
                let _ = stream.flush();
                for _ in 0..batch { line.clear(); if reader.read_line(&mut line).is_err() { return; } }
                td.fetch_add(batch as u64, Ordering::Relaxed);
            }
        }));
    }
    let mut last_report = Instant::now();
    loop {
        if puts_done.load(Ordering::Relaxed) >= total_keys { break; }
        if last_report.elapsed() >= Duration::from_secs(5) {
            let done = puts_done.load(Ordering::Relaxed);
            let pct = (done as f64 / total_keys as f64) * 100.0;
            eprintln!("[massive][prepopulate] {} / {} ({:.2}%) elapsed_s={:.1} rate={:.0} puts/s", done, total_keys, pct, start_pop.elapsed().as_secs_f64(), (done as f64 / start_pop.elapsed().as_secs_f64()));
            last_report = Instant::now();
        }
        std::thread::sleep(Duration::from_millis(250));
    }
    for t in threads { let _ = t.join(); }
    let pop_elapsed = start_pop.elapsed().as_secs_f64();
    eprintln!("[massive] prepopulation complete: keys={} time_s={:.2} rate={:.0} puts/s", total_keys, pop_elapsed, (total_keys as f64 / pop_elapsed));

    // Workload phase.
    let stop_at = Instant::now() + Duration::from_secs(duration_s);
    let ops_get = Arc::new(AtomicU64::new(0));
    let ops_put = Arc::new(AtomicU64::new(0));
    let ops_inv = Arc::new(AtomicU64::new(0));
    let ops_total = Arc::new(AtomicU64::new(0));
    let hits = Arc::new(AtomicU64::new(0));
    let misses = Arc::new(AtomicU64::new(0));

    let mut workers = Vec::new();
    for _ in 0..conns {
        let server = server_addr.clone();
        let vt = value_template.clone();
        let repair_mode = repair_mode.clone();
        let og = ops_get.clone(); let opu = ops_put.clone(); let oi = ops_inv.clone(); let ot = ops_total.clone();
        let h = hits.clone(); let m = misses.clone();
        let stop = stop_at.clone();
        workers.push(thread::spawn(move || {
            let mut rng = rand::thread_rng();
            let mut conn = match TcpStream::connect(&server) { Ok(s) => s, Err(_) => { return; } };
            conn.set_nodelay(true).ok();
            let reader_stream = conn.try_clone().unwrap();
            let mut reader = std::io::BufReader::new(reader_stream);
            let mut buf = String::new();
            let mut is_get_batch = smallvec::SmallVec::<[bool; 64]>::new();
            let mut key_ids = smallvec::SmallVec::<[u64; 64]>::new(); // parallel to batch (valid only for GETs; for others placeholder)
            while Instant::now() < stop {
                is_get_batch.clear();
                key_ids.clear();
                for _ in 0..pipeline_depth {
                    if Instant::now() >= stop { break; }
                    let r: u32 = rng.gen_range(0..100);
                    if r < get_pct { // GET
                        let miss_roll: u32 = rng.gen_range(0..100);
                        let key_id = if miss_roll < miss_pct { total_keys + rng.gen_range(0..1000) as u64 } else {
                            if rng.gen_range(0..100) < 92 { rng.gen_range(0..hot_keys) } else { rng.gen_range(hot_keys..total_keys) }
                        };
                        let cmd = format!("GET\tp{}", key_id);
                        let _ = conn.write_all(cmd.as_bytes()); let _ = conn.write_all(b"\n");
                        is_get_batch.push(true); key_ids.push(key_id); og.fetch_add(1, Ordering::Relaxed);
                    } else if r < get_pct + put_pct { // PUT (update hot key)
                        let choose_warm = rng.gen_range(0..100) < warm_put_pct && hot_keys < total_keys;
                        let key_id = if choose_warm { rng.gen_range(hot_keys..total_keys) } else { rng.gen_range(0..hot_keys) };
                        let cmd = format!("PUT\tp{}\t-\tgrp{}\t{}", key_id, key_id % tag_mod, vt);
                        let _ = conn.write_all(cmd.as_bytes()); let _ = conn.write_all(b"\n");
                        is_get_batch.push(false); key_ids.push(0); opu.fetch_add(1, Ordering::Relaxed);
                    } else { // INV_TAG
                        let key_id = rng.gen_range(0..hot_keys);
                        let tag = key_id % tag_mod;
                        let cmd = format!("INV_TAG\tgrp{}", tag);
                        let _ = conn.write_all(cmd.as_bytes()); let _ = conn.write_all(b"\n");
                        is_get_batch.push(false); key_ids.push(0); oi.fetch_add(1, Ordering::Relaxed);
                        // Repair strategy to quickly restore hot working set and preserve hit ratio.
                        match repair_mode.as_str() {
                            "targeted" => {
                                // Iterate deterministic keys in this tag group across full key space (hot + warm).
                                let mut repaired = 0u32; let mut k = tag;
                                while k < total_keys && repaired < repair_limit && is_get_batch.len() < pipeline_depth {
                                    let cmd2 = format!("PUT\tp{}\t-\tgrp{}\t{}", k, tag, vt);
                                    let _ = conn.write_all(cmd2.as_bytes()); let _ = conn.write_all(b"\n");
                                    is_get_batch.push(false); key_ids.push(0); opu.fetch_add(1, Ordering::Relaxed);
                                    repaired += 1; k += tag_mod;
                                }
                            }
                            _ => { // random fallback
                                for _ in 0..repop_per_inval.min(pipeline_depth as u32) {
                                    if is_get_batch.len() >= pipeline_depth { break; }
                                    let choose_warm = rng.gen_range(0..100) < warm_put_pct && hot_keys < total_keys;
                                    let k = if choose_warm { rng.gen_range(hot_keys..total_keys) } else { rng.gen_range(0..hot_keys) };
                                    let cmd2 = format!("PUT\tp{}\t-\tgrp{}\t{}", k, k % tag_mod, vt);
                                    let _ = conn.write_all(cmd2.as_bytes()); let _ = conn.write_all(b"\n");
                                    is_get_batch.push(false); key_ids.push(0); opu.fetch_add(1, Ordering::Relaxed);
                                }
                            }
                        }
                    }
                }
                let _ = conn.flush();
                for (idx, was_get) in is_get_batch.iter().enumerate() {
                    buf.clear(); if reader.read_line(&mut buf).is_err() { return; }
                    if *was_get {
                        if buf.starts_with("VALUE") { h.fetch_add(1, Ordering::Relaxed); }
                        else if buf.starts_with("NF") { m.fetch_add(1, Ordering::Relaxed);
                            if self_heal_on_miss {
                                // Immediate self-heal: PUT the missing key with a hot tag (its original tag derivable from id % tag_mod).
                                if let Some(key_id) = key_ids.get(idx) { let tag = *key_id % tag_mod; let heal = format!("PUT\tp{}\t-\tgrp{}\t{}", key_id, tag, vt); let _ = conn.write_all(heal.as_bytes()); let _ = conn.write_all(b"\n"); let _ = conn.flush(); buf.clear(); let _ = reader.read_line(&mut buf); opu.fetch_add(1, Ordering::Relaxed); ot.fetch_add(1, Ordering::Relaxed); }
                            }
                        }
                    }
                    ot.fetch_add(1, Ordering::Relaxed);
                }
            }
        }));
    }

    // Progress reporter
    let mut last_total = 0u64; let mut last_time = Instant::now();
    while Instant::now() < stop_at { std::thread::sleep(Duration::from_secs(5));
        let now_total = ops_total.load(Ordering::Relaxed);
        let int_ops = now_total - last_total; let int_rate = int_ops as f64 / last_time.elapsed().as_secs_f64();
        eprintln!("[massive][progress] elapsed_s={:.0} total_ops={} int_ops={} int_ops_per_sec={:.0} hit_ratio={:.4}",
                  (Instant::now() + Duration::from_secs(0) - (stop_at - Duration::from_secs(duration_s))).as_secs_f64(), now_total, int_ops, int_rate, {
            let h = hits.load(Ordering::Relaxed); let miss = misses.load(Ordering::Relaxed); if h+miss>0 { h as f64 /(h+miss) as f64 } else { 0.0 }
        });
        last_total = now_total; last_time = Instant::now();
    }
    for w in workers { let _ = w.join(); }

    let h = hits.load(Ordering::Relaxed); let miss = misses.load(Ordering::Relaxed);
    let get = ops_get.load(Ordering::Relaxed); let put = ops_put.load(Ordering::Relaxed); let inv = ops_inv.load(Ordering::Relaxed); let tot = ops_total.load(Ordering::Relaxed);
    let hit_ratio = if h+miss>0 { h as f64/(h+miss) as f64 } else { 0.0 };
    eprintln!("[massive][summary] duration_s={} total_ops={} get_ops={} put_ops={} inv_tag_ops={} hits={} misses={} hit_ratio={:.4}",
              duration_s, tot, get, put, inv, h, miss, hit_ratio);
    assert!(hit_ratio >= min_hit_ratio, "expected hit ratio >= {} (got {:.3})", min_hit_ratio, hit_ratio);
}

