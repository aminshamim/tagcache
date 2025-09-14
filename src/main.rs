/*!
 * TagCache - Lightweight, sharded, tag-aware in-memory cache server
 * 
 * Author: Md. Aminul Islam Sarker <aminshamim@gmail.com>
 * GitHub: https://github.com/aminshamim/tagcache
 * LinkedIn: https://www.linkedin.com/in/aminshamim/
 * 
 * This file contains the main server implementation with detailed comments for Rust beginners.
 * The goal of these comments is to explain: types, ownership, concurrency primitives,
 * async runtime usage, data model, and protocol handling.
 */

use axum::{routing::{get, post}, Json, Router, extract::{Path, Query, FromRequestParts, State}, response::Json as ResponseJson, http::{request::Parts, StatusCode}}; // Axum web framework imports for routing & JSON
use serde::{Deserialize, Serialize}; // Serde for (de)serialization of JSON payloads
use std::{sync::Arc, time::{Duration, Instant, SystemTime, UNIX_EPOCH}, env}; // Arc = thread-safe reference counting; time utilities; env vars
use clap::{Parser, Subcommand}; // Command line argument parsing
use reqwest; // HTTP client for CLI commands
use axum::response::{Html, IntoResponse};
use axum::http::{header, Uri};
use sysinfo::{System}; // System info for CPU monitoring

// Conditionally embed assets only if the dist folder exists
#[cfg(feature = "embed-ui")]
mod embedded_assets {
    use rust_embed::RustEmbed;
    
    #[derive(RustEmbed)]
    #[folder = "app/dist/"]
    pub struct Assets;
}

// Static file handler for the web UI
async fn static_handler(uri: Uri) -> impl IntoResponse {
    let path = uri.path().trim_start_matches('/');
    
    if path.is_empty() || path == "index.html" {
        return serve_index_html().into_response();
    }
    
    #[cfg(feature = "embed-ui")]
    {
        match embedded_assets::Assets::get(path) {
            Some(content) => {
                let mime = mime_guess::from_path(path).first_or_octet_stream();
                let headers = [
                    (header::CONTENT_TYPE, mime.as_ref()),
                    (header::CACHE_CONTROL, "public, max-age=31536000"),
                ];
                return (headers, content.data).into_response();
            }
            None => return serve_index_html().into_response(),
        }
    }
    
    #[cfg(not(feature = "embed-ui"))]
    {
        serve_index_html().into_response()
    }
}

fn serve_index_html() -> Html<std::borrow::Cow<'static, [u8]>> {
    #[cfg(feature = "embed-ui")]
    {
        match embedded_assets::Assets::get("index.html") {
            Some(content) => return Html(content.data),
            None => {}
        }
    }
    
    // Fallback UI when assets are not embedded
    let fallback_html = r#"<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TagCache Server</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 2px solid #007acc; padding-bottom: 10px; }
        .status { background: #e8f5e8; padding: 15px; border-radius: 4px; margin: 20px 0; }
        .endpoint { background: #f0f0f0; padding: 10px; margin: 10px 0; border-radius: 4px; font-family: monospace; }
        .method { color: #007acc; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 TagCache Server</h1>
        <div class="status">
            <strong>✓ Server is running!</strong><br>
            The TagCache server is operational and ready to handle requests.
        </div>
        
        <h2>API Endpoints</h2>
        <div class="endpoint"><span class="method">GET</span> /health - Health check</div>
        <div class="endpoint"><span class="method">GET</span> /stats - Server statistics (requires auth)</div>
        <div class="endpoint"><span class="method">POST</span> /put - Store key-value data (requires auth)</div>
        <div class="endpoint"><span class="method">GET</span> /get/:key - Retrieve data (requires auth)</div>
        
        <h2>Authentication</h2>
        <p>Use HTTP Basic Auth with username: <code>admin</code> and password: <code>password</code></p>
        <p>Default credentials should be changed in production!</p>
        
        <h2>TCP Protocol</h2>
        <p>High-performance TCP protocol available on port 1984</p>
        
        <h2>Documentation</h2>
        <p>Visit <a href="https://github.com/aminshamim/tagcache">github.com/aminshamim/tagcache</a> for complete documentation.</p>
    </div>
</body>
</html>"#;
    
    Html(fallback_html.as_bytes().into())
}

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

use base64::engine::general_purpose::STANDARD as B64;
use base64::Engine;
use std::path::PathBuf;
use std::fs;

// =============================
// CONFIGURATION MANAGEMENT
// =============================
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ServerConfig {
    pub http_port: u16,
    pub tcp_port: u16,
    pub num_shards: usize,
    pub cleanup_interval_seconds: u64,
    pub allowed_origin: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct AuthConfig {
    pub username: String,
    pub password: String,
    pub token_lifetime_seconds: u64,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct CacheConfig {
    pub default_ttl_seconds: u64,
    pub max_tags_per_entry: usize,
    pub max_key_length: usize,
    pub max_value_length: usize,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct LoggingConfig {
    pub level: String,
    pub format: String,
    pub file: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct PerformanceConfig {
    pub tcp_nodelay: bool,
    pub tcp_keepalive_seconds: u64,
    pub max_connections: usize,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct SecurityConfig {
    pub require_auth: bool,
    pub rate_limit_per_minute: u64,
    pub allowed_ips: Option<Vec<String>>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct TagCacheConfig {
    pub server: ServerConfig,
    pub authentication: AuthConfig,
    pub cache: CacheConfig,
    pub logging: LoggingConfig,
    pub performance: PerformanceConfig,
    pub security: SecurityConfig,
}

impl Default for TagCacheConfig {
    fn default() -> Self {
        Self {
            server: ServerConfig {
                http_port: 8080,
                tcp_port: 1984,
                num_shards: 16,
                cleanup_interval_seconds: 60,
                allowed_origin: None,
            },
            authentication: AuthConfig {
                username: "admin".to_string(),
                password: "password".to_string(),
                token_lifetime_seconds: 3600,
            },
            cache: CacheConfig {
                default_ttl_seconds: 0,
                max_tags_per_entry: 100,
                max_key_length: 1024,
                max_value_length: 1048576,
            },
            logging: LoggingConfig {
                level: "info".to_string(),
                format: "pretty".to_string(),
                file: None,
            },
            performance: PerformanceConfig {
                tcp_nodelay: true,
                tcp_keepalive_seconds: 7200,
                max_connections: 10000,
            },
            security: SecurityConfig {
                require_auth: true,
                rate_limit_per_minute: 0,
                allowed_ips: None,
            },
        }
    }
}

impl TagCacheConfig {
    /// Get the default configuration file path
    pub fn default_config_path() -> PathBuf {
        // Try current directory first, then user config dir
        let current_dir_config = PathBuf::from("tagcache.conf");
        if current_dir_config.exists() {
            return current_dir_config;
        }
        
        // Check user config directory
        if let Some(config_dir) = dirs::config_dir() {
            let user_config = config_dir.join("tagcache").join("tagcache.conf");
            if user_config.exists() {
                return user_config;
            }
        }
        
        // Default to current directory
        current_dir_config
    }

    /// Load configuration from file with fallback to defaults
    pub fn load_from_file<P: AsRef<std::path::Path>>(path: P) -> anyhow::Result<Self> {
        let path = path.as_ref();
        if !path.exists() {
            println!("Configuration file {} not found, creating with defaults", path.display());
            let default_config = Self::default();
            default_config.save_to_file(path)?;
            return Ok(default_config);
        }

        let content = fs::read_to_string(path)?;
        let mut config: TagCacheConfig = toml::from_str(&content)?;
        
        // Apply environment variable overrides
        config.apply_env_overrides();
        
        Ok(config)
    }

    /// Save configuration to file
    pub fn save_to_file<P: AsRef<std::path::Path>>(&self, path: P) -> anyhow::Result<()> {
        let path = path.as_ref();
        
        // Create parent directory if it doesn't exist
        if let Some(parent) = path.parent() {
            fs::create_dir_all(parent)?;
        }
        
        let content = toml::to_string_pretty(self)?;
        fs::write(path, content)?;
        println!("Configuration saved to {}", path.display());
        Ok(())
    }

    /// Apply environment variable overrides
    fn apply_env_overrides(&mut self) {
        // Server overrides
        if let Ok(port) = env::var("PORT").or_else(|_| env::var("TC_HTTP_PORT")) {
            if let Ok(p) = port.parse() {
                self.server.http_port = p;
            }
        }
        
        if let Ok(tcp_port) = env::var("TCP_PORT").or_else(|_| env::var("TC_TCP_PORT")) {
            if let Ok(p) = tcp_port.parse() {
                self.server.tcp_port = p;
            }
        }
        
        if let Ok(shards) = env::var("NUM_SHARDS").or_else(|_| env::var("TC_NUM_SHARDS")) {
            if let Ok(s) = shards.parse() {
                self.server.num_shards = s;
            }
        }
        
        // Auth overrides
        if let Ok(username) = env::var("TAGCACHE_USERNAME") {
            self.authentication.username = username;
        }
        
        if let Ok(password) = env::var("TAGCACHE_PASSWORD") {
            self.authentication.password = password;
        }
        
        // Other overrides
        if let Ok(origin) = env::var("ALLOWED_ORIGIN") {
            self.server.allowed_origin = Some(origin);
        }
    }

    /// Update authentication credentials and save to file
    pub fn update_auth(&mut self, username: Option<String>, password: Option<String>, config_path: &std::path::Path) -> anyhow::Result<()> {
        if let Some(u) = username {
            self.authentication.username = u;
        }
        if let Some(p) = password {
            self.authentication.password = p;
        }
        self.save_to_file(config_path)?;
        Ok(())
    }
}

// =============================
// CLI COMMAND DEFINITIONS
// =============================
#[derive(Parser)]
#[command(name = "tagcache")]
#[command(about = "TagCache - Lightweight, sharded, tag-aware in-memory cache server")]
#[command(version = env!("CARGO_PKG_VERSION"))]
struct Cli {
    #[command(subcommand)]
    command: Option<Commands>,

    /// Server host (default: localhost)
    #[arg(long, default_value = "localhost")]
    host: String,

    /// Server port (default: 8080)
    #[arg(long, short = 'p', default_value = "8080")]
    port: u16,

    /// Authentication username
    #[arg(long, short = 'u')]
    username: Option<String>,

    /// Authentication password  
    #[arg(long)]
    password: Option<String>,

    /// Authentication token
    #[arg(long, short = 't')]
    token: Option<String>,
}

#[derive(Subcommand)]
enum Commands {
    /// Start the TagCache server
    Server,
    
    /// Store a key-value pair with optional tags
    Put {
        /// The cache key
        key: String,
        /// The value to store
        value: String,
        /// Comma-separated list of tags
        #[arg(long, short)]
        tags: Option<String>,
        /// TTL in milliseconds
        #[arg(long)]
        ttl_ms: Option<u64>,
    },
    
    /// Get operations
    Get {
        #[command(subcommand)]
        get_command: GetCommands,
    },
    
    /// Flush operations  
    Flush {
        #[command(subcommand)]
        flush_command: FlushCommands,
    },
    
    /// Show server statistics
    Stats,
    
    /// Show server status/health
    Status,
    
    /// Show server health check
    Health,
    
    /// Restart server (if running as service)
    Restart,
    
    /// Change password
    ChangePassword {
        /// New password
        new_password: String,
    },
    
    /// Reset to default credentials (admin/password)
    ResetCredentials,
    
    /// Configuration management
    Config {
        #[command(subcommand)]
        config_command: ConfigCommands,
    },
}

#[derive(Subcommand)]
enum ConfigCommands {
    /// Show current configuration
    Show {
        /// Configuration file path (default: auto-detect)
        #[arg(long, short)]
        config: Option<String>,
    },
    /// Set a configuration value
    Set {
        /// Configuration key (e.g., "authentication.password")
        key: String,
        /// Configuration value
        value: String,
        /// Configuration file path (default: auto-detect)
        #[arg(long, short)]
        config: Option<String>,
    },
    /// Reset configuration to defaults
    Reset {
        /// Configuration file path (default: auto-detect)
        #[arg(long, short)]
        config: Option<String>,
    },
    /// Show configuration file path
    Path,
}

#[derive(Subcommand)]
enum GetCommands {
    /// Get value by key
    Key { key: String },
    /// Get keys by tags
    Tag { tags: String },
}

#[derive(Subcommand)]
enum FlushCommands {
    /// Flush specific key
    Key { key: String },
    /// Flush by tags
    Tag { tags: String },
    /// Flush all entries
    All,
}

// =============================
// CLI CLIENT IMPLEMENTATION
// =============================
struct TagCacheClient {
    base_url: String,
    client: reqwest::Client,
    auth_header: Option<String>,
}

impl TagCacheClient {
    fn new(host: &str, port: u16) -> Self {
        Self {
            base_url: format!("http://{}:{}", host, port),
            client: reqwest::Client::new(),
            auth_header: None,
        }
    }

    fn with_auth(mut self, username: Option<String>, password: Option<String>, token: Option<String>) -> Self {
        if let Some(token) = token {
            self.auth_header = Some(format!("Bearer {}", token));
        } else if let (Some(username), Some(password)) = (username, password) {
            let credentials = base64::engine::general_purpose::STANDARD
                .encode(format!("{}:{}", username, password));
            self.auth_header = Some(format!("Basic {}", credentials));
        }
        self
    }

    async fn put(&self, key: &str, value: &str, tags: Option<&str>, ttl_ms: Option<u64>) -> anyhow::Result<()> {
        let tags_vec: Vec<String> = if let Some(tags) = tags {
            tags.split(',').map(|s| s.trim().to_string()).collect()
        } else {
            Vec::new()
        };

        let payload = serde_json::json!({
            "key": key,
            "value": value,
            "tags": tags_vec,
            "ttl_ms": ttl_ms
        });

        let mut request = self.client.post(&format!("{}/put", self.base_url));
        if let Some(auth) = &self.auth_header {
            request = request.header("Authorization", auth);
        }

        let response = request.json(&payload).send().await?;
        
        if response.status().is_success() {
            println!("✓ Successfully stored key '{}' with value '{}'", key, value);
            if let Some(tags) = tags {
                println!("  Tags: {}", tags);
            }
            if let Some(ttl) = ttl_ms {
                println!("  TTL: {}ms", ttl);
            }
        } else {
            let error_text = response.text().await?;
            anyhow::bail!("Failed to store key: {}", error_text);
        }

        Ok(())
    }

    async fn get_key(&self, key: &str) -> anyhow::Result<()> {
        let mut request = self.client.get(&format!("{}/get/{}", self.base_url, key));
        if let Some(auth) = &self.auth_header {
            request = request.header("Authorization", auth);
        }

        let response = request.send().await?;
        
        if response.status().is_success() {
            let json: serde_json::Value = response.json().await?;
            if let Some(value) = json.get("value") {
                println!("Key: {}", key);
                println!("Value: {}", value.as_str().unwrap_or(""));
            } else if json.get("error").is_some() {
                println!("Key '{}' not found", key);
            }
        } else {
            println!("Key '{}' not found", key);
        }

        Ok(())
    }

    async fn get_keys_by_tag(&self, tags: &str) -> anyhow::Result<()> {
        let tag_list: Vec<&str> = tags.split(',').map(|s| s.trim()).collect();
        
        for tag in &tag_list {
            let mut request = self.client.get(&format!("{}/keys-by-tag?tag={}", self.base_url, tag));
            if let Some(auth) = &self.auth_header {
                request = request.header("Authorization", auth);
            }

            let response = request.send().await?;
            
            if response.status().is_success() {
                let json: serde_json::Value = response.json().await?;
                if let Some(keys) = json.get("keys").and_then(|k| k.as_array()) {
                    println!("Tag '{}' contains {} keys:", tag, keys.len());
                    for key in keys {
                        if let Some(key_str) = key.as_str() {
                            println!("  - {}", key_str);
                        }
                    }
                } else {
                    println!("Tag '{}' has no keys", tag);
                }
            } else {
                println!("Failed to get keys for tag '{}'", tag);
            }
        }

        Ok(())
    }

    async fn flush_key(&self, key: &str) -> anyhow::Result<()> {
        let payload = serde_json::json!({ "key": key });

        let mut request = self.client.post(&format!("{}/invalidate-key", self.base_url));
        if let Some(auth) = &self.auth_header {
            request = request.header("Authorization", auth);
        }

        let response = request.json(&payload).send().await?;
        
        if response.status().is_success() {
            let json: serde_json::Value = response.json().await?;
            if json.get("success").and_then(|s| s.as_bool()).unwrap_or(false) {
                println!("✓ Successfully flushed key '{}'", key);
            } else {
                println!("Key '{}' was not found", key);
            }
        } else {
            let error_text = response.text().await?;
            anyhow::bail!("Failed to flush key: {}", error_text);
        }

        Ok(())
    }

    async fn flush_tags(&self, tags: &str) -> anyhow::Result<()> {
        let tag_list: Vec<String> = tags.split(',').map(|s| s.trim().to_string()).collect();
        let payload = serde_json::json!({ "tags": tag_list, "mode": "any" });

        let mut request = self.client.post(&format!("{}/invalidate/tags", self.base_url));
        if let Some(auth) = &self.auth_header {
            request = request.header("Authorization", auth);
        }

        let response = request.json(&payload).send().await?;
        
        if response.status().is_success() {
            let json: serde_json::Value = response.json().await?;
            if let Some(count) = json.get("count").and_then(|c| c.as_u64()) {
                println!("✓ Successfully flushed {} entries with tags: {}", count, tags);
            }
        } else {
            let error_text = response.text().await?;
            anyhow::bail!("Failed to flush tags: {}", error_text);
        }

        Ok(())
    }

    async fn flush_all(&self) -> anyhow::Result<()> {
        let mut request = self.client.post(&format!("{}/flush", self.base_url));
        if let Some(auth) = &self.auth_header {
            request = request.header("Authorization", auth);
        }

        let response = request.send().await?;
        
        if response.status().is_success() {
            let json: serde_json::Value = response.json().await?;
            if let Some(count) = json.get("count").and_then(|c| c.as_u64()) {
                println!("✓ Successfully flushed all {} entries from cache", count);
            }
        } else {
            let error_text = response.text().await?;
            anyhow::bail!("Failed to flush cache: {}", error_text);
        }

        Ok(())
    }

    async fn stats(&self) -> anyhow::Result<()> {
        let mut request = self.client.get(&format!("{}/stats", self.base_url));
        if let Some(auth) = &self.auth_header {
            request = request.header("Authorization", auth);
        }

        let response = request.send().await?;
        
        if response.status().is_success() {
            let json: serde_json::Value = response.json().await?;
            
            println!("TagCache Statistics:");
            println!("==================");
            if let Some(hits) = json.get("hits") {
                println!("Hits: {}", hits);
            }
            if let Some(misses) = json.get("misses") {
                println!("Misses: {}", misses);
            }
            if let Some(puts) = json.get("puts") {
                println!("Puts: {}", puts);
            }
            if let Some(invalidations) = json.get("invalidations") {
                println!("Invalidations: {}", invalidations);
            }
            if let Some(hit_ratio) = json.get("hit_ratio") {
                println!("Hit Ratio: {:.2}%", hit_ratio.as_f64().unwrap_or(0.0) * 100.0);
            }
            if let Some(items) = json.get("items") {
                println!("Total Items: {}", items);
            }
            if let Some(bytes) = json.get("bytes") {
                println!("Total Bytes: {}", bytes);
            }
            if let Some(tags) = json.get("tags") {
                println!("Total Tags: {}", tags);
            }
            if let Some(shards) = json.get("shard_count") {
                println!("Shards: {}", shards);
            }
        } else {
            anyhow::bail!("Failed to get stats: {}", response.status());
        }

        Ok(())
    }

    async fn health(&self) -> anyhow::Result<()> {
        let response = self.client.get(&format!("{}/health", self.base_url)).send().await?;
        
        if response.status().is_success() {
            let json: serde_json::Value = response.json().await?;
            println!("Health Check: ✓ OK");
            if let Some(time) = json.get("time") {
                println!("Server Time: {}", time);
            }
        } else {
            anyhow::bail!("Health check failed: {}", response.status());
        }

        Ok(())
    }

    async fn status(&self) -> anyhow::Result<()> {
        // Try to connect to both HTTP and TCP ports
        println!("TagCache Server Status:");
        println!("=====================");
        
        // Check HTTP endpoint
        match self.client.get(&format!("{}/health", self.base_url)).send().await {
            Ok(response) if response.status().is_success() => {
                println!("HTTP Server: ✓ Running on {}", self.base_url);
            }
            _ => {
                println!("HTTP Server: ✗ Not responding on {}", self.base_url);
            }
        }
        
        // Try to get stats for more detailed status
        match self.stats().await {
            Ok(_) => {},
            Err(_) => {
                println!("Note: Could not retrieve detailed statistics (authentication may be required)");
            }
        }

        Ok(())
    }

    async fn restart(&self) -> anyhow::Result<()> {
        println!("Restart functionality depends on your deployment method:");
        println!("  • Homebrew service: brew services restart tagcache");
        println!("  • Systemd: sudo systemctl restart tagcache");
        println!("  • Docker: docker restart <container_name>");
        println!("  • Process manager: depends on your setup");
        Ok(())
    }

    async fn change_password(&self, new_password: &str) -> anyhow::Result<()> {
        let payload = serde_json::json!({ "new_password": new_password });

        let mut request = self.client.post(&format!("{}/auth/change_password", self.base_url));
        if let Some(auth) = &self.auth_header {
            request = request.header("Authorization", auth);
        }

        let response = request.json(&payload).send().await?;
        
        if response.status().is_success() {
            let json: serde_json::Value = response.json().await?;
            if json.get("success").and_then(|s| s.as_bool()).unwrap_or(false) {
                println!("✓ Password changed successfully");
                println!("The new password is: {}", new_password);
            } else {
                anyhow::bail!("Failed to change password");
            }
        } else {
            let error_text = response.text().await?;
            anyhow::bail!("Failed to change password: {}", error_text);
        }

        Ok(())
    }

    async fn reset_credentials(&self) -> anyhow::Result<()> {
        let mut request = self.client.post(&format!("{}/auth/reset", self.base_url));
        if let Some(auth) = &self.auth_header {
            request = request.header("Authorization", auth);
        }

        let response = request.send().await?;
        
        if response.status().is_success() {
            let json: serde_json::Value = response.json().await?;
            if json.get("success").and_then(|s| s.as_bool()).unwrap_or(false) {
                println!("✓ Credentials reset to defaults");
                println!("Username: admin");
                println!("Password: password");
            } else {
                anyhow::bail!("Failed to reset credentials");
            }
        } else {
            let error_text = response.text().await?;
            anyhow::bail!("Failed to reset credentials: {}", error_text);
        }

        Ok(())
    }
}

// =============================
// DATA MODEL TYPES
// =============================
// We wrap raw String keys in a newtype Key for type safety + trait impls.
#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub struct Key(String); // Simple wrapper; cloning duplicates the underlying String.

// Same idea for Tag — improves clarity and prevents mixing strings accidentally.
#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub struct Tag(String);

impl Key {
    pub fn new<S: Into<String>>(s: S) -> Self { Key(s.into()) }
    pub fn as_str(&self) -> &str { &self.0 }
}

impl Tag {
    pub fn new<S: Into<String>>(s: S) -> Self { Tag(s.into()) }
    pub fn as_str(&self) -> &str { &self.0 }
}

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
pub struct AuthState { 
    credentials: Arc<Mutex<Credentials>>, 
    tokens: DashSet<String>,
    config_path: Arc<Mutex<PathBuf>>,  // Path to configuration file for persistence
}

impl AuthState {
    fn new(creds: Credentials, config_path: PathBuf) -> Self { 
        Self { 
            credentials: Arc::new(Mutex::new(creds)), 
            tokens: DashSet::new(),
            config_path: Arc::new(Mutex::new(config_path)),
        } 
    }
    fn issue_token(&self) -> String { let token: String = rand::thread_rng().sample_iter(&Alphanumeric).take(48).map(char::from).collect(); self.tokens.insert(token.clone()); token }
    fn rotate(&self) -> Credentials { let new = Credentials { username: rand::thread_rng().sample_iter(&Alphanumeric).take(16).map(char::from).collect(), password: rand::thread_rng().sample_iter(&Alphanumeric).take(24).map(char::from).collect() }; *self.credentials.lock() = new.clone(); self.tokens.clear(); new }
    fn validate_basic(&self, u:&str, p:&str) -> bool { let c = self.credentials.lock(); c.username==u && c.password==p }
    fn validate_token(&self, t:&str) -> bool { self.tokens.contains(t) }
    
    fn change_password(&self, new_password: String) -> bool {
        let mut creds = self.credentials.lock();
        creds.password = new_password.clone();
        
        // Persist to configuration file
        if let Err(e) = self.persist_credentials_to_config(Some(creds.username.clone()), Some(new_password)) {
            eprintln!("Warning: Failed to persist password change to config file: {}", e);
            // Don't fail the operation, just warn
        }
        
        // Clear all tokens to force re-authentication
        self.tokens.clear();
        true
    }
    
    fn reset_to_defaults(&self) -> bool {
        let new = Credentials {
            username: "admin".to_string(),
            password: "password".to_string(),
        };
        *self.credentials.lock() = new.clone();
        
        // Persist to configuration file
        if let Err(e) = self.persist_credentials_to_config(Some(new.username), Some(new.password)) {
            eprintln!("Warning: Failed to persist credential reset to config file: {}", e);
            // Don't fail the operation, just warn
        }
        
        // Clear all tokens to force re-authentication
        self.tokens.clear();
        true
    }
    
    fn persist_credentials_to_config(&self, username: Option<String>, password: Option<String>) -> anyhow::Result<()> {
        let config_path = self.config_path.lock();
        let mut config = TagCacheConfig::load_from_file(&*config_path)?;
        config.update_auth(username, password, &*config_path)?;
        Ok(())
    }

}

#[derive(Clone)]
pub struct AppState { 
    pub cache: Arc<Cache>, 
    pub auth: Arc<AuthState>,
    pub system: Arc<parking_lot::Mutex<System>>, // System monitor for CPU stats
}

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
#[derive(Serialize)] struct RotateResponse { ok:bool, username:String, password:String }

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
        let old_tags = if let Some(old_entry) = shard.entries.get(&key) {
            old_entry.tags.clone()  // Clone the tags to avoid holding the read lock
        } else {
            SmallVec::new()  // No old entry, no tags to clean up
        };
        // Read lock is dropped here
        
        // Clean up old tag associations without holding entry lock
        for tag in &old_tags {
            if let Some(keys) = shard.tag_to_keys.get(tag) {
                keys.remove(&key);
                // Remove empty tag entries to prevent memory leaks
                if keys.is_empty() {
                    drop(keys);  // Drop the reference before removal
                    shard.tag_to_keys.remove(tag);
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
        
        // First, check if entry exists and get its expiration status
        let (value, is_expired) = if let Some(entry) = shard.entries.get(key) {
            if entry.is_expired() {
                (None, true)  // Entry exists but is expired
            } else {
                (Some(entry.value.clone()), false)  // Entry exists and valid
            }
        } else {
            (None, false)  // Entry doesn't exist
        };
        // The read lock is automatically dropped here when `entry` goes out of scope
        
        // Now handle expired entry removal without holding read lock
        if is_expired {
            // Safe to remove now - no lock conflict
            if let Some((_, old_entry)) = shard.entries.remove(key) {
                // Clean up tag associations for expired entry
                for tag in &old_entry.tags {
                    if let Some(tag_keys) = shard.tag_to_keys.get_mut(tag) {
                        tag_keys.remove(key);
                        // Remove empty tag entries to prevent memory leaks
                        if tag_keys.is_empty() {
                            drop(tag_keys);  // Drop the mutable reference
                            shard.tag_to_keys.remove(tag);
                        }
                    }
                }
            }
            self.stats.lock().misses += 1;
            return None;
        }
        
        // Return value if we have one
        if let Some(val) = value {
            self.stats.lock().hits += 1;
            Some(val)
        } else {
            self.stats.lock().misses += 1;
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
async fn stats_handler(State(state): State<Arc<AppState>>, _auth: Authenticated) -> ResponseJson<StatsResponse> {
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
    ResponseJson(RotateResponse { ok: true, username: new.username, password: new.password }) }

async fn change_password_handler(State(state): State<Arc<AppState>>, _authd: Authenticated, Json(body): Json<serde_json::Value>) -> ResponseJson<serde_json::Value> {
    if let Some(new_password) = body.get("new_password").and_then(|p| p.as_str()) {
        let success = state.auth.change_password(new_password.to_string());
        ResponseJson(serde_json::json!({"success": success}))
    } else {
        ResponseJson(serde_json::json!({"success": false, "error": "new_password is required"}))
    }
}

async fn reset_credentials_handler(State(state): State<Arc<AppState>>, _authd: Authenticated) -> ResponseJson<serde_json::Value> {
    let success = state.auth.reset_to_defaults();
    ResponseJson(serde_json::json!({"success": success}))
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
        .route("/auth/change_password", post(change_password_handler))
        .route("/auth/reset", post(reset_credentials_handler))
    .route("/health", get(health_handler))
    .route("/system", get(system_handler))
        // Serve the React UI for all other routes (SPA routing)
        .fallback(static_handler)
    .with_state(app_state.clone());

    // CORS: allow specified origin or fallback * (dev). Allow auth headers.
    let cors = if let Some(origin) = allowed_origin { CorsLayer::very_permissive().allow_origin(origin.parse::<axum::http::HeaderValue>().unwrap()) } else { CorsLayer::very_permissive() };
    router.layer(cors)
}

async fn health_handler() -> ResponseJson<serde_json::Value> { ResponseJson(serde_json::json!({"status":"ok","time": chrono::Utc::now().to_rfc3339()})) }

async fn system_handler(State(state): State<Arc<AppState>>) -> ResponseJson<serde_json::Value> {
    let mut system = state.system.lock();
    system.refresh_cpu(); // Refresh CPU usage
    system.refresh_memory(); // Refresh memory usage
    
    let cpu_cores: Vec<serde_json::Value> = system.cpus()
        .iter()
        .enumerate()
        .map(|(idx, cpu)| serde_json::json!({
            "core": idx,
            "name": cpu.name(),
            "usage": cpu.cpu_usage(),
            "frequency": cpu.frequency()
        }))
        .collect();
    
    // Calculate global CPU usage as average of all cores
    let global_cpu_usage = if system.cpus().is_empty() {
        0.0
    } else {
        system.cpus().iter().map(|cpu| cpu.cpu_usage()).sum::<f32>() / system.cpus().len() as f32
    };
    
    ResponseJson(serde_json::json!({
        "cpu_cores": cpu_cores,
        "core_count": system.cpus().len(),
        "global_cpu_usage": global_cpu_usage,
        "total_memory": system.total_memory(),
        "used_memory": system.used_memory(),
        "timestamp": chrono::Utc::now().to_rfc3339()
    }))
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
// CONFIG COMMAND HANDLERS
// =============================
async fn handle_config_command(config_command: ConfigCommands) -> anyhow::Result<()> {
    match config_command {
        ConfigCommands::Show { config } => {
            let config_path = config.map(PathBuf::from)
                .unwrap_or_else(|| TagCacheConfig::default_config_path());
            
            match TagCacheConfig::load_from_file(&config_path) {
                Ok(cfg) => {
                    println!("Configuration from: {}", config_path.display());
                    println!("{}", toml::to_string_pretty(&cfg)?);
                }
                Err(e) => {
                    println!("Error loading configuration: {}", e);
                    return Err(e);
                }
            }
            Ok(())
        }
        
        ConfigCommands::Set { key, value, config } => {
            let config_path = config.map(PathBuf::from)
                .unwrap_or_else(|| TagCacheConfig::default_config_path());
            
            let mut cfg = TagCacheConfig::load_from_file(&config_path)?;
            
            // Parse the key and update the config
            match set_config_value(&mut cfg, &key, &value) {
                Ok(_) => {
                    cfg.save_to_file(&config_path)?;
                    println!("✓ Configuration updated: {} = {}", key, value);
                    println!("Configuration saved to: {}", config_path.display());
                    println!("Restart the server for changes to take effect.");
                }
                Err(e) => {
                    println!("Error setting configuration: {}", e);
                    return Err(e);
                }
            }
            Ok(())
        }
        
        ConfigCommands::Reset { config } => {
            let config_path = config.map(PathBuf::from)
                .unwrap_or_else(|| TagCacheConfig::default_config_path());
            
            let default_cfg = TagCacheConfig::default();
            default_cfg.save_to_file(&config_path)?;
            println!("✓ Configuration reset to defaults");
            println!("Configuration saved to: {}", config_path.display());
            println!("Restart the server for changes to take effect.");
            Ok(())
        }
        
        ConfigCommands::Path => {
            let config_path = TagCacheConfig::default_config_path();
            println!("Configuration file path: {}", config_path.display());
            if config_path.exists() {
                println!("Status: EXISTS");
            } else {
                println!("Status: NOT FOUND (will be created with defaults on first use)");
            }
            Ok(())
        }
    }
}

fn set_config_value(config: &mut TagCacheConfig, key: &str, value: &str) -> anyhow::Result<()> {
    let parts: Vec<&str> = key.split('.').collect();
    if parts.len() != 2 {
        anyhow::bail!("Invalid config key format. Use section.key (e.g., authentication.password)");
    }
    
    let section = parts[0];
    let field = parts[1];
    
    match section {
        "server" => match field {
            "http_port" => config.server.http_port = value.parse()?,
            "tcp_port" => config.server.tcp_port = value.parse()?,
            "num_shards" => config.server.num_shards = value.parse()?,
            "cleanup_interval_seconds" => config.server.cleanup_interval_seconds = value.parse()?,
            "allowed_origin" => config.server.allowed_origin = if value.is_empty() { None } else { Some(value.to_string()) },
            _ => anyhow::bail!("Unknown server field: {}", field),
        },
        "authentication" => match field {
            "username" => config.authentication.username = value.to_string(),
            "password" => config.authentication.password = value.to_string(),
            "token_lifetime_seconds" => config.authentication.token_lifetime_seconds = value.parse()?,
            _ => anyhow::bail!("Unknown authentication field: {}", field),
        },
        "cache" => match field {
            "default_ttl_seconds" => config.cache.default_ttl_seconds = value.parse()?,
            "max_tags_per_entry" => config.cache.max_tags_per_entry = value.parse()?,
            "max_key_length" => config.cache.max_key_length = value.parse()?,
            "max_value_length" => config.cache.max_value_length = value.parse()?,
            _ => anyhow::bail!("Unknown cache field: {}", field),
        },
        "logging" => match field {
            "level" => config.logging.level = value.to_string(),
            "format" => config.logging.format = value.to_string(),
            "file" => config.logging.file = if value.is_empty() { None } else { Some(value.to_string()) },
            _ => anyhow::bail!("Unknown logging field: {}", field),
        },
        "performance" => match field {
            "tcp_nodelay" => config.performance.tcp_nodelay = value.parse()?,
            "tcp_keepalive_seconds" => config.performance.tcp_keepalive_seconds = value.parse()?,
            "max_connections" => config.performance.max_connections = value.parse()?,
            _ => anyhow::bail!("Unknown performance field: {}", field),
        },
        "security" => match field {
            "require_auth" => config.security.require_auth = value.parse()?,
            "rate_limit_per_minute" => config.security.rate_limit_per_minute = value.parse()?,
            "allowed_ips" => {
                if value.is_empty() {
                    config.security.allowed_ips = None;
                } else {
                    config.security.allowed_ips = Some(value.split(',').map(|s| s.trim().to_string()).collect());
                }
            },
            _ => anyhow::bail!("Unknown security field: {}", field),
        },
        _ => anyhow::bail!("Unknown config section: {}", section),
    }
    
    Ok(())
}

// =============================
// MAIN ENTRY POINT
// =============================
#[tokio::main] // Macro sets up a multi-threaded async runtime and runs this async fn as root task.
async fn main() -> anyhow::Result<()> {
    let cli = Cli::parse();
    
    match cli.command {
        Some(Commands::Server) | None => {
            // Start server mode (default behavior)
            start_server().await
        }
        Some(cmd) => {
            // Handle CLI commands
            let client = TagCacheClient::new(&cli.host, cli.port)
                .with_auth(cli.username, cli.password, cli.token);
            
            match cmd {
                Commands::Put { key, value, tags, ttl_ms } => {
                    client.put(&key, &value, tags.as_deref(), ttl_ms).await
                }
                Commands::Get { get_command } => {
                    match get_command {
                        GetCommands::Key { key } => client.get_key(&key).await,
                        GetCommands::Tag { tags } => client.get_keys_by_tag(&tags).await,
                    }
                }
                Commands::Flush { flush_command } => {
                    match flush_command {
                        FlushCommands::Key { key } => client.flush_key(&key).await,
                        FlushCommands::Tag { tags } => client.flush_tags(&tags).await,
                        FlushCommands::All => client.flush_all().await,
                    }
                }
                Commands::Stats => client.stats().await,
                Commands::Status => client.status().await,
                Commands::Health => client.health().await,
                Commands::Restart => client.restart().await,
                Commands::ChangePassword { new_password } => client.change_password(&new_password).await,
                Commands::ResetCredentials => client.reset_credentials().await,
                Commands::Config { config_command } => {
                    handle_config_command(config_command).await
                }
                Commands::Server => unreachable!(), // Already handled above
            }
        }
    }
}

// =============================
// SERVER IMPLEMENTATION
// =============================
async fn start_server() -> anyhow::Result<()> {
    // Initialize tracing (logging). Reads RUST_LOG or default filter.
    tracing_subscriber::fmt()
        .with_env_filter(tracing_subscriber::EnvFilter::from_default_env())
        .init();

    // Load configuration from file
    let config_path = TagCacheConfig::default_config_path();
    let config = TagCacheConfig::load_from_file(&config_path)?;
    
    println!("TagCache Server starting...");
    println!("Configuration loaded from: {}", config_path.display());

    // Build the cache (Arc so it can be shared across tasks / threads).
    let cache = Arc::new(Cache::new(config.server.num_shards));
    
    // Use credentials from configuration file
    let auth_creds = Credentials {
        username: config.authentication.username.clone(),
        password: config.authentication.password.clone(),
    };
    let auth_state = Arc::new(AuthState::new(auth_creds, config_path.clone()));
    
    // Initialize system monitor for CPU stats
    let mut system = System::new_all();
    system.refresh_all(); // Initial refresh
    let system_monitor = Arc::new(parking_lot::Mutex::new(system));
    
    let app_state = Arc::new(AppState { 
        cache: cache.clone(), 
        auth: auth_state.clone(),
        system: system_monitor 
    });

    // Background task: periodically sweep expired entries to free memory.
    let cleanup_cache = cache.clone();
    tokio::spawn(async move { // Spawn detached task (no join handle needed here)
        let mut interval = time::interval(Duration::from_secs(config.server.cleanup_interval_seconds));
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
        if let Err(e) = run_tcp_server(tcp_cache, config.server.tcp_port).await { eprintln!("TCP server error: {e}"); }
    });

    // Build Axum router with all endpoints.
    let app = build_app(app_state.clone(), config.server.allowed_origin.clone());

    // Bind TCP listener for HTTP (await returns listener only when bind succeeds).
    let listener = tokio::net::TcpListener::bind(&format!("0.0.0.0:{}", config.server.http_port)).await?;
    info!("TagCache HTTP port={} TCP port={} shards={} cleanup={}s", 
          config.server.http_port, config.server.tcp_port, config.server.num_shards, config.server.cleanup_interval_seconds);

    // Serve HTTP forever (await until server stops via error / shutdown signal).
    axum::serve(listener, app).await?;

    Ok(()) // Return Result success
}
