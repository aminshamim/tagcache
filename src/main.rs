// abridged for readability; full logic is in your ZIP
use axum::{routing::{get, post}, Json, Router, extract::{Path, Query}, response::Json as ResponseJson, http::StatusCode};
use serde::{Deserialize, Serialize};
use std::{sync::Arc, time::{Duration, Instant}, env};
use dashmap::{DashMap, DashSet};
use smallvec::SmallVec;
use tokio::time;
use ahash::{RandomState};
use std::hash::{Hash, Hasher};
use tower_http::cors::{CorsLayer, Any};
use tracing::{info};
use parking_lot::Mutex;

// Key, Tag, Entry structs
#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub struct Key(String);

#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub struct Tag(String);

#[derive(Debug, Clone)]
pub struct Entry {
    pub value: String,
    pub tags: SmallVec<[Tag; 4]>,
    pub created_at: Instant,
    pub ttl: Option<Duration>,
}

impl Entry {
    pub fn is_expired(&self) -> bool {
        if let Some(ttl) = self.ttl {
            self.created_at.elapsed() > ttl
        } else {
            false
        }
    }
}

// Shard with DashMaps
#[derive(Debug)]
pub struct Shard {
    pub entries: DashMap<Key, Entry>,
    pub tag_to_keys: DashMap<Tag, DashSet<Key>>,
}

impl Shard {
    pub fn new() -> Self {
        Self {
            entries: DashMap::new(),
            tag_to_keys: DashMap::new(),
        }
    }
}

// Cache with shards, sweeper, counters
#[derive(Debug)]
pub struct Cache {
    pub shards: Vec<Shard>,
    pub stats: Arc<Mutex<CacheStats>>,
    hasher: RandomState,
}

#[derive(Debug, Default, Clone)]
pub struct CacheStats {
    pub hits: u64,
    pub misses: u64,
    pub puts: u64,
    pub invalidations: u64,
}

impl Cache {
    pub fn new(num_shards: usize) -> Self {
        assert!(num_shards > 0, "num_shards must be > 0");
        let mut shards = Vec::with_capacity(num_shards);
        for _ in 0..num_shards {
            shards.push(Shard::new());
        }

        Self {
            shards,
            stats: Arc::new(Mutex::new(CacheStats::default())),
            hasher: RandomState::new(),
        }
    }

    fn hash_key(&self, key: &Key) -> usize {
        let mut hasher = self.hasher.build_hasher();
        key.hash(&mut hasher);
        (hasher.finish() as usize) % self.shards.len()
    }

    pub fn put(&self, key: Key, value: String, tags: Vec<Tag>, ttl: Option<Duration>) {
        let shard_idx = self.hash_key(&key);
        let shard = &self.shards[shard_idx];

        let entry = Entry {
            value,
            tags: SmallVec::from_vec(tags.clone()),
            created_at: Instant::now(),
            ttl,
        };

        // Remove old tag associations if key exists
        if let Some(old_entry) = shard.entries.get(&key) {
            for tag in &old_entry.tags {
                if let Some(keys) = shard.tag_to_keys.get(tag) {
                    keys.remove(&key);
                }
            }
        }

        // Add new tag associations
        for tag in &tags {
            shard.tag_to_keys.entry(tag.clone()).or_insert_with(DashSet::new).insert(key.clone());
        }

        shard.entries.insert(key, entry);
        self.stats.lock().puts += 1;
    }

    pub fn get(&self, key: &Key) -> Option<String> {
        let shard_idx = self.hash_key(key);
        let shard = &self.shards[shard_idx];

        if let Some(entry) = shard.entries.get(key) {
            if entry.is_expired() {
                shard.entries.remove(key);
                self.stats.lock().misses += 1;
                None
            } else {
                self.stats.lock().hits += 1;
                Some(entry.value.clone())
            }
        } else {
            self.stats.lock().misses += 1;
            None
        }
    }

    pub fn get_keys_by_tag(&self, tag: &Tag) -> Vec<Key> {
        let mut result = Vec::new();
        for shard in &self.shards {
            if let Some(keys) = shard.tag_to_keys.get(tag) {
                for key in keys.iter() {
                    if let Some(entry) = shard.entries.get(&key) {
                        if !entry.is_expired() {
                            result.push(key.clone());
                        }
                    }
                }
            }
        }
        result
    }

    pub fn invalidate_key(&self, key: &Key) -> bool {
        let shard_idx = self.hash_key(key);
        let shard = &self.shards[shard_idx];

        if let Some((_, entry)) = shard.entries.remove(key) {
            // Remove tag associations
            for tag in &entry.tags {
                if let Some(keys) = shard.tag_to_keys.get(tag) {
                    keys.remove(key);
                }
            }
            self.stats.lock().invalidations += 1;
            true
        } else {
            false
        }
    }

    pub fn invalidate_tag(&self, tag: &Tag) -> usize {
        let mut count = 0;
        for shard in &self.shards {
            if let Some(keys) = shard.tag_to_keys.get(tag) {
                let keys_to_remove: Vec<Key> = keys.iter().map(|k| k.clone()).collect();
                for key in keys_to_remove {
                    if shard.entries.remove(&key).is_some() {
                        count += 1;
                    }
                }
                keys.clear();
            }
        }
        self.stats.lock().invalidations += count as u64;
        count
    }

    pub fn cleanup_expired(&self) -> usize {
        let mut count = 0;
        for shard in &self.shards {
            let mut keys_to_remove = Vec::new();

            for entry in shard.entries.iter() {
                if entry.value().is_expired() {
                    keys_to_remove.push(entry.key().clone());
                }
            }

            for key in keys_to_remove {
                if let Some((_, entry)) = shard.entries.remove(&key) {
                    for tag in &entry.tags {
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

    pub fn get_stats(&self) -> CacheStats {
        self.stats.lock().clone()
    }
}

// Request/Response structs
#[derive(Deserialize)]
pub struct PutRequest {
    pub key: String,
    pub value: String,
    pub tags: Vec<String>,
    pub ttl_seconds: Option<u64>,
    pub ttl_ms: Option<u64>,
}

#[derive(Serialize)]
pub struct PutResponse {
    pub ok: bool,
    pub ttl_ms: Option<u64>,
}

#[derive(Serialize)]
pub struct GetResponse {
    pub value: String,
}

#[derive(Deserialize)]
pub struct KeysByTagQuery {
    pub tag: String,
    pub limit: Option<usize>,
}

#[derive(Serialize)]
pub struct KeysByTagResponse {
    pub keys: Vec<String>,
}

#[derive(Deserialize)]
pub struct InvalidateKeyRequest {
    pub key: String,
}

#[derive(Deserialize)]
pub struct InvalidateTagRequest {
    pub tag: String,
}

#[derive(Serialize)]
pub struct InvalidateResponse {
    pub success: bool,
    pub count: Option<usize>,
}

#[derive(Serialize, Clone)]
pub struct StatsResponse {
    pub hits: u64,
    pub misses: u64,
    pub puts: u64,
    pub invalidations: u64,
    pub hit_ratio: f64,
}

// Endpoints:
async fn put_handler(
    axum::extract::State(cache): axum::extract::State<Arc<Cache>>,
    Json(req): Json<PutRequest>,
) -> ResponseJson<PutResponse> {
    let key = Key(req.key);
    let tags = req.tags.into_iter().map(Tag).collect();
    let ttl = req.ttl_ms.map(|ms| Duration::from_millis(ms))
        .or_else(|| req.ttl_seconds.map(|s| Duration::from_secs(s)));
    let ttl_ms_return = ttl.map(|d| d.as_millis() as u64);
    cache.put(key, req.value, tags, ttl);
    ResponseJson(PutResponse { ok: true, ttl_ms: ttl_ms_return })
}

async fn get_handler(
    axum::extract::State(cache): axum::extract::State<Arc<Cache>>,
    Path(key): Path<String>,
) -> ResponseJson<serde_json::Value> {
    let key = Key(key);
    if let Some(value) = cache.get(&key) {
        ResponseJson(serde_json::json!({"value": value}))
    } else {
        ResponseJson(serde_json::json!({"error": "not_found"}))
    }
}

async fn keys_by_tag_handler(
    axum::extract::State(cache): axum::extract::State<Arc<Cache>>,
    Query(query): Query<KeysByTagQuery>,
) -> ResponseJson<KeysByTagResponse> {
    let tag = Tag(query.tag);
    let mut keys = cache.get_keys_by_tag(&tag).into_iter().map(|k| k.0).collect::<Vec<_>>();
    if let Some(limit) = query.limit { if keys.len() > limit { keys.truncate(limit); } }
    ResponseJson(KeysByTagResponse { keys })
}

async fn invalidate_key_handler(
    axum::extract::State(cache): axum::extract::State<Arc<Cache>>,
    Json(req): Json<InvalidateKeyRequest>,
) -> ResponseJson<InvalidateResponse> {
    let key = Key(req.key);
    let success = cache.invalidate_key(&key);
    ResponseJson(InvalidateResponse { success, count: None })
}

async fn invalidate_tag_handler(
    axum::extract::State(cache): axum::extract::State<Arc<Cache>>,
    Json(req): Json<InvalidateTagRequest>,
) -> ResponseJson<InvalidateResponse> {
    let tag = Tag(req.tag);
    let count = cache.invalidate_tag(&tag);
    ResponseJson(InvalidateResponse { success: count > 0, count: Some(count) })
}

async fn stats_handler(
    axum::extract::State(cache): axum::extract::State<Arc<Cache>>,
) -> ResponseJson<StatsResponse> {
    let stats = cache.get_stats();
    let hit_ratio = if stats.hits + stats.misses > 0 {
        stats.hits as f64 / (stats.hits + stats.misses) as f64
    } else {
        0.0
    };

    ResponseJson(StatsResponse {
        hits: stats.hits,
        misses: stats.misses,
        puts: stats.puts,
        invalidations: stats.invalidations,
        hit_ratio,
    })
}

// helper to build app (for tests)
pub fn build_app(cache: Arc<Cache>) -> Router {
    Router::new()
        .route("/put", post(put_handler))
        .route("/get/:key", get(get_handler))
        .route("/keys-by-tag", get(keys_by_tag_handler))
        .route("/invalidate-key", post(invalidate_key_handler))
        .route("/invalidate-tag", post(invalidate_tag_handler))
        .route("/stats", get(stats_handler))
        .layer(CorsLayer::new().allow_origin(Any))
        .with_state(cache)
}

#[tokio::main]
async fn main() -> anyhow::Result<()> {
    // tracing setup + env vars
    tracing_subscriber::fmt()
        .with_env_filter(tracing_subscriber::EnvFilter::from_default_env())
        .init();

    let port = env::var("PORT")
        .unwrap_or_else(|_| "8080".to_string())
        .parse::<u16>()
        .unwrap_or(8080);

    let num_shards = env::var("NUM_SHARDS")
        .unwrap_or_else(|_| "16".to_string())
        .parse::<usize>()
        .unwrap_or(16);

    let cleanup_interval = env::var("CLEANUP_INTERVAL_SECONDS")
        .unwrap_or_else(|_| "60".to_string())
        .parse::<u64>()
        .unwrap_or(60);

    // build cache + sweeper
    let cache = Arc::new(Cache::new(num_shards));

    // Start background cleanup task
    let cleanup_cache = cache.clone();
    tokio::spawn(async move {
        let mut interval = time::interval(Duration::from_secs(cleanup_interval));
        loop {
            interval.tick().await;
            let expired_count = cleanup_cache.cleanup_expired();
            if expired_count > 0 {
                info!("Cleaned up {} expired entries", expired_count);
            }
        }
    });

    // build Axum routes
    let app = build_app(cache);

    // start server
    let listener = tokio::net::TcpListener::bind(&format!("0.0.0.0:{}", port)).await?;
    info!("TagCache server starting on port {}", port);
    info!("Using {} shards, cleanup interval: {}s", num_shards, cleanup_interval);

    axum::serve(listener, app).await?;

    Ok(())
}
