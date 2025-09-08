# TagCache

A lightweight, sharded, tag-aware in‑memory cache server written in Rust. It exposes:

- A JSON HTTP API (default port 8080)
- A low‑latency custom TCP text protocol (default port 1984)
- Tag-based invalidation (by key or by tag)
- Optional per-entry TTL
- Periodic background expiration sweeping

## Features
- Fast concurrent access via multi-shard design (DashMap + hash sharding)
- Associate multiple tags with each key
- Invalidate a single key or all keys sharing a tag
- TTL specified in milliseconds or seconds (first non-null of `ttl_ms`, `ttl_seconds`)
- Stats endpoint (hits, misses, puts, invalidations, hit ratio)
- Compact TCP protocol for reduced overhead vs HTTP/JSON

## Build & Run (Rust)
```bash
cargo build --release
./target/release/tagcache
```
Server starts HTTP on `:8080` and TCP on `:1984` by default.

## Environment Variables
Primary (preferred):
- `PORT` – HTTP port (default 8080)
- `TCP_PORT` – TCP protocol port (default 1984)
- `NUM_SHARDS` – number of shards (default 16)
- `CLEANUP_INTERVAL_MS` – sweep interval in ms (fallback to seconds if not set)
- `CLEANUP_INTERVAL_SECONDS` – sweep interval in seconds (if ms not set)

Legacy (still accepted, logged when used):
- `TC_HTTP_PORT`, `TC_TCP_PORT`, `TC_NUM_SHARDS`, `TC_SWEEP_INTERVAL_MS`

## HTTP API
Base URL: `http://host:PORT`

### PUT /put
Store/update a value.
```bash
curl -X POST http://127.0.0.1:8080/put \
  -H 'Content-Type: application/json' \
  -d '{"key":"user:42","value":"hello","tags":["users","trial"],"ttl_ms":60000}'
```
Response:
```json
{"ok":true,"ttl_ms":60000}
```

### GET /get/:key
```bash
curl http://127.0.0.1:8080/get/user:42
```
Response (hit):
```json
{"value":"hello"}
```
Response (miss):
```json
{"error":"not_found"}
```

### GET /keys-by-tag?tag=TAG&limit=N
```bash
curl 'http://127.0.0.1:8080/keys-by-tag?tag=users&limit=50'
```
Response:
```json
{"keys":["user:42", "user:7"]}
```

### POST /invalidate-key
```bash
curl -X POST http://127.0.0.1:8080/invalidate-key \
  -H 'Content-Type: application/json' \
  -d '{"key":"user:42"}'
```
Response: `{ "success": true }`

### POST /invalidate-tag
```bash
curl -X POST http://127.0.0.1:8080/invalidate-tag \
  -H 'Content-Type: application/json' \
  -d '{"tag":"trial"}'
```
Response: `{ "success": true, "count": <removed> }`

### GET /stats
```bash
curl http://127.0.0.1:8080/stats
```
Response:
```json
{
  "hits": 10,
  "misses": 2,
  "puts": 12,
  "invalidations": 1,
  "hit_ratio": 0.8333
}
```

## TCP Protocol
Line-based, tab-delimited. One command per line. Fields separated by `\t` (TAB). Newline terminates command.

Commands:
```
PUT <key> <ttl_ms|- > <tag1,tag2|- > <value>
GET <key>
DEL <key>
INV_TAG <tag>
KEYS_BY_TAG <tag>   (alias: KEYS <tag>)
STATS
```
Responses (one line):
```
OK | ERR <msg>
VALUE <value> | NF
DEL ok | DEL nf
INV_TAG <count>
KEYS <k1,k2,...>
STATS <hits> <misses> <puts> <invalidations> <hit_ratio>
```
Example session (using `nc` and showing literal tabs as ↹ for clarity):
```
PUT↹user:1↹60000↹users,trial↹hello world
OK
GET↹user:1
VALUE	hello world
INV_TAG↹trial
INV_TAG	1
GET↹user:1
NF
```
Notes:
- Value, key, and tags must not contain tabs or newlines.
- `-` means no TTL or no tags.
- No escaping layer; for binary or large payloads consider a future binary protocol.

## Docker
Build image:
```bash
docker build -t tagcache:latest .
```
Run:
```bash
docker run --rm -p 8080:8080 -p 1984:1984 tagcache:latest
```
Environment overrides:
```bash
docker run -e NUM_SHARDS=64 -e CLEANUP_INTERVAL_MS=5000 -p 8080:8080 -p 1984:1984 tagcache:latest
```

## PHP Example
Provided under `examples/php/test.php`:
```bash
php examples/php/test.php
```
Set `TAGCACHE_URL` for a custom base URL.

## Benchmarking
### Included TCP Bench Tool

Basic usage:
```bash
# Using cargo (recommended)
cargo run --release --bin bench_tcp

# Using compiled binary directly
./target/release/bench_tcp
```

Advanced usage with parameters:
```bash
# Basic benchmark with defaults (GET mode, 32 connections, 10 seconds, 100 keys)
cargo run --release --bin bench_tcp

# PUT benchmark with custom settings
cargo run --release --bin bench_tcp -- --mode put --conns 64 --duration 8 --keys 2000

# GET benchmark with custom settings  
cargo run --release --bin bench_tcp -- --mode get --conns 64 --duration 8 --keys 2000

# High-load benchmark
cargo run --release --bin bench_tcp -- --conns 100 --duration 30 --keys 1000 --mode put

# Custom host and port
cargo run --release --bin bench_tcp -- --host 127.0.0.1 --port 1984 --conns 50 --duration 15
```

Available parameters:
- `--host` - Server host (default: 127.0.0.1)
- `--port` - Server port (default: 1984) 
- `--conns` - Number of concurrent connections (default: 32)
- `--duration` - Test duration in seconds (default: 10)
- `--keys` - Number of keys to use (default: 100)
- `--mode` - Benchmark mode: `get` or `put` (default: get)
- `--ttl` - TTL in milliseconds (default: 60000)

**Note:** Make sure TagCache server is running first using `./tagcache.sh`

Sample results (reference only):
```
PUT:  ~69.9 K ops/sec  p50 ~0.87 ms  p99 ~1.79 ms
GET:  ~65.2 K ops/sec  p50 ~0.95 ms  p99 ~2.25 ms
```

### HTTP via wrk
`put.lua`:
```lua
wrk.method = "POST"
wrk.path   = "/put"
wrk.headers["Content-Type"] = "application/json"
function request()
  local k = math.random(1,100000)
  return wrk.format(nil, nil, nil, '{"key":"k'..k..'","value":"v","tags":["t"],"ttl_ms":60000}')
end
```
Run:
```bash
wrk -t8 -c64 -d10s -s put.lua http://127.0.0.1:8080
```
`get.lua`:
```lua
math.randomseed(os.time())
function request()
  local k = math.random(1,100000)
  return wrk.format("GET", "/get/k"..k)
end
```
Run:
```bash
wrk -t8 -c64 -d10s -s get.lua http://127.0.0.1:8080
```

## Performance Tuning
- Increase `NUM_SHARDS` to reduce contention (power of 2 often helps hash distribution)
- Use `RUSTFLAGS="-C target-cpu=native"` for maximum local CPU optimizations
- Pin process / adjust OS networking (e.g. `ulimit -n`, TCP backlog) for very high concurrency
- Reduce JSON overhead by preferring TCP protocol in latency-sensitive paths

## Limitations / Roadmap
- No persistence (in-memory only)
- No replication / clustering (future: consistent hashing + peer discovery)
- No compression / binary protocol (planned binary frame optional layer)
- Tag cardinality not bounded (monitor memory usage with many distinct tags)

## Development
Run in debug:
```bash
cargo run
```
Run benchmarks (release recommended):
```bash
cargo build --release
./target/release/bench_tcp --mode get --conns 32 --duration 5 --keys 5000
```

## License
(Choose a license and place it here, e.g. MIT or Apache-2.0.)

---
PRs and issues welcome.
