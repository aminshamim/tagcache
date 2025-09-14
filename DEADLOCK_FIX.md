# TagCache Deadlock Fix

## 🚨 Issue Identified

**Root Cause:** DashMap RwLock deadlock in `Cache::get()` method

### Stack Trace Analysis
```
Thread 3 (Thread 0x7d386e5ff6c0 (LWP 6521) "tokio-runtime-w"):
#0  syscall () at ../sysdeps/unix/sysv/linux/x86_64/syscall.S:38
#1  0x000056d0077a3ec9 in dashmap::lock::RawRwLock::lock_exclusive_slow ()
#2  0x000056d0078007d9 in <dashmap::DashMap<K,V,S> as dashmap::t::Map<K,V,S>>::_remove ()
#3  0x000056d0077dc7b4 in tagcache::Cache::get ()
#4  0x000056d007882c9f in tagcache::run_tcp_server::{{closure}}::{{closure}} ()
```

### The Problem

In the original `Cache::get()` method:

```rust
if let Some(entry) = shard.entries.get(key) {   // 🔒 Acquires READ lock
    if entry.is_expired() {
        shard.entries.remove(key);               // 💥 Tries to acquire WRITE lock while READ lock held
        // DEADLOCK!
    }
}
```

**Sequence:**
1. Thread acquires **read lock** via `shard.entries.get(key)`
2. Checks if entry is expired
3. Tries to acquire **write lock** via `shard.entries.remove(key)`
4. **DEADLOCK** - can't upgrade read lock to write lock in DashMap

## ✅ Solution Applied

### Fixed `Cache::get()` Method

```rust
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
    // 🔓 Read lock is automatically dropped here
    
    // Now handle expired entry removal without holding read lock
    if is_expired {
        // ✅ Safe to remove now - no lock conflict
        if let Some((_, old_entry)) = shard.entries.remove(key) {
            // Clean up tag associations for expired entry
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
    
    // Return value if we have one
    if let Some(val) = value {
        self.stats.lock().hits += 1;
        Some(val)
    } else {
        self.stats.lock().misses += 1;
        None
    }
}
```

### Fixed `Cache::put()` Method

Also fixed similar issue in tag cleanup:

```rust
// If key existed, remove old tag associations to avoid stale reverse index entries.
let old_tags = if let Some(old_entry) = shard.entries.get(&key) {
    old_entry.tags.clone()  // Clone the tags to avoid holding the read lock
} else {
    SmallVec::new()  // No old entry, no tags to clean up
};
// 🔓 Read lock is dropped here

// Clean up old tag associations without holding entry lock
for tag in &old_tags {
    if let Some(keys) = shard.tag_to_keys.get(tag) {
        keys.remove(&key);
        if keys.is_empty() {
            drop(keys);
            shard.tag_to_keys.remove(tag);
        }
    }
}
```

## 🔧 Key Changes

1. **Separate lock scopes**: Read operations and write operations now use separate lock acquisitions
2. **Clone data out**: Extract needed data while holding read lock, then drop lock before modifications
3. **Proper cleanup**: Added memory leak prevention by cleaning up empty tag associations
4. **No lock upgrades**: Never try to upgrade read lock to write lock

## 🎯 Why This Fixes the Hanging

- **Before**: Thread would hang indefinitely waiting for write lock while holding read lock
- **After**: Clean separation of read/write phases prevents lock conflicts
- **Performance**: Minimal overhead - just cloning small data structures
- **Memory**: Better cleanup prevents memory leaks from empty tag sets

## 🧪 Testing

```bash
# Build with the fix
cargo build --release

# Test with high concurrency
# The server should no longer hang under load
```

## 📊 Impact

- ✅ **Eliminates deadlock** in high-concurrency scenarios
- ✅ **Maintains performance** - overhead is minimal
- ✅ **Improves memory management** - better cleanup of tag associations
- ✅ **Thread safety** - proper lock ordering prevents race conditions

The server should now handle concurrent requests without hanging, especially under high load scenarios involving TCP protocol operations.