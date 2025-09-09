// TagCache main server implementation with heavy inline comments for Rust beginners.
// The goal of these comments is to explain: types, ownership, concurrency primitives,
// async runtime usage, data model, and protocol handling. Feel free to trim later.

use axum::{routing::{get, post, put, delete}, Json, Router, extract::{Path, Query, FromRequestParts, State}, response::Json as ResponseJson, http::{request::Parts, StatusCode}}; // Axum web framework imports for routing & JSON
use serde::{Deserialize, Serialize}; // Serde for (de)serialization of JSON payloads
use std::{sync::Arc, time::{Duration, Instant, SystemTime, UNIX_EPOCH}, env}; // Arc = thread-safe reference counting; time utilities; env vars
use dashmap::{DashMap, DashSet}; // Concurrent hash map + set (lock sharded) for high concurrency
use smallvec::SmallVec; // Optimized small vector that stores small number of elements inline (avoids heap for few tags)
use tokio::time; // Tokio timing utilities (interval)
use ahash::{RandomState}; // Fast hashing state for consistent shard distribution
use std::hash::{Hash, Hasher, BuildHasher}; // Traits for custom hashing
use tower_http::cors::{CorsLayer}; // CORS middleware for HTTP
use tracing::{info}; // Structured logging (use RUST_LOG=info to see)
use parking_lot::Mutex; // Faster, simpler mutex vs std::sync::Mutex (not poisonable)
use tokio::net::{TcpListener, TcpStream}; // Async TCP server primitives
use tokio::io::{AsyncBufReadExt, AsyncWriteExt, BufReader}; // Async buffered IO extensions
use rand::{Rng, distributions::Alphanumeric};
use std::fs;
use std::path::Path as FsPath;
use std::io::Write;
use std::collections::HashMap;
use base64::engine::general_purpose::STANDARD as B64;
use base64::Engine;

// =============================
// DATA MODEL TYPES
// =============================
// We wrap raw String keys in a newtype Key for type safety + trait impls.
#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub struct Key(String); // Simple wrapper; cloning duplicates the underlying String.

// Same idea for Tag — improves clarity and prevents mixing strings accidentally.
#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub struct Tag(String);

// Represents one cached entry (the stored value + metadata).
#[derive(Debug, Clone)]
pub struct Entry {
    pub value: String,                // The actual cached value (here just a String; could be bytes or JSON later)
    pub tags: SmallVec<[Tag; 4]>,     // Tags associated with this key (SmallVec keeps up to 4 inline, no heap alloc)
    pub created_at: Instant,          // When the entry was inserted (for TTL expiration)
    pub ttl: Option<Duration>,        // Optional time-to-live; None = never expires (unless invalidated)
    pub created_system: SystemTime,   // Wall clock creation time
}

impl Entry {
    // Helper to check if this entry should be considered expired.
    pub fn is_expired(&self) -> bool {
        if let Some(ttl) = self.ttl {             // If a TTL exists
            self.created_at.elapsed() > ttl       // Compare elapsed time to TTL
        } else {
            false                                  // No TTL => never expires
        }
    }
}

// A Shard holds a subset of all keys. Sharding reduces contention: each DashMap already shards internally,
// but we add an outer manual shard layer to control scaling and future distribution strategies.
#[derive(Debug)]
pub struct Shard {
    pub entries: DashMap<Key, Entry>,          // Map key -> entry
    pub tag_to_keys: DashMap<Tag, DashSet<Key>>, // Reverse index: tag -> set of keys sharing it
}

impl Shard {
    pub fn new() -> Self {
        Self {
            entries: DashMap::new(),
            tag_to_keys: DashMap::new(),
        }
    }
}

// Overall cache — contains multiple shards and aggregated statistics.
#[derive(Debug)]
pub struct Cache {
    pub shards: Vec<Shard>,               // Fixed number of shards selected by hashing the key
    pub stats: Arc<Mutex<CacheStats>>,    // Shared stats protected by a Mutex (updates are small / low contention)
    hasher: RandomState,                 // Fast hashing state (provides build_hasher())
}

// Simple counters; Clone so we can snapshot for /stats without locking long.
#[derive(Debug, Default, Clone)]
pub struct CacheStats {
    pub hits: u64,
    pub misses: u64,
    pub puts: u64,
    pub invalidations: u64,
}

// =============================
// AUTH TYPES
// =============================
#[derive(Clone, Debug)]
pub struct Credentials { pub username: String, pub password: String }

#[derive(Clone, Debug)]
pub struct AuthState { credentials: Arc<Mutex<Credentials>>, tokens: DashSet<String> }

impl AuthState {
    fn new(creds: Credentials) -> Self { Self { credentials: Arc::new(Mutex::new(creds)), tokens: DashSet::new() } }
    fn issue_token(&self) -> String { let token: String = rand::thread_rng().sample_iter(&Alphanumeric).take(48).map(char::from).collect(); self.tokens.insert(token.clone()); token }
    fn rotate(&self) -> Credentials { let new = Credentials { username: rand::thread_rng().sample_iter(&Alphanumeric).take(16).map(char::from).collect(), password: rand::thread_rng().sample_iter(&Alphanumeric).take(24).map(char::from).collect() }; *self.credentials.lock() = new.clone(); self.tokens.clear(); new }
    fn validate_basic(&self, u:&str, p:&str) -> bool { let c = self.credentials.lock(); c.username==u && c.password==p }
    fn validate_token(&self, t:&str) -> bool { self.tokens.contains(t) }
}

#[derive(Clone)]
pub struct AppState { pub cache: Arc<Cache>, pub auth: Arc<AuthState> }

// Request guard for auth (per-route, simpler + fast)
pub struct Authenticated;
#[axum::async_trait]
impl FromRequestParts<Arc<AppState>> for Authenticated {
    type Rejection = (StatusCode, ResponseJson<serde_json::Value>);
    async fn from_request_parts(parts: &mut Parts, state: &Arc<AppState>) -> Result<Self, Self::Rejection> {
        if let Some(hv) = parts.headers.get(axum::http::header::AUTHORIZATION) {
            if let Ok(s) = hv.to_str() {
                if let Some(bearer) = s.strip_prefix("Bearer ") { if state.auth.validate_token(bearer) { return Ok(Authenticated); } }
                if let Some(basic) = s.strip_prefix("Basic ") {
                    if let Ok(decoded) = B64.decode(basic) {
                        if let Ok(pair) = String::from_utf8(decoded) {
                            if let Some((u,p)) = pair.split_once(':') { if state.auth.validate_basic(u,p) { return Ok(Authenticated); } }
                        }
                    }
                }
            }
        }
        Err((StatusCode::UNAUTHORIZED, ResponseJson(serde_json::json!({"error":"unauthorized"}))))
    }
}

#[derive(Deserialize)] struct LoginBody { username:String, password:String }
#[derive(Serialize)] struct LoginResponse { token:String, expires_in:u64 }
#[derive(Serialize)] struct RotateResponse { ok:bool, username:String, password:String }
#[derive(Serialize)] struct SetupRequired { setup_required: bool }

impl Cache {
    // Create a new cache with N shards.
    pub fn new(num_shards: usize) -> Self {
        assert!(num_shards > 0, "num_shards must be > 0");
        let mut shards = Vec::with_capacity(num_shards);
        for _ in 0..num_shards { // Allocate and push each shard
            shards.push(Shard::new());
        }
        Self {
            shards,
            stats: Arc::new(Mutex::new(CacheStats::default())),
            hasher: RandomState::new(), // Random seed hashing state for consistent distribution
        }
    }

    // Decide which shard a key belongs to using hashing.
    fn hash_key(&self, key: &Key) -> usize {
        let mut hasher = self.hasher.build_hasher(); // Build a new hasher instance
        key.hash(&mut hasher); // Feed the key
        (hasher.finish() as usize) % self.shards.len() // Map to shard index
    }

    // Insert or update a key with value + tags + optional TTL.
    pub fn put(&self, key: Key, value: String, tags: Vec<Tag>, ttl: Option<Duration>) {
        let shard_idx = self.hash_key(&key);      // Pick shard
        let shard = &self.shards[shard_idx];

        // Build new entry (Instant::now() captured here).
        let entry = Entry {
            value,
            tags: SmallVec::from_vec(tags.clone()), // Clone tags so we can also iterate original vector for indexing
            created_at: Instant::now(),
            ttl,
            created_system: SystemTime::now(),
        };

        // If key existed, remove old tag associations to avoid stale reverse index entries.
        if let Some(old_entry) = shard.entries.get(&key) {
            for tag in &old_entry.tags {
                if let Some(keys) = shard.tag_to_keys.get(tag) { // Look up DashSet for this tag
                    keys.remove(&key);                           // Remove key from tag set
                }
            }
        }

        // Add reverse index entries for each new tag.
        for tag in &tags {
            shard
                .tag_to_keys
                .entry(tag.clone())              // Get or insert a DashSet for this tag
                .or_insert_with(DashSet::new)
                .insert(key.clone());            // Insert key into the tag set
        }

        shard.entries.insert(key, entry);        // Upsert the actual entry
        self.stats.lock().puts += 1;              // Increment PUT counter (lock is short-lived)
    }

    // Retrieve a value if present and not expired.
    pub fn get(&self, key: &Key) -> Option<String> {
        let shard_idx = self.hash_key(key);
        let shard = &self.shards[shard_idx];
        if let Some(entry) = shard.entries.get(key) {   // entry = DashMap reference guard
            if entry.is_expired() {                     // TTL check
                shard.entries.remove(key);              // Eager removal of expired entry
                self.stats.lock().misses += 1;          // Count as miss
                None
            } else {
                self.stats.lock().hits += 1;            // Count as hit
                Some(entry.value.clone())               // Clone value out (cheap relative to network cost)
            }
        } else {
            self.stats.lock().misses += 1;              // Key absent
            None
        }
    }

    // Return all keys that have a given tag (filtering expired ones).
    pub fn get_keys_by_tag(&self, tag: &Tag) -> Vec<Key> {
        let mut result = Vec::new();
        for shard in &self.shards {                     // Scan every shard (O(shards + keys_for_tag))
            if let Some(keys) = shard.tag_to_keys.get(tag) { // If this shard has any keys for the tag
                for key in keys.iter() {                     // Iterate the set of keys
                    if let Some(entry) = shard.entries.get(&key) {
                        if !entry.is_expired() {             // Avoid returning expired keys
                            result.push(key.clone());
                        }
                    }
                }
            }
        }
        result
    }

    // Invalidate (remove) a single key (and detach all its tags).
    pub fn invalidate_key(&self, key: &Key) -> bool {
        let shard_idx = self.hash_key(key);
        let shard = &self.shards[shard_idx];
        if let Some((_, entry)) = shard.entries.remove(key) { // Remove returns (key, value)
            for tag in &entry.tags {                          // Clean reverse index
                if let Some(keys) = shard.tag_to_keys.get(tag) {
                    keys.remove(key);
                }
            }
            self.stats.lock().invalidations += 1;             // Increment invalidations counter
            true
        } else {
            false
        }
    }

    // Invalidate all keys for a tag; returns number of removed entries.
    pub fn invalidate_tag(&self, tag: &Tag) -> usize {
        let mut count = 0;
        for shard in &self.shards {                          // Scan all shards
            if let Some(keys) = shard.tag_to_keys.get(tag) {
                let keys_to_remove: Vec<Key> = keys.iter().map(|k| k.clone()).collect(); // Snapshot to avoid mutation while iterating
                for key in keys_to_remove {                   // Remove each key
                    if shard.entries.remove(&key).is_some() {
                        count += 1;
                    }
                }
                keys.clear();                                 // Clear the tag's set after removals
            }
        }
        self.stats.lock().invalidations += count as u64;      // Record count
        count
    }

    // Sweep pass: remove all expired entries (lazy removal also happens on get()).
    pub fn cleanup_expired(&self) -> usize {
        let mut count = 0;
        for shard in &self.shards {                 // Visit each shard
            let mut to_remove = Vec::new();         // Collect keys to remove (avoid holding ref across mutation)
            for entry in shard.entries.iter() {     // Iterate all entries in shard (read guards)
                if entry.value().is_expired() {     // Check expiration
                    to_remove.push(entry.key().clone());
                }
            }
            for key in to_remove {                  // Remove expired ones
                if let Some((_, entry)) = shard.entries.remove(&key) {
                    for tag in &entry.tags {        // Clean reverse mappings
                        if let Some(keys) = shard.tag_to_keys.get(tag) {
                            keys.remove(&key);
                        }
                    }
                    count += 1;
                }
            }
        }
        count
    }

    // Snapshot statistics (cheap clone of small struct).
    pub fn get_stats(&self) -> CacheStats {
        self.stats.lock().clone()
    }

    pub fn flush_all(&self) -> usize { // Remove ALL entries and tag indexes; return number removed
        let mut total = 0;
        for shard in &self.shards {
            total += shard.entries.len();
            shard.entries.clear();
            shard.tag_to_keys.clear();
        }
        self.stats.lock().invalidations += total as u64;
        total
    }
}

// =============================
// HTTP REQUEST / RESPONSE TYPES
// =============================
// Structures below represent shapes of JSON payloads. serde derives (Deserialize/Serialize) generate code.
#[derive(Deserialize)]
pub struct PutRequest { // Input for /put
    pub key: String,
    pub value: String,
    pub tags: Vec<String>,
    pub ttl_seconds: Option<u64>, // Alternative TTL unit
    pub ttl_ms: Option<u64>,      // Preferred millisecond TTL
}

#[derive(Serialize)]
pub struct PutResponse { // Output of /put
    pub ok: bool,
    pub ttl_ms: Option<u64>,
}

#[derive(Serialize)]
pub struct GetResponse { // Could be used if we wanted a typed success response
    pub value: String,
}

#[derive(Deserialize)]
pub struct KeysByTagQuery { // Query parameters (?tag=...&limit=...)
    pub tag: String,
    pub limit: Option<usize>,
}

#[derive(Serialize)]
pub struct KeysByTagResponse { // Response for keys-by-tag
    pub keys: Vec<String>,
}

#[derive(Deserialize)]
pub struct InvalidateKeyRequest { // Body for /invalidate-key
    pub key: String,
}

#[derive(Deserialize)]
pub struct InvalidateTagRequest { // Body for /invalidate-tag
    pub tag: String,
}

#[derive(Serialize)]
pub struct InvalidateResponse { // Shared invalidation response
    pub success: bool,
    pub count: Option<usize>, // Present for tag invalidation
}

#[derive(Serialize, Clone)]
pub struct StatsResponse { // /stats output (extended)
    pub hits: u64,
    pub misses: u64,
    pub puts: u64,
    pub invalidations: u64,
    pub hit_ratio: f64,
    pub items: usize,
    pub bytes: usize,
    pub tags: usize,
    pub shard_count: usize,
    pub shard_items: Vec<usize>,   // length = shard_count
    pub shard_bytes: Vec<usize>,   // length = shard_count
}

// RESTful key endpoints types
#[derive(Deserialize)]
pub struct KeyUpsertBody {
    pub value: serde_json::Value,
    pub ttl_ms: Option<u64>,
    pub tags: Option<Vec<String>>, // optional to allow updating value only
}

#[derive(Serialize)]
pub struct KeyMetadataResponse {
    pub key: String,
    pub value: serde_json::Value,
    pub ttl_ms: Option<u64>, // remaining ttl
    pub tags: Vec<String>,
}

#[derive(Deserialize, Default)]
pub struct SearchBody {
    pub q: Option<String>,
    pub tag_any: Option<Vec<String>>,
    pub tag_all: Option<Vec<String>>,
    pub limit: Option<usize>,
}

#[derive(Serialize)]
pub struct SearchResultItem { pub key: String, pub ttl_ms: Option<u64>, pub tags: Vec<String>, pub created_ms: Option<u64> }
#[derive(Serialize)]
pub struct SearchResult { pub keys: Vec<SearchResultItem> }

#[derive(Deserialize)]
pub struct InvalidateTagsBody { pub tags: Vec<String>, pub mode: Option<String> }
#[derive(Deserialize)]
pub struct InvalidateKeysBody { pub keys: Vec<String> }

#[derive(Deserialize)]
pub struct BulkKeysBody { pub keys: Vec<String> }

#[derive(Serialize)]
pub struct BulkGetItem { pub key: String, pub value: serde_json::Value, pub ttl_ms: Option<u64>, pub tags: Vec<String>, pub created_ms: Option<u64> }

// =============================
// HTTP HANDLERS
// Each handler is async and receives shared state via Axum's State extractor.
// =============================
async fn put_handler(State(state): State<Arc<AppState>>, _auth: Authenticated, Json(req): Json<PutRequest>) -> ResponseJson<PutResponse> {
    let key = Key(req.key);
    let tags = req.tags.into_iter().map(Tag).collect();
    let ttl = req.ttl_ms.map(Duration::from_millis).or_else(|| req.ttl_seconds.map(Duration::from_secs));
    let ttl_ms_return = ttl.map(|d| d.as_millis() as u64);
    state.cache.put(key, req.value, tags, ttl);
    ResponseJson(PutResponse { ok: true, ttl_ms: ttl_ms_return })
}

// GET handler returns either {value: ...} or {error: "not_found"}
async fn get_handler(State(state): State<Arc<AppState>>, _auth: Authenticated, Path(key): Path<String>) -> ResponseJson<serde_json::Value> {
    let key = Key(key);
    if let Some(value) = state.cache.get(&key) { ResponseJson(serde_json::json!({"value": value})) } else { ResponseJson(serde_json::json!({"error": "not_found"})) }
}

// List keys associated with a tag.
async fn keys_by_tag_handler(State(state): State<Arc<AppState>>, _auth: Authenticated, Query(query): Query<KeysByTagQuery>) -> ResponseJson<KeysByTagResponse> {
    let tag = Tag(query.tag);
    let mut keys = state.cache.get_keys_by_tag(&tag).into_iter().map(|k| k.0).collect::<Vec<_>>();
    if let Some(limit) = query.limit { if keys.len() > limit { keys.truncate(limit); } }
    ResponseJson(KeysByTagResponse { keys })
}

// Invalidate single key.
async fn invalidate_key_handler(State(state): State<Arc<AppState>>, _auth: Authenticated, Json(req): Json<InvalidateKeyRequest>) -> ResponseJson<InvalidateResponse> {
    let key = Key(req.key);
    let success = state.cache.invalidate_key(&key);
    ResponseJson(InvalidateResponse { success, count: None })
}

// Invalidate all keys with a tag.
async fn invalidate_tag_handler(State(state): State<Arc<AppState>>, _auth: Authenticated, Json(req): Json<InvalidateTagRequest>) -> ResponseJson<InvalidateResponse> {
    let tag = Tag(req.tag);
    let count = state.cache.invalidate_tag(&tag);
    ResponseJson(InvalidateResponse { success: count > 0, count: Some(count) })
}

// Flush all keys (dangerous – no auth layer here)
async fn flush_handler(State(state): State<Arc<AppState>>, _auth: Authenticated) -> ResponseJson<InvalidateResponse> { let count = state.cache.flush_all(); ResponseJson(InvalidateResponse { success: true, count: Some(count) }) }

// Return stats snapshot.
async fn stats_handler(State(state): State<Arc<AppState>>) -> ResponseJson<StatsResponse> {
    let stats = state.cache.get_stats();
    let hit_ratio = if stats.hits + stats.misses > 0 {            // Avoid divide by zero
        stats.hits as f64 / (stats.hits + stats.misses) as f64
    } else { 0.0 };
    // aggregate item & byte counts
    let mut items = 0usize;
    let mut bytes = 0usize;
    let mut tag_set: std::collections::HashSet<String> = std::collections::HashSet::new();
    let mut shard_items_vec = Vec::with_capacity(state.cache.shards.len());
    let mut shard_bytes_vec = Vec::with_capacity(state.cache.shards.len());
    for shard in &state.cache.shards {
        let si = shard.entries.len();
        let mut sb = 0usize;
        for e in shard.entries.iter() { sb += e.value().value.len(); }
        shard_items_vec.push(si);
        shard_bytes_vec.push(sb);
        items += si;
        bytes += sb;
        for t in shard.tag_to_keys.iter() { tag_set.insert(t.key().0.clone()); }
    }
    ResponseJson(StatsResponse {                                   // Build JSON struct
        hits: stats.hits,
        misses: stats.misses,
        puts: stats.puts,
        invalidations: stats.invalidations,
        hit_ratio,
        items,
        bytes,
        tags: tag_set.len(),
        shard_count: state.cache.shards.len(),
        shard_items: shard_items_vec,
        shard_bytes: shard_bytes_vec,
    })
}

// =============================
// REST: GET /keys/:key -> metadata
// =============================
async fn rest_get_key(State(state): State<Arc<AppState>>, _auth: Authenticated, Path(key): Path<String>) -> ResponseJson<serde_json::Value> {
    let key_wrap = Key(key.clone());
    let shard_idx = state.cache.hash_key(&key_wrap);
    let shard = &state.cache.shards[shard_idx];
    if let Some(entry) = shard.entries.get(&key_wrap) {
        if entry.is_expired() { shard.entries.remove(&key_wrap); return ResponseJson(serde_json::json!({"error":"not_found"})); }
        let remaining = entry.ttl.map(|ttl| {
            let elapsed = entry.created_at.elapsed();
            if elapsed >= ttl { 0 } else { (ttl - elapsed).as_millis() as u64 }
        });
        // try parse JSON value
    let parsed = serde_json::from_str::<serde_json::Value>(&entry.value).unwrap_or(serde_json::Value::String(entry.value.clone()));
    let tags: Vec<String> = entry.tags.iter().map(|t| t.0.clone()).collect();
    let created_ms = entry.created_system.duration_since(UNIX_EPOCH).ok().map(|d| d.as_millis() as u64);
    return ResponseJson(serde_json::json!({"key": key_wrap.0, "value": parsed, "ttl_ms": remaining, "tags": tags, "created_ms": created_ms}));
    }
    ResponseJson(serde_json::json!({"error":"not_found"}))
}

// PUT /keys/:key
async fn rest_put_key(State(state): State<Arc<AppState>>, _auth: Authenticated, Path(key): Path<String>, Json(body): Json<KeyUpsertBody>) -> ResponseJson<serde_json::Value> {
    let ttl = body.ttl_ms.map(Duration::from_millis);
    let tags_vec = body.tags.unwrap_or_default().into_iter().map(Tag).collect::<Vec<_>>();
    // store string representation
    let value_str = if body.value.is_string() { body.value.as_str().unwrap().to_string() } else { body.value.to_string() };
    state.cache.put(Key(key.clone()), value_str, tags_vec, ttl);
    ResponseJson(serde_json::json!({"ok":true,"ttl_ms": ttl.map(|d| d.as_millis() as u64)}))
}

// DELETE /keys/:key
async fn rest_delete_key(State(state): State<Arc<AppState>>, _auth: Authenticated, Path(key): Path<String>) -> ResponseJson<serde_json::Value> {
    let removed = state.cache.invalidate_key(&Key(key));
    ResponseJson(serde_json::json!({"ok": removed, "deleted": if removed {1} else {0}}))
}

// POST /keys/bulk/get { keys: [] }
async fn bulk_get_handler(State(state): State<Arc<AppState>>, _auth: Authenticated, Json(body): Json<BulkKeysBody>) -> ResponseJson<serde_json::Value> {
    let mut items: Vec<BulkGetItem> = Vec::with_capacity(body.keys.len());
    for k in body.keys {
        let key_wrap = Key(k.clone());
        let shard_idx = state.cache.hash_key(&key_wrap);
        let shard = &state.cache.shards[shard_idx];
        if let Some(entry) = shard.entries.get(&key_wrap) {
            if entry.is_expired() { shard.entries.remove(&key_wrap); continue; }
            let remaining = entry.ttl.map(|ttl| {
                let elapsed = entry.created_at.elapsed();
                if elapsed >= ttl { 0 } else { (ttl - elapsed).as_millis() as u64 }
            });
            let parsed = serde_json::from_str::<serde_json::Value>(&entry.value).unwrap_or(serde_json::Value::String(entry.value.clone()));
            let tags: Vec<String> = entry.tags.iter().map(|t| t.0.clone()).collect();
            let created_ms = entry.created_system.duration_since(UNIX_EPOCH).ok().map(|d| d.as_millis() as u64);
            items.push(BulkGetItem { key: key_wrap.0.clone(), value: parsed, ttl_ms: remaining, tags, created_ms });
        }
    }
    ResponseJson(serde_json::json!({"items": items}))
}

// POST /keys/bulk/delete { keys: [] }
async fn bulk_delete_handler(State(state): State<Arc<AppState>>, _auth: Authenticated, Json(body): Json<BulkKeysBody>) -> ResponseJson<serde_json::Value> {
    let mut count = 0usize;
    for k in body.keys { if state.cache.invalidate_key(&Key(k)) { count += 1; } }
    ResponseJson(serde_json::json!({"success": true, "count": count}))
}

// POST /search
async fn search_handler(State(state): State<Arc<AppState>>, _auth: Authenticated, Json(body): Json<SearchBody>) -> ResponseJson<SearchResult> {
    let mut results: Vec<SearchResultItem> = Vec::new();
    let limit = body.limit.unwrap_or(100);
    // Build tag sets for all/all semantics
    if body.tag_all.as_ref().map(|v| !v.is_empty()).unwrap_or(false) {
        // Intersection of keys across all tags
        let tag_objs: Vec<Tag> = body.tag_all.clone().unwrap().into_iter().map(Tag).collect();
    let key_counts: dashmap::DashMap<String, usize> = dashmap::DashMap::new();
        for tag in &tag_objs {
            let keys = state.cache.get_keys_by_tag(tag);
            for k in keys { key_counts.entry(k.0).and_modify(|c| *c+=1).or_insert(1); }
        }
        for kv in key_counts.iter() {
            if *kv.value() == tag_objs.len() { // present in all
                if results.len() >= limit { break; }
                // fetch metadata
                if let Some(meta) = fetch_meta_simple(&state.cache, &kv.key()) { results.push(meta); }
            }
        }
    } else if body.tag_any.as_ref().map(|v| !v.is_empty()).unwrap_or(false) {
        let mut seen = std::collections::HashSet::new();
    for t in body.tag_any.clone().unwrap() { if results.len()>=limit { break; } let keys = state.cache.get_keys_by_tag(&Tag(t)); for k in keys { if seen.insert(k.0.clone()) { if let Some(meta) = fetch_meta_simple(&state.cache, &k.0) { results.push(meta); if results.len()>=limit { break; } } } } }
    } else if let Some(q) = body.q.clone() {
        let qlower = q.to_string();
    for shard in &state.cache.shards {
            for entry in shard.entries.iter() {
                let kref = &entry.key().0;
                if kref.starts_with(&qlower) {
            if let Some(meta) = fetch_meta_simple(&state.cache, kref) { results.push(meta); }
                    if results.len()>=limit { break; }
                }
            }
            if results.len()>=limit { break; }
        }
    } else { // enumerate newest first across ALL shards (previously stopped after first shard hit limit)
        for shard in &state.cache.shards {
            for entry in shard.entries.iter() {
                if entry.value().is_expired() { continue; }
                if let Some(meta) = fetch_meta_simple(&state.cache, &entry.key().0) { results.push(meta); }
            }
        }
        // Sort newest first then enforce limit
        results.sort_by(|a,b| b.created_ms.cmp(&a.created_ms));
        if results.len() > limit { results.truncate(limit); }
    }
    ResponseJson(SearchResult { keys: results })
}

fn fetch_meta_simple(cache: &Cache, key_str: &str) -> Option<SearchResultItem> {
    let key = Key(key_str.to_string());
    let shard_idx = cache.hash_key(&key);
    let shard = &cache.shards[shard_idx];
    if let Some(entry) = shard.entries.get(&key) {
        if entry.is_expired() { return None; }
        let remaining = entry.ttl.map(|ttl| {
            let elapsed = entry.created_at.elapsed();
            if elapsed >= ttl { 0 } else { (ttl - elapsed).as_millis() as u64 }
        });
    let tags = entry.tags.iter().map(|t| t.0.clone()).collect();
    let created_ms = entry.created_system.duration_since(UNIX_EPOCH).ok().map(|d| d.as_millis() as u64);
    return Some(SearchResultItem { key: key_str.to_string(), ttl_ms: remaining, tags, created_ms });
    }
    None
}

// POST /invalidate/tags
async fn invalidate_tags_handler(State(state): State<Arc<AppState>>, _auth: Authenticated, Json(body): Json<InvalidateTagsBody>) -> ResponseJson<serde_json::Value> {
    let mode = body.mode.unwrap_or_else(|| "any".to_string());
    let mut count = 0usize;
    if mode == "any" { for t in body.tags { count += state.cache.invalidate_tag(&Tag(t)); } }
    else { // all: collect keys having all tags
        let tags: Vec<Tag> = body.tags.into_iter().map(Tag).collect();
        if !tags.is_empty() {
            let first_keys = state.cache.get_keys_by_tag(&tags[0]);
            for k in first_keys {
                let shard_idx = state.cache.hash_key(&k);
                let shard = &state.cache.shards[shard_idx];
                if let Some(entry) = shard.entries.get(&k) {
                    let tagset: std::collections::HashSet<_> = entry.tags.iter().map(|t| &t.0).collect();
                    if tags.iter().all(|t| tagset.contains(&t.0)) { if state.cache.invalidate_key(&k) { count+=1; } }
                }
            }
        }
    }
    ResponseJson(serde_json::json!({"success": true, "count": count}))
}

// POST /invalidate/keys
async fn invalidate_keys_handler(State(state): State<Arc<AppState>>, _auth: Authenticated, Json(body): Json<InvalidateKeysBody>) -> ResponseJson<serde_json::Value> {
    let mut count = 0usize;
    for k in body.keys { if state.cache.invalidate_key(&Key(k)) { count+=1; } }
    ResponseJson(serde_json::json!({"success": true, "count": count}))
}

// AUTH handlers
async fn login_handler(State(state): State<Arc<AppState>>, Json(body): Json<LoginBody>) -> (StatusCode, ResponseJson<serde_json::Value>) {
    if state.auth.validate_basic(&body.username, &body.password) { let token = state.auth.issue_token(); return (StatusCode::OK, ResponseJson(serde_json::json!({"token": token, "expires_in": 3600 }))); }
    (StatusCode::UNAUTHORIZED, ResponseJson(serde_json::json!({"error":"invalid_credentials"})))
}

async fn rotate_handler(State(state): State<Arc<AppState>>, _authd: Authenticated) -> ResponseJson<RotateResponse> { let new = state.auth.rotate();
    // Write file
    if let Err(e) = write_credentials_file(&new) { eprintln!("credential rotate write error: {e}"); }
    ResponseJson(RotateResponse { ok: true, username: new.username, password: new.password }) }

async fn setup_required_handler() -> ResponseJson<SetupRequired> {
    let need = !FsPath::new("credential.txt").exists();
    ResponseJson(SetupRequired { setup_required: need })
}

// Build the Axum HTTP router configuration.
pub fn build_app(app_state: Arc<AppState>, allowed_origin: Option<String>) -> Router {
    let router = Router::new()
        // Each route maps path + method to handler. State cloned into each closure.
        .route("/put", post(put_handler))
        .route("/get/:key", get(get_handler))
        .route("/keys-by-tag", get(keys_by_tag_handler))
        .route("/invalidate-key", post(invalidate_key_handler))
        .route("/invalidate-tag", post(invalidate_tag_handler))
        .route("/flush", post(flush_handler))
        .route("/stats", get(stats_handler))
    // New RESTful routes
    .route("/keys/:key", get(rest_get_key).put(rest_put_key).delete(rest_delete_key))
    .route("/search", post(search_handler))
    .route("/keys", get(list_keys_handler))
    .route("/invalidate/tags", post(invalidate_tags_handler))
    .route("/invalidate/keys", post(invalidate_keys_handler))
    .route("/keys/bulk/get", post(bulk_get_handler))
    .route("/keys/bulk/delete", post(bulk_delete_handler))
        // auth endpoints
        .route("/auth/login", post(login_handler))
        .route("/auth/rotate", post(rotate_handler))
    .route("/auth/setup_required", get(setup_required_handler))
    .route("/health", get(health_handler))
    .with_state(app_state.clone());

    // CORS: allow specified origin or fallback * (dev). Allow auth headers.
    let cors = if let Some(origin) = allowed_origin { CorsLayer::very_permissive().allow_origin(origin.parse::<axum::http::HeaderValue>().unwrap()) } else { CorsLayer::very_permissive() };
    router.layer(cors)
}

async fn health_handler() -> ResponseJson<serde_json::Value> { ResponseJson(serde_json::json!({"status":"ok","time": chrono::Utc::now().to_rfc3339()})) }

fn load_or_create_credentials() -> anyhow::Result<Credentials> {
    let path = FsPath::new("credential.txt");
    if path.exists() {
        let content = fs::read_to_string(path)?;
        let mut map = HashMap::new();
        for line in content.lines() { if let Some((k,v)) = line.split_once('=') { map.insert(k.trim().to_string(), v.trim().to_string()); } }
        let username = map.get("username").cloned().ok_or_else(|| anyhow::anyhow!("username missing"))?;
        let password = map.get("password").cloned().ok_or_else(|| anyhow::anyhow!("password missing"))?;
        return Ok(Credentials { username, password });
    }
    let creds = Credentials {
        username: rand::thread_rng().sample_iter(&Alphanumeric).take(16).map(char::from).collect(),
        password: rand::thread_rng().sample_iter(&Alphanumeric).take(24).map(char::from).collect()
    };
    write_credentials_file(&creds)?;
    Ok(creds)
}

fn write_credentials_file(creds: &Credentials) -> anyhow::Result<()> {
    let mut file = fs::File::create("credential.txt")?;
    #[cfg(unix)] {
        use std::os::unix::fs::PermissionsExt; let mut perms = file.metadata()?.permissions(); perms.set_mode(0o600); fs::set_permissions("credential.txt", perms)?;
    }
    let now = chrono::Utc::now().to_rfc3339();
    write!(file, "username={}\npassword={}\ncreated_at={}\nversion=1\n", creds.username, creds.password, now)?;
    Ok(())
}

// Lightweight listing of all keys with metadata (newest first)
async fn list_keys_handler(State(state): State<Arc<AppState>>, _auth: Authenticated) -> ResponseJson<serde_json::Value> {
    let mut out: Vec<serde_json::Value> = Vec::new();
    for shard in &state.cache.shards {
        for e in shard.entries.iter() {
            if e.value().is_expired() { continue; }
            let ttl_ms = e.value().ttl.map(|ttl| {
                let elapsed = e.value().created_at.elapsed();
                if elapsed >= ttl { 0 } else { (ttl - elapsed).as_millis() as u64 }
            });
            let created_ms = e.value().created_system.duration_since(UNIX_EPOCH).ok().map(|d| d.as_millis() as u64);
            let tags: Vec<String> = e.value().tags.iter().map(|t| t.0.clone()).collect();
            out.push(serde_json::json!({
                "key": e.key().0,
                "size": e.value().value.len(),
                "ttl": ttl_ms,
                "tags": tags,
                "created_ms": created_ms
            }));
        }
    }
    out.sort_by(|a,b| b.get("created_ms").and_then(|v| v.as_u64()).cmp(&a.get("created_ms").and_then(|v| v.as_u64())));
    ResponseJson(serde_json::json!({"keys": out}))
}

// =============================
// TCP PROTOCOL IMPLEMENTATION
// Custom lightweight line protocol for lower overhead than HTTP/JSON.
// =============================
async fn handle_tcp_client(cache: Arc<Cache>, mut stream: TcpStream) {
    let peer = stream.peer_addr().ok();                 // Capture peer address (optional)
    let (r, mut w) = stream.split();                    // Split into read and write halves (independent borrowing)
    let mut reader = BufReader::new(r);                 // Buffer reads line-by-line
    let mut line = String::new();                       // Reusable line buffer
    while let Ok(n) = reader.read_line(&mut line).await { // Async read until newline (includes trailing \n)
        if n == 0 { break; }                            // EOF => client disconnected
        while line.ends_with(['\n','\r']) { line.pop(); } // Strip CR/LF
        if line.is_empty() { continue; }                // Ignore empty lines
        let mut parts = line.splitn(5, '\t');          // Split into at most 5 segments by TAB
        let cmd = parts.next().unwrap_or("").to_ascii_uppercase(); // Command verb (case-insensitive)
        // Match command and produce a response string.
        let resp = match cmd.as_str() {
            // PUT <key> <ttl_ms|- > <tag1,tag2|- > <value>
            "PUT" => {
                let maybe_key = parts.next();
                match maybe_key {
                    Some(k) if !k.is_empty() => {                   // Validate non-empty key
                        let ttl_part = parts.next().unwrap_or("-"); // TTL field
                        let tags_part = parts.next().unwrap_or("-"); // Tags list
                        let value = parts.next().unwrap_or("");      // Remaining value (may contain spaces, not tabs)
                        let ttl = if ttl_part == "-" || ttl_part.is_empty() { None } else { ttl_part.parse::<u64>().ok().map(Duration::from_millis) };
                        let tags: Vec<Tag> = if tags_part == "-" || tags_part.is_empty() { Vec::new() } else { tags_part.split(',').filter(|s| !s.is_empty()).map(|s| Tag(s.to_string())).collect() };
                        cache.put(Key(k.to_string()), value.to_string(), tags, ttl); // Store entry
                        "OK".to_string()
                    }
                    _ => "ERR missing_key".to_string()
                }
            }
            // GET <key>
            "GET" => {
                let key = parts.next();
                match key { Some(k) => {
                    match cache.get(&Key(k.to_string())) { Some(v) => format!("VALUE\t{}", v), None => "NF".to_string() }
                }, None => "ERR missing_key".to_string() }
            }
            // DEL <key>
            "DEL" => {
                let key = parts.next();
                match key { Some(k) => { if cache.invalidate_key(&Key(k.to_string())) { "DEL ok".to_string() } else { "DEL nf".to_string() } }, None => "ERR missing_key".to_string() }
            }
            // INV_TAG <tag>
            "INV_TAG" => {
                let tag = parts.next();
                match tag { Some(t) => { let count = cache.invalidate_tag(&Tag(t.to_string())); format!("INV_TAG\t{}", count) }, None => "ERR missing_tag".to_string() }
            }
            // KEYS_BY_TAG <tag>  (alias KEYS <tag>)
            "KEYS_BY_TAG" | "KEYS" => {
                let tag = parts.next();
                match tag { Some(t) => { let keys = cache.get_keys_by_tag(&Tag(t.to_string())); let list = keys.into_iter().map(|k| k.0).collect::<Vec<_>>().join(","); format!("KEYS\t{}", list) }, None => "ERR missing_tag".to_string() }
            }
            // STATS => summary counters
            "STATS" => {
                let s = cache.get_stats();
                let hit_ratio = if s.hits + s.misses > 0 { s.hits as f64 / (s.hits + s.misses) as f64 } else { 0.0 };
                format!("STATS\t{}\t{}\t{}\t{}\t{:.6}", s.hits, s.misses, s.puts, s.invalidations, hit_ratio)
            }
            "FLUSH" => { // Remove every entry
                let c = cache.flush_all();
                format!("FLUSH\t{}", c)
            }
            _ => "ERR unknown_command".to_string(),            // Fallback for unrecognized commands
        };
        if let Err(_) = (&mut w).write_all(resp.as_bytes()).await { break; } // Send response body
        let _ = (&mut w).write_all(b"\n").await;                          // Terminate line
        line.clear();                                                       // Reuse buffer
    }
    let _ = (&mut w).shutdown().await;             // Try to close write half cleanly
    let _ = peer;                                  // Silence unused variable (document purpose earlier)
}

// TCP accept loop: keeps running forever unless an error bubbles up.
async fn run_tcp_server(cache: Arc<Cache>, port: u16) -> anyhow::Result<()> {
    let listener = TcpListener::bind(("0.0.0.0", port)).await?; // Bind to all interfaces
    info!("TCP cache protocol listening on {}", port);          // Log startup
    loop {                                                      // Accept loop
        let (sock, _) = listener.accept().await?;               // Wait for next connection
        let c = cache.clone();                                  // Clone Arc for task
        tokio::spawn(async move {                               // Spawn independent task per client
            handle_tcp_client(c, sock).await;                   // Handle lifecycle
        });
    }
}

// =============================
// MAIN ENTRY POINT
// =============================
#[tokio::main] // Macro sets up a multi-threaded async runtime and runs this async fn as root task.
async fn main() -> anyhow::Result<()> {
    // Initialize tracing (logging). Reads RUST_LOG or default filter.
    tracing_subscriber::fmt()
        .with_env_filter(tracing_subscriber::EnvFilter::from_default_env())
        .init();

    // Small helper closure: try primary env var name first, then legacy fallback; return Option<String>.
    let fetch = |primary: &str, legacy: &str| -> Option<String> {
        if let Ok(v) = env::var(primary) { return Some(v); }
        if let Ok(v) = env::var(legacy) { println!("Using legacy env var {legacy} for {primary}"); return Some(v); }
        None
    };

    // Parse configuration with defaults; unwrap_or falls back if parse fails.
    let port: u16 = fetch("PORT", "TC_HTTP_PORT").unwrap_or_else(|| "8080".to_string()).parse().unwrap_or(8080);
    let tcp_port: u16 = fetch("TCP_PORT", "TC_TCP_PORT").unwrap_or_else(|| "1984".to_string()).parse().unwrap_or(1984);
    let num_shards: usize = fetch("NUM_SHARDS", "TC_NUM_SHARDS").unwrap_or_else(|| "16".to_string()).parse().unwrap_or(16);

    // Cleanup interval: prefer ms, else seconds, else default 60s.
    let cleanup_interval = if let Some(ms) = fetch("CLEANUP_INTERVAL_MS", "TC_SWEEP_INTERVAL_MS") {
        // Provided in ms -> convert to seconds (ceil) because we use time::interval with whole seconds.
        ms.parse::<u64>().ok().map(|v| (v.max(1)+999)/1000).unwrap_or(60)
    } else if let Some(secs) = fetch("CLEANUP_INTERVAL_SECONDS", "CLEANUP_INTERVAL_SECS") {
        secs.parse::<u64>().unwrap_or(60)
    } else { 60 };

    // Build the cache (Arc so it can be shared across tasks / threads).
    let cache = Arc::new(Cache::new(num_shards));
    let creds = load_or_create_credentials()?;
    let auth_state = Arc::new(AuthState::new(creds));
    let app_state = Arc::new(AppState { cache: cache.clone(), auth: auth_state.clone() });
    let allowed_origin = env::var("ALLOWED_ORIGIN").ok();

    // Background task: periodically sweep expired entries to free memory.
    let cleanup_cache = cache.clone();
    tokio::spawn(async move { // Spawn detached task (no join handle needed here)
        let mut interval = time::interval(Duration::from_secs(cleanup_interval));
        loop {
            interval.tick().await;                       // Wait for next tick
            let expired_count = cleanup_cache.cleanup_expired();
            if expired_count > 0 {                       // Only log if we did work
                info!("Cleaned up {} expired entries", expired_count);
            }
        }
    });

    // Launch TCP server early (independent of HTTP lifecycle). Errors logged to stderr.
    let tcp_cache = cache.clone();
    tokio::spawn(async move {
        if let Err(e) = run_tcp_server(tcp_cache, tcp_port).await { eprintln!("TCP server error: {e}"); }
    });

    // Build Axum router with all endpoints.
    let app = build_app(app_state.clone(), allowed_origin);

    // Bind TCP listener for HTTP (await returns listener only when bind succeeds).
    let listener = tokio::net::TcpListener::bind(&format!("0.0.0.0:{}", port)).await?;
    info!("TagCache HTTP port={} TCP port={} shards={} cleanup={}s", port, tcp_port, num_shards, cleanup_interval);

    // Serve HTTP forever (await until server stops via error / shutdown signal).
    axum::serve(listener, app).await?;

    Ok(()) // Return Result success
}
