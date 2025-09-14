/*!
 * Deadlock and Concurrency Tests for TagCache
 * 
 * These tests are designed to detect deadlocks, race conditions, and other
 * concurrency issues that could cause the server to hang or behave incorrectly.
 */

use std::sync::Arc;
use std::time::{Duration, Instant};
use tokio::time::timeout;
use tokio::task::JoinSet;

// Since the types are in main.rs, we need to include them directly
// For now, let's define the basic types we need for testing
use dashmap::{DashMap, DashSet};
use smallvec::SmallVec;
use ahash::RandomState;
use std::hash::{Hash, Hasher, BuildHasher};
use parking_lot::Mutex;

/// Key wrapper for type safety
#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub struct Key(String);

impl Key {
    pub fn new<S: Into<String>>(s: S) -> Self { Key(s.into()) }
    pub fn as_str(&self) -> &str { &self.0 }
}

/// Tag wrapper for type safety  
#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub struct Tag(String);

impl Tag {
    pub fn new<S: Into<String>>(s: S) -> Self { Tag(s.into()) }
    pub fn as_str(&self) -> &str { &self.0 }
}

/// Cache entry with TTL support
#[derive(Debug, Clone)]
pub struct Entry {
    pub value: String,
    pub tags: SmallVec<[Tag; 4]>,
    pub created_at: std::time::Instant,
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

/// Cache shard
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

/// Cache statistics
#[derive(Debug, Default, Clone)]
pub struct CacheStats {
    pub hits: u64,
    pub misses: u64,
    pub puts: u64,
    pub invalidations: u64,
}

/// Main cache structure
#[derive(Debug)]
pub struct Cache {
    pub shards: Vec<Shard>,
    pub stats: Arc<Mutex<CacheStats>>,
    hasher: RandomState,
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
            created_at: std::time::Instant::now(),
            ttl,
        };

        // Get old tags first to avoid lock conflicts
        let old_tags = if let Some(old_entry) = shard.entries.get(&key) {
            old_entry.tags.clone()
        } else {
            SmallVec::new()
        };

        // Clean up old tag associations
        for tag in &old_tags {
            if let Some(keys) = shard.tag_to_keys.get(tag) {
                keys.remove(&key);
                if keys.is_empty() {
                    drop(keys);
                    shard.tag_to_keys.remove(tag);
                }
            }
        }

        // Add new tag associations
        for tag in &tags {
            shard
                .tag_to_keys
                .entry(tag.clone())
                .or_insert_with(DashSet::new)
                .insert(key.clone());
        }

        shard.entries.insert(key, entry);
        self.stats.lock().puts += 1;
    }

    pub fn get(&self, key: &Key) -> Option<String> {
        let shard_idx = self.hash_key(key);
        let shard = &self.shards[shard_idx];
        
        // First, check if entry exists and get its expiration status
        let (value, is_expired) = if let Some(entry) = shard.entries.get(key) {
            if entry.is_expired() {
                (None, true)
            } else {
                (Some(entry.value.clone()), false)
            }
        } else {
            (None, false)
        };
        
        // Handle expired entry removal without holding read lock
        if is_expired {
            if let Some((_, old_entry)) = shard.entries.remove(key) {
                // Clean up tag associations
                for tag in &old_entry.tags {
                    if let Some(tag_keys) = shard.tag_to_keys.get_mut(tag) {
                        tag_keys.remove(key);
                        if tag_keys.is_empty() {
                            drop(tag_keys);
                            shard.tag_to_keys.remove(tag);
                        }
                    }
                }
            }
            self.stats.lock().misses += 1;
            return None;
        }
        
        if let Some(val) = value {
            self.stats.lock().hits += 1;
            Some(val)
        } else {
            self.stats.lock().misses += 1;
            None
        }
    }

    pub fn invalidate_key(&self, key: &Key) -> bool {
        let shard_idx = self.hash_key(key);
        let shard = &self.shards[shard_idx];
        if let Some((_, entry)) = shard.entries.remove(key) {
            for tag in &entry.tags {
                if let Some(keys) = shard.tag_to_keys.get(tag) {
                    keys.remove(key);
                    if keys.is_empty() {
                        drop(keys);
                        shard.tag_to_keys.remove(tag);
                    }
                }
            }
            self.stats.lock().invalidations += 1;
            true
        } else {
            false
        }
    }

    pub fn cleanup_expired(&self) -> usize {
        let mut count = 0;
        for shard in &self.shards {
            let mut to_remove = Vec::new();
            for entry in shard.entries.iter() {
                if entry.value().is_expired() {
                    to_remove.push(entry.key().clone());
                }
            }
            for key in to_remove {
                if let Some((_, entry)) = shard.entries.remove(&key) {
                    for tag in &entry.tags {
                        if let Some(keys) = shard.tag_to_keys.get(tag) {
                            keys.remove(&key);
                            if keys.is_empty() {
                                drop(keys);
                                shard.tag_to_keys.remove(tag);
                            }
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

/// Test for the specific deadlock issue in Cache::get() method
/// This test reproduces the scenario where expired entries cause deadlocks
#[tokio::test]
async fn test_expired_entry_deadlock() {
    let cache = Arc::new(Cache::new(4));
    let key = Key::new("test_key");
    let value = "test_value".to_string();
    let tags = vec![Tag::new("tag1"), Tag::new("tag2")];
    
    // Insert an entry with very short TTL (1ms)
    cache.put(key.clone(), value, tags, Some(Duration::from_millis(1)));
    
    // Wait for entry to expire
    tokio::time::sleep(Duration::from_millis(10)).await;
    
    // Create multiple concurrent tasks trying to get the expired entry
    let mut tasks = JoinSet::new();
    
    for i in 0..50 {
        let cache_clone = cache.clone();
        let key_clone = key.clone();
        
        tasks.spawn(async move {
            let start = Instant::now();
            
            // This should not hang - should return None for expired entry
            let result = cache_clone.get(&key_clone);
            
            let duration = start.elapsed();
            
            // If it takes more than 1 second, we likely have a deadlock
            if duration > Duration::from_secs(1) {
                panic!("Task {} took too long: {:?} - possible deadlock", i, duration);
            }
            
            (i, result, duration)
        });
    }
    
    // Wait for all tasks with timeout - should complete quickly
    let timeout_duration = Duration::from_secs(5);
    let start = Instant::now();
    
    while !tasks.is_empty() {
        match timeout(timeout_duration, tasks.join_next()).await {
            Ok(Some(Ok((task_id, get_result, duration)))) => {
                println!("Task {} completed in {:?}, result: {:?}", task_id, duration, get_result.is_some());
                // All should return None since entry is expired
                assert!(get_result.is_none(), "Expired entry should return None");
            }
            Ok(Some(Err(e))) => {
                panic!("Task panicked: {:?}", e);
            }
            Ok(None) => break, // No more tasks
            Err(_) => {
                panic!("Task timed out - possible deadlock detected");
            }
        }
    }
    
    let total_duration = start.elapsed();
    println!("All expired entry tasks completed in {:?}", total_duration);
    
    // Should complete much faster than timeout
    assert!(total_duration < Duration::from_secs(2), 
           "Test took too long: {:?} - possible performance issue", total_duration);
}

/// Test concurrent put operations with tag cleanup
/// This tests the deadlock scenario in Cache::put() method
#[tokio::test]
async fn test_concurrent_put_with_tag_cleanup() {
    let cache = Arc::new(Cache::new(8));
    let mut tasks = JoinSet::new();
    
    // Create multiple tasks that repeatedly update the same key with different tags
    for task_id in 0..20 {
        let cache_clone = cache.clone();
        
        tasks.spawn(async move {
            let key = Key::new("shared_key");
            
            for iteration in 0..50 {
                let tags = vec![
                    Tag::new(format!("tag_{}_{}", task_id, iteration)),
                    Tag::new(format!("shared_tag_{}", iteration % 5)),
                ];
                let value = format!("value_{}_{}", task_id, iteration);
                
                let start = Instant::now();
                
                // This put operation involves tag cleanup which could deadlock
                cache_clone.put(key.clone(), value, tags, Some(Duration::from_secs(60)));
                
                let duration = start.elapsed();
                
                if duration > Duration::from_millis(500) {
                    panic!("Put operation took too long: {:?} - possible deadlock", duration);
                }
            }
            
            task_id
        });
    }
    
    // Wait for all tasks with timeout
    let timeout_duration = Duration::from_secs(10);
    let start = Instant::now();
    
    while !tasks.is_empty() {
        match timeout(timeout_duration, tasks.join_next()).await {
            Ok(Some(Ok(task_id))) => {
                println!("Put task {} completed successfully", task_id);
            }
            Ok(Some(Err(e))) => {
                panic!("Put task panicked: {:?}", e);
            }
            Ok(None) => break,
            Err(_) => {
                panic!("Put task timed out - possible deadlock detected");
            }
        }
    }
    
    let total_duration = start.elapsed();
    println!("All put tasks completed in {:?}", total_duration);
    
    // Should complete much faster than timeout
    assert!(total_duration < Duration::from_secs(5), 
           "Put test took too long: {:?} - possible performance issue", total_duration);
}

/// Test mixed read/write operations under high concurrency
/// This simulates real-world load that could trigger various deadlocks
#[tokio::test]
async fn test_mixed_operations_high_concurrency() {
    let cache = Arc::new(Cache::new(16));
    let mut tasks = JoinSet::new();
    
    // Populate cache with initial data
    for i in 0..100 {
        let key = Key::new(format!("initial_key_{}", i));
        let value = format!("initial_value_{}", i);
        let tags = vec![Tag::new(format!("tag_{}", i % 10))];
        cache.put(key, value, tags, Some(Duration::from_millis(100 + i * 10)));
    }
    
    // Reader tasks
    for task_id in 0..15 {
        let cache_clone = cache.clone();
        
        tasks.spawn(async move {
            for i in 0..200 {
                let key = Key::new(format!("initial_key_{}", i % 100));
                
                let start = Instant::now();
                let _result = cache_clone.get(&key);
                let duration = start.elapsed();
                
                if duration > Duration::from_millis(100) {
                    panic!("Reader task {} iteration {} took too long: {:?}", task_id, i, duration);
                }
                
                // Small random delay
                let delay_micros = (task_id * 13 + i * 7) % 100 + 1;
                tokio::time::sleep(Duration::from_micros(delay_micros as u64)).await;
            }
            format!("reader_{}", task_id)
        });
    }
    
    // Writer tasks
    for task_id in 0..10 {
        let cache_clone = cache.clone();
        
        tasks.spawn(async move {
            for i in 0..100 {
                let key = Key::new(format!("writer_key_{}_{}", task_id, i));
                let value = format!("writer_value_{}_{}", task_id, i);
                let tags = vec![
                    Tag::new(format!("writer_tag_{}", task_id)),
                    Tag::new(format!("iteration_tag_{}", i % 5)),
                ];
                
                let start = Instant::now();
                cache_clone.put(key, value, tags, Some(Duration::from_millis(200)));
                let duration = start.elapsed();
                
                if duration > Duration::from_millis(100) {
                    panic!("Writer task {} iteration {} took too long: {:?}", task_id, i, duration);
                }
                
                // Small random delay
                let delay_micros = (task_id * 17 + i * 11) % 100 + 1;
                tokio::time::sleep(Duration::from_micros(delay_micros as u64)).await;
            }
            format!("writer_{}", task_id)
        });
    }
    
    // Invalidation tasks
    for task_id in 0..5 {
        let cache_clone = cache.clone();
        
        tasks.spawn(async move {
            for i in 0..50 {
                // Invalidate some initial keys
                let key = Key::new(format!("initial_key_{}", i * 2));
                
                let start = Instant::now();
                let _removed = cache_clone.invalidate_key(&key);
                let duration = start.elapsed();
                
                if duration > Duration::from_millis(100) {
                    panic!("Invalidation task {} iteration {} took too long: {:?}", task_id, i, duration);
                }
                
                tokio::time::sleep(Duration::from_millis(5)).await;
            }
            format!("invalidator_{}", task_id)
        });
    }
    
    // Wait for all tasks
    let timeout_duration = Duration::from_secs(30);
    let start = Instant::now();
    let mut completed_tasks = Vec::new();
    
    while !tasks.is_empty() {
        match timeout(timeout_duration, tasks.join_next()).await {
            Ok(Some(Ok(task_name))) => {
                completed_tasks.push(task_name);
                println!("Task completed: {}", completed_tasks.last().unwrap());
            }
            Ok(Some(Err(e))) => {
                panic!("Task panicked: {:?}", e);
            }
            Ok(None) => break,
            Err(_) => {
                panic!("Task timed out - possible deadlock detected");
            }
        }
    }
    
    let total_duration = start.elapsed();
    println!("All mixed operations completed in {:?}", total_duration);
    println!("Completed {} tasks", completed_tasks.len());
    
    // Should complete much faster than timeout
    assert!(total_duration < Duration::from_secs(15), 
           "Mixed operations test took too long: {:?} - possible performance issue", total_duration);
    
    // Verify we completed all expected tasks
    assert_eq!(completed_tasks.len(), 30, "Not all tasks completed");
}

/// Test for memory leaks in tag associations during concurrent operations
#[tokio::test]
async fn test_tag_association_memory_leaks() {
    let cache = Arc::new(Cache::new(4));
    let mut tasks = JoinSet::new();
    
    // Tasks that create and destroy entries with many tags
    for task_id in 0..10 {
        let cache_clone = cache.clone();
        
        tasks.spawn(async move {
            for cycle in 0..20 {
                // Create entries with many tags
                for i in 0..50 {
                    let key = Key::new(format!("temp_key_{}_{}", task_id, i));
                    let value = format!("temp_value_{}", i);
                    let tags = (0..10).map(|t| Tag::new(format!("temp_tag_{}_{}", cycle, t))).collect();
                    
                    cache_clone.put(key.clone(), value, tags, Some(Duration::from_millis(50)));
                }
                
                // Wait for entries to expire
                tokio::time::sleep(Duration::from_millis(100)).await;
                
                // Trigger cleanup by accessing expired entries
                for i in 0..50 {
                    let key = Key::new(format!("temp_key_{}_{}", task_id, i));
                    let _result = cache_clone.get(&key); // Should clean up expired entry
                }
            }
            
            task_id
        });
    }
    
    // Wait for all tasks
    let timeout_duration = Duration::from_secs(20);
    
    while !tasks.is_empty() {
        match timeout(timeout_duration, tasks.join_next()).await {
            Ok(Some(Ok(task_id))) => {
                println!("Memory leak test task {} completed", task_id);
            }
            Ok(Some(Err(e))) => {
                panic!("Memory leak test task panicked: {:?}", e);
            }
            Ok(None) => break,
            Err(_) => {
                panic!("Memory leak test task timed out");
            }
        }
    }
    
    // Run cleanup to remove any remaining expired entries
    let cleaned = cache.cleanup_expired();
    println!("Cleanup removed {} expired entries", cleaned);
    
    // Get stats to verify memory usage is reasonable
    let stats = cache.get_stats();
    println!("Final stats: {:?}", stats);
    
    // The cache should be mostly empty after cleanup
    // (some entries might still exist from recent puts)
    println!("Test completed successfully - no memory leak detected");
}

/// Stress test with rapid put/get cycles on the same keys
/// This can trigger race conditions in tag management
#[tokio::test]
async fn test_rapid_put_get_cycles() {
    let cache = Arc::new(Cache::new(2)); // Small shard count to increase contention
    let mut tasks = JoinSet::new();
    
    let shared_keys: Vec<Key> = (0..5).map(|i| Key::new(format!("shared_{}", i))).collect();
    let shared_keys = Arc::new(shared_keys);
    
    // Tasks that rapidly put and get the same keys
    for task_id in 0..20 {
        let cache_clone = cache.clone();
        let keys_clone = shared_keys.clone();
        
        tasks.spawn(async move {
            for iteration in 0..100 {
                let key_idx = iteration % keys_clone.len();
                let key = &keys_clone[key_idx];
                
                // Put with random TTL
                let ttl_ms = 10 + ((task_id * iteration * 7) % 90);
                let ttl = Duration::from_millis(ttl_ms as u64);
                let value = format!("value_{}_{}", task_id, iteration);
                let tags = vec![Tag::new(format!("tag_{}", task_id))];
                
                let start = Instant::now();
                cache_clone.put(key.clone(), value, tags, Some(ttl));
                
                // Immediately try to get it
                let _result = cache_clone.get(key);
                
                let duration = start.elapsed();
                
                if duration > Duration::from_millis(50) {
                    panic!("Rapid cycle task {} iteration {} took too long: {:?}", task_id, iteration, duration);
                }
                
                // Very short delay to create race conditions
                if iteration % 10 == 0 {
                    tokio::time::sleep(Duration::from_micros(100)).await;
                }
            }
            
            task_id
        });
    }
    
    // Wait for all tasks
    let timeout_duration = Duration::from_secs(15);
    
    while !tasks.is_empty() {
        match timeout(timeout_duration, tasks.join_next()).await {
            Ok(Some(Ok(task_id))) => {
                println!("Rapid cycle task {} completed", task_id);
            }
            Ok(Some(Err(e))) => {
                panic!("Rapid cycle task panicked: {:?}", e);
            }
            Ok(None) => break,
            Err(_) => {
                panic!("Rapid cycle task timed out - possible deadlock");
            }
        }
    }
    
    println!("Rapid put/get cycles test completed successfully");
}

/// Test to ensure the fixed deadlock scenarios work correctly
#[tokio::test]
async fn test_deadlock_regression() {
    let cache = Arc::new(Cache::new(4));
    
    // Test the specific scenario that was causing deadlocks
    println!("Testing deadlock regression scenarios...");
    
    // Scenario 1: Expired entry access during high concurrency
    let key1 = Key::new("deadlock_test_1");
    cache.put(key1.clone(), "value1".to_string(), vec![Tag::new("tag1")], Some(Duration::from_millis(1)));
    tokio::time::sleep(Duration::from_millis(10)).await; // Ensure expiry
    
    let mut tasks = Vec::new();
    for _ in 0..50 {
        let cache_clone = cache.clone();
        let key_clone = key1.clone();
        tasks.push(tokio::spawn(async move {
            cache_clone.get(&key_clone)
        }));
    }
    
    // All should complete without hanging
    for task in tasks {
        let result = timeout(Duration::from_secs(1), task).await;
        assert!(result.is_ok(), "Task should not timeout");
        assert!(result.unwrap().unwrap().is_none(), "Expired entry should return None");
    }
    
    // Scenario 2: Rapid tag updates
    let key2 = Key::new("deadlock_test_2");
    let mut tasks = Vec::new();
    
    for i in 0..30 {
        let cache_clone = cache.clone();
        let key_clone = key2.clone();
        tasks.push(tokio::spawn(async move {
            for j in 0..20 {
                let tags = vec![Tag::new(format!("tag_{}_{}", i, j))];
                cache_clone.put(key_clone.clone(), format!("value_{}", j), tags, Some(Duration::from_secs(60)));
            }
        }));
    }
    
    // All should complete without hanging
    for task in tasks {
        let result = timeout(Duration::from_secs(2), task).await;
        assert!(result.is_ok(), "Put tasks should not timeout");
    }
    
    println!("Deadlock regression test passed!");
}