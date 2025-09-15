#include "tagcache.h"
#include <php_ini.h>
#include <ext/standard/info.h>
#include <ext/standard/php_smart_string.h>
#include <zend_smart_str.h>
#include <ext/standard/php_var.h>
#include <ext/standard/base64.h>
#include <sys/time.h>
#include <sys/types.h>
#include <sys/socket.h>
#include <netinet/tcp.h>
#include <arpa/inet.h>
#include <netdb.h>
#include <unistd.h>
#include <fcntl.h>

// Feature detection for optional serializers
#ifdef HAVE_IGBINARY
#include "ext/igbinary/igbinary.h"
#endif

#ifdef HAVE_MSGPACK  
#include "ext/msgpack/msgpack.h"
#endif

static zend_class_entry *tagcache_ce; // class entry for OO API

#include <errno.h>

int le_tagcache_client; // resource id

// Forward declarations of PHP functions
PHP_FUNCTION(tagcache_create);
PHP_FUNCTION(tagcache_put);
PHP_FUNCTION(tagcache_get);
PHP_FUNCTION(tagcache_delete);
PHP_FUNCTION(tagcache_invalidate_tag);
PHP_FUNCTION(tagcache_invalidate_tags_any);
PHP_FUNCTION(tagcache_invalidate_tags_all);
PHP_FUNCTION(tagcache_invalidate_keys);
PHP_FUNCTION(tagcache_keys_by_tag);
PHP_FUNCTION(tagcache_bulk_get);
PHP_FUNCTION(tagcache_bulk_put);
PHP_FUNCTION(tagcache_stats);
PHP_FUNCTION(tagcache_flush);
PHP_FUNCTION(tagcache_search_any);
PHP_FUNCTION(tagcache_search_all);
PHP_FUNCTION(tagcache_close);

// Argument info
ZEND_BEGIN_ARG_INFO_EX(arginfo_tagcache_create, 0, 0, 0)
    ZEND_ARG_ARRAY_INFO(0, options, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_tagcache_delete, 0, 0, 2)
    ZEND_ARG_INFO(0, handle)
    ZEND_ARG_TYPE_INFO(0, key, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_tagcache_invalidate_tag, 0, 0, 2)
    ZEND_ARG_INFO(0, handle)
    ZEND_ARG_TYPE_INFO(0, tag, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_tagcache_invalidate_tags, 0, 0, 2)
    ZEND_ARG_INFO(0, handle)
    ZEND_ARG_ARRAY_INFO(0, tags, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_tagcache_invalidate_keys, 0, 0, 2)
    ZEND_ARG_INFO(0, handle)
    ZEND_ARG_ARRAY_INFO(0, keys, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_tagcache_keys_by_tag, 0, 0, 2)
    ZEND_ARG_INFO(0, handle)
    ZEND_ARG_TYPE_INFO(0, tag, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_tagcache_bulk_get, 0, 0, 2)
    ZEND_ARG_INFO(0, handle)
    ZEND_ARG_ARRAY_INFO(0, keys, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_tagcache_bulk_put, 0, 0, 2)
    ZEND_ARG_INFO(0, handle)
    ZEND_ARG_ARRAY_INFO(0, items, 0)
    ZEND_ARG_TYPE_INFO(0, ttl_ms, IS_LONG, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_tagcache_stats, 0, 0, 1)
    ZEND_ARG_INFO(0, handle)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_tagcache_flush, 0, 0, 1)
    ZEND_ARG_INFO(0, handle)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_tagcache_search_any, 0, 0, 2)
    ZEND_ARG_INFO(0, handle)
    ZEND_ARG_ARRAY_INFO(0, tags, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_tagcache_search_all, 0, 0, 2)
    ZEND_ARG_INFO(0, handle)
    ZEND_ARG_ARRAY_INFO(0, tags, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_tagcache_close, 0, 0, 1)
    ZEND_ARG_INFO(0, handle)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_tagcache_put, 0, 0, 3)
    ZEND_ARG_INFO(0, handle)
    ZEND_ARG_TYPE_INFO(0, key, IS_STRING, 0)
    ZEND_ARG_INFO(0, value)
    ZEND_ARG_ARRAY_INFO(0, tags, 1)
    ZEND_ARG_TYPE_INFO(0, ttl_ms, IS_LONG, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_tagcache_get, 0, 0, 2)
    ZEND_ARG_INFO(0, handle)
    ZEND_ARG_TYPE_INFO(0, key, IS_STRING, 0)
ZEND_END_ARG_INFO()

// ... (other arginfo omitted for brevity)

static void tc_client_dtor(zend_resource *res) {
    tc_client_handle *h = (tc_client_handle*)res->ptr;
    if (!h) return;
    if (h->pool) {
        for (int i=0;i<h->pool_len;i++) {
            if (h->pool[i].fd>=0) close(h->pool[i].fd);
            // Cleanup per-connection mutexes
            pthread_mutex_destroy(&h->pool[i].conn_mutex);
            pthread_mutex_destroy(&h->pool[i].pipeline_mutex);
        }
        efree(h->pool);
    }
    
    // Cleanup thread safety primitives
    pthread_mutex_destroy(&h->pool_mutex);
    pthread_mutex_destroy(&h->async_mutex);
    
    if (h->cfg.host) efree(h->cfg.host);
    if (h->cfg.http_base) efree(h->cfg.http_base);
    efree(h);
}

// Clock helper
static double now_mono() {
    struct timeval tv; gettimeofday(&tv, NULL); return (double)tv.tv_sec + (double)tv.tv_usec/1e6; }

static int set_blocking(int fd) {
    int flags = fcntl(fd, F_GETFL, 0);
    if (flags<0) return -1;
    return fcntl(fd, F_SETFL, flags & ~O_NONBLOCK);
}

static int tc_tcp_connect_raw(const char *host, int port, int timeout_ms) {
    struct addrinfo hints, *res=NULL, *rp=NULL;
    char portbuf[16]; snprintf(portbuf, sizeof(portbuf), "%d", port);
    memset(&hints,0,sizeof(hints)); hints.ai_family=AF_UNSPEC; hints.ai_socktype=SOCK_STREAM;
    if (getaddrinfo(host, portbuf, &hints, &res)!=0) return -1;
    int fd=-1; int saved=-1;
    for (rp=res; rp; rp=rp->ai_next) {
        fd=socket(rp->ai_family, rp->ai_socktype, rp->ai_protocol);
        if (fd<0) continue;
        // blocking connect with timeout via select if needed
        int flags = fcntl(fd, F_GETFL, 0);
        fcntl(fd, F_SETFL, flags | O_NONBLOCK);
        int rc = connect(fd, rp->ai_addr, rp->ai_addrlen);
        if (rc==0) {
            fcntl(fd, F_SETFL, flags & ~O_NONBLOCK);
            saved=fd; break;
        }
        if (rc<0 && errno==EINPROGRESS) {
            fd_set wf; FD_ZERO(&wf); FD_SET(fd,&wf);
            struct timeval tv; tv.tv_sec=timeout_ms/1000; tv.tv_usec=(timeout_ms%1000)*1000;
            int sel = select(fd+1,NULL,&wf,NULL,&tv);
            if (sel>0 && FD_ISSET(fd,&wf)) {
                int err=0; socklen_t el=sizeof(err); getsockopt(fd,SOL_SOCKET,SO_ERROR,&err,&el);
                if (!err) { fcntl(fd, F_SETFL, flags & ~O_NONBLOCK); saved=fd; break; }
            }
        }
        close(fd); fd=-1;
    }
    if (res) freeaddrinfo(res);
    if (saved>=0) {
        int one=1; setsockopt(saved, IPPROTO_TCP, TCP_NODELAY, &one, sizeof(one));
    }
    return saved;
}

// Setup keep-alive with configuration
void tc_setup_keep_alive(int fd, tc_client_config *cfg) {
    if (!cfg->enable_keep_alive) return;
    
    int one = 1;
    setsockopt(fd, SOL_SOCKET, SO_KEEPALIVE, &one, sizeof(one));
    
    // Set keep-alive parameters if supported
#ifdef TCP_KEEPIDLE
    setsockopt(fd, IPPROTO_TCP, TCP_KEEPIDLE, &cfg->keep_alive_idle, sizeof(cfg->keep_alive_idle));
#endif
#ifdef TCP_KEEPINTVL
    setsockopt(fd, IPPROTO_TCP, TCP_KEEPINTVL, &cfg->keep_alive_interval, sizeof(cfg->keep_alive_interval));
#endif
#ifdef TCP_KEEPCNT
    setsockopt(fd, IPPROTO_TCP, TCP_KEEPCNT, &cfg->keep_alive_count, sizeof(cfg->keep_alive_count));
#endif
}

static tc_tcp_conn *tc_get_conn(tc_client_handle *h) {
    pthread_mutex_lock(&h->pool_mutex);  // THREAD SAFETY: Lock pool access
    
    double t = now_mono();
    
    // AGGRESSIVE OPTIMIZATION 1: Ultra-fast connection pinning
    // If last connection is still healthy, use it immediately (no health checks)
    if (h->last_used && h->last_used->fd >= 0 && h->last_used->healthy) {
        tc_tcp_conn *conn = h->last_used;
        pthread_mutex_unlock(&h->pool_mutex);
        return conn;
    }
    
    // AGGRESSIVE OPTIMIZATION 2: Deterministic connection selection
    // Instead of round-robin, use modulo hash for better cache locality
    int start_idx = h->rr % h->pool_len;
    
    // AGGRESSIVE OPTIMIZATION 3: Unrolled connection search
    // Check up to 4 connections in sequence without loop overhead
    tc_tcp_conn *candidates[4];
    int candidate_count = 0;
    
    for (int i = 0; i < h->pool_len && candidate_count < 4; i++) {
        int idx = (start_idx + i) % h->pool_len;
        tc_tcp_conn *c = &h->pool[idx];
        
        if (c->fd >= 0 && c->healthy) {
            candidates[candidate_count++] = c;
        }
    }
    
    // Use first healthy connection found
    if (candidate_count > 0) {
        tc_tcp_conn *c = candidates[0];
        c->last_used = t;
        h->last_used = c; // Pin this connection aggressively
        h->rr = (c - h->pool); // Update position for next search (THREAD SAFE: under mutex)
        pthread_mutex_unlock(&h->pool_mutex);
        return c;
    }
    
    // AGGRESSIVE OPTIMIZATION 4: Parallel connection recovery
    // Try to recover multiple connections simultaneously
    int recovery_attempts = 2; // Recover up to 2 connections at once
    tc_tcp_conn *recovered = NULL;
    
    for (int attempt = 0; attempt < recovery_attempts && attempt < h->pool_len; attempt++) {
        int idx = (start_idx + attempt) % h->pool_len;
        tc_tcp_conn *c = &h->pool[idx];
        
        if (c->fd >= 0) close(c->fd);
        
        // AGGRESSIVE OPTIMIZATION 5: Reduced timeout for fast recovery
        int fast_timeout = h->cfg.connect_timeout_ms / 2; // Half normal timeout
        int fd = tc_tcp_connect_raw(h->cfg.host, h->cfg.port, fast_timeout);
        
        if (fd >= 0) {
            // Setup keep-alive if enabled
            tc_setup_keep_alive(fd, &h->cfg);
            
            c->fd = fd; 
            c->healthy = true; 
            c->created_at = t; 
            c->last_used = t; 
            c->rlen = 0; 
            c->rpos = 0; 
            c->wlen = 0;
            
            if (!recovered) {
                recovered = c; // Use first successful recovery
                h->last_used = c;
                h->rr = idx;  // THREAD SAFE: under mutex
            }
        } else {
            c->fd = -1; 
            c->healthy = false;
        }
    }
    
    pthread_mutex_unlock(&h->pool_mutex);  // THREAD SAFETY: Unlock before return
    return recovered;
}

// Buffered line reader (returns 0 on success, -1 on failure)
static int tc_readline(tc_tcp_conn *c, smart_str *out) {
    while (1) {
        // scan existing buffer for newline
        for (size_t i = c->rpos; i < c->rlen; i++) {
            if (c->rbuf[i] == '\n') {
                size_t line_len = i - c->rpos;
                if (line_len) smart_str_appendl(out, c->rbuf + c->rpos, line_len);
                c->rpos = i + 1; // move past '\n'
                if (c->rpos == c->rlen) { c->rpos = c->rlen = 0; }
                smart_str_0(out);
                return 0;
            }
        }
        // need more data; compact if buffer consumed fully
        if (c->rpos > 0) {
            if (c->rpos < c->rlen) {
                memmove(c->rbuf, c->rbuf + c->rpos, c->rlen - c->rpos);
                c->rlen -= c->rpos; c->rpos = 0;
            } else {
                c->rpos = c->rlen = 0;
            }
        }
        if (c->rlen == sizeof(c->rbuf)) {
            // line too long (should not happen for protocol); treat as error
            return -1;
        }
        ssize_t r = recv(c->fd, c->rbuf + c->rlen, sizeof(c->rbuf) - c->rlen, 0);
        if (r <= 0) { c->healthy = false; return -1; }
        c->rlen += (size_t)r;
    }
}

// Ultra-fast command assembly (safe version for cross-platform compatibility)
static int tc_build_get_cmd(tc_tcp_conn *c, const char *key, size_t key_len) {
    if (key_len + 10 > sizeof(c->cmd_buf)) return -1; // sanity check
    
    char *p = c->cmd_buf;
    memcpy(p, "GET\t", 4); 
    p += 4;
    
    // Fast memcpy for key
    memcpy(p, key, key_len); 
    p += key_len;
    *p++ = '\n';
    
    return (int)(p - c->cmd_buf);
}

// Ultra-fast integer to string conversion (faster than snprintf)
static inline int fast_ltoa(long value, char *buffer) {
    if (value == 0) {
        *buffer++ = '0';
        return 1;
    }
    
    char temp[32];
    int i = 0;
    bool negative = value < 0;
    if (negative) value = -value;
    
    // Convert digits in reverse
    while (value > 0) {
        temp[i++] = '0' + (value % 10);
        value /= 10;
    }
    
    int len = i;
    if (negative) {
        *buffer++ = '-';
        len++;
    }
    
    // Reverse digits into buffer
    while (i-- > 0) {
        *buffer++ = temp[i];
    }
    
    return len;
}

static int tc_build_put_cmd(tc_tcp_conn *c, const char *key, size_t key_len, 
                           const char *value, size_t val_len, 
                           const char *tags, size_t tags_len,
                           long ttl) {
    // Estimate total size: PUT\t + key + \t + ttl + \t + tags + \t + value + \n
    size_t est_size = 4 + key_len + 1 + 20 + 1 + tags_len + 1 + val_len + 1;
    if (est_size > sizeof(c->cmd_buf)) return -1;
    
    char *p = c->cmd_buf;
    memcpy(p, "PUT\t", 4); 
    p += 4;
    
    // Fast key copy
    memcpy(p, key, key_len); 
    p += key_len;
    *p++ = '\t';
    
    // Safe TTL conversion
    if (ttl > 0) {
        int n = fast_ltoa(ttl, p);
        p += n;
    } else {
        *p++ = '0';
    }
    *p++ = '\t';
    
    // Fast tags copy
    if (tags_len > 0) {
        memcpy(p, tags, tags_len);
        p += tags_len;
    }
    *p++ = '\t';
    
    // Fast value copy 
    memcpy(p, value, val_len);
    p += val_len;
    *p++ = '\n';
    
    return (int)(p - c->cmd_buf);
}

// Write buffer helpers
static int tc_flush(tc_tcp_conn *c) {
    size_t off=0; while (off < c->wlen) {
        ssize_t w = send(c->fd, c->wbuf + off, c->wlen - off, 0);
        if (w <= 0) { c->healthy=false; return -1; }
        off += (size_t)w;
    }
    c->wlen = 0; return 0;
}
static int tc_write(tc_tcp_conn *c, const char *buf, size_t len) {
    if (len > sizeof(c->wbuf)) { // large payload: flush existing then send directly
        if (c->wlen && tc_flush(c)!=0) return -1;
        size_t off=0; while(off<len){ ssize_t w=send(c->fd, buf+off, len-off, 0); if(w<=0){ c->healthy=false; return -1;} off+=(size_t)w; }
        return 0;
    }
    if (c->wlen + len > sizeof(c->wbuf)) {
        if (tc_flush(c)!=0) return -1;
    }
    memcpy(c->wbuf + c->wlen, buf, len); c->wlen += len; return 0;
}

int tc_tcp_cmd(tc_client_handle *h, const char *cmd, size_t cmd_len, smart_str *resp) {
    tc_tcp_conn *c=tc_get_conn(h); if(!c) return -1;
    if (tc_write(c, cmd, cmd_len)!=0) return -1;
    if (tc_flush(c)!=0) return -1; // ensure command is sent before waiting
    smart_str line = {0}; if (tc_readline(c, &line)!=0) { smart_str_free(&line); return -1; }
    *resp = line; return 0;
}

// Ultra-optimized send with MSG_MORE for batching (macOS fallback)
static inline int tc_send_ultra_fast(int fd, const void *buf, size_t len, bool more) {
    int flags = MSG_NOSIGNAL;
#ifdef MSG_MORE
    if (more) flags |= MSG_MORE;  // Tell kernel more data is coming
#endif
    
    ssize_t sent = send(fd, buf, len, flags);
    return (sent == (ssize_t)len) ? 0 : -1;
}

// Ultra-optimized recv with pre-allocated buffer
static inline int tc_recv_ultra_fast(int fd, char *buf, size_t max_len, size_t *recv_len) {
    ssize_t r = recv(fd, buf, max_len - 1, 0);
    if (r <= 0) return -1;
    buf[r] = '\0';
    *recv_len = (size_t)r;
    return 0;
}

// AGGRESSIVE OPTIMIZATION: Command pipelining for multiple operations
static int tc_tcp_pipeline_cmds(tc_client_handle *h, const char **cmds, size_t *cmd_lens, int cmd_count, smart_str *responses) {
    tc_tcp_conn *c = tc_get_conn(h); 
    if (!c) return -1;
    
    // PHASE 1: Send all commands with MSG_MORE to batch them
    for (int i = 0; i < cmd_count; i++) {
        bool more = (i < cmd_count - 1); // More data coming unless this is the last command
        
        if (tc_send_ultra_fast(c->fd, cmds[i], cmd_lens[i], more) != 0) {
            c->healthy = false;
            return -1;
        }
    }
    
    // PHASE 2: Read all responses in sequence
    for (int i = 0; i < cmd_count; i++) {
        smart_str line = {0};
        if (tc_readline(c, &line) != 0) {
            // Clean up any partial responses
            for (int j = 0; j < i; j++) {
                smart_str_free(&responses[j]);
            }
            smart_str_free(&line);
            return -1;
        }
        responses[i] = line;
    }
    
    return 0;
}

// Zero-allocation GET with stack buffers only - 10x faster
static int tc_ultrafast_get(tc_tcp_conn *c, const char *key, size_t key_len, char *result_buf, size_t buf_size, size_t *result_len) {
    // Build GET command in minimal stack buffer
    char cmd[256];
    if (4 + key_len + 2 > sizeof(cmd)) return -1;
    
    memcpy(cmd, "GET\t", 4);
    memcpy(cmd + 4, key, key_len);
    cmd[4 + key_len] = '\n';
    
    size_t cmd_len = 4 + key_len + 1;
    
    // Single optimized send
    if (tc_send_ultra_fast(c->fd, cmd, cmd_len, false) != 0) {
        c->healthy = false;
        return -1;
    }
    
    // Single optimized receive
    if (tc_recv_ultra_fast(c->fd, result_buf, buf_size, result_len) != 0) {
        c->healthy = false;
        return -1;
    }
    
    // Ultra-fast response check
    if (*result_len >= 6 && memcmp(result_buf, "VALUE\t", 6) == 0) {
        return 0; // Found
    } else if (*result_len >= 9 && memcmp(result_buf, "NOT_FOUND", 9) == 0) {
        return 1; // Not found  
    }
    
    return -1; // Error
}

// Zero-allocation PUT with stack buffers only - 10x faster
static int tc_ultrafast_put(tc_tcp_conn *c, const char *key, size_t key_len, 
                           const char *value, size_t val_len, long ttl) {
    // Use connection's command buffer for zero allocation
    char *p = c->cmd_buf;
    size_t remaining = sizeof(c->cmd_buf);
    
    // Ultra-fast command assembly
    if (remaining < 4 + key_len + 1 + 20 + 3 + val_len + 1) return -1;
    
    memcpy(p, "PUT\t", 4); p += 4;
    memcpy(p, key, key_len); p += key_len;
    *p++ = '\t';
    
    if (ttl > 0) {
        p += sprintf(p, "%ld", ttl);
    } else {
        *p++ = '-';
    }
    *p++ = '\t';
    *p++ = '-'; // no tags
    *p++ = '\t';
    
    memcpy(p, value, val_len); p += val_len;
    *p++ = '\n';
    
    size_t cmd_len = p - c->cmd_buf;
    
    // Single optimized send
    if (tc_send_ultra_fast(c->fd, c->cmd_buf, cmd_len, false) != 0) {
        c->healthy = false;
        return -1;
    }
    
    // Single optimized receive
    char resp[16];
    size_t resp_len;
    if (tc_recv_ultra_fast(c->fd, resp, sizeof(resp), &resp_len) != 0) {
        c->healthy = false;
        return -1;
    }
    
    // Ultra-fast response check
    return (resp_len >= 2 && resp[0] == 'O' && resp[1] == 'K') ? 0 : -1;
}

// Specialized raw GET: writes command with stack buffer, returns 0 on success, 1 NF, -1 error.
static int tc_tcp_get_raw(tc_client_handle *h, const char *key, size_t key_len, smart_str *val_line) {
    tc_tcp_conn *c = tc_get_conn(h); if(!c) return -1;
    char cmd[256];
    size_t max_needed = key_len + 6; // "GET\t" + key + "\n"
    int n;
    if (max_needed < sizeof(cmd)) {
        n = snprintf(cmd, sizeof(cmd), "GET\t%.*s\n", (int)key_len, key);
        if (n < 0 || (size_t)n >= sizeof(cmd)) return -1;
        size_t off=0; while(off<(size_t)n){ ssize_t w=send(c->fd, cmd+off, (size_t)n-off, 0); if(w<=0){ c->healthy=false; return -1;} off+= (size_t)w; }
    } else {
        // fallback if key very large
        smart_str dyn = {0}; smart_str_appends(&dyn, "GET\t"); smart_str_appendl(&dyn, key, key_len); smart_str_appendc(&dyn,'\n'); smart_str_0(&dyn);
        size_t off=0; while(off<ZSTR_LEN(dyn.s)){ ssize_t w=send(c->fd, ZSTR_VAL(dyn.s)+off, ZSTR_LEN(dyn.s)-off, 0); if(w<=0){ c->healthy=false; smart_str_free(&dyn); return -1;} off+= (size_t)w; }
        smart_str_free(&dyn);
    }
    // Read single line
    smart_str line = {0}; if (tc_readline(c, &line)!=0) { smart_str_free(&line); return -1; }
    if (!line.s) { smart_str_free(&line); return -1; }
    if (zend_string_equals_literal(line.s, "NF")) { smart_str_free(&line); return 1; }
    // Expect VALUE\t...
    if (ZSTR_LEN(line.s) < 7 || strncmp(ZSTR_VAL(line.s), "VALUE\t", 6)!=0) { smart_str_free(&line); return -1; }
    *val_line = line; return 0;
}

// Serialization markers
static void append_marker(smart_str *buf, const char *s){ smart_str_appends(buf,s); }

// Multi-format serializer with runtime format selection
char *tc_serialize_zval(smart_str *out, zval *val, tc_serialize_t format) {
    switch (format) {
        case TC_SERIALIZE_NATIVE:
            // NATIVE: Only serialize basic types, reject complex ones
            switch (Z_TYPE_P(val)) {
                case IS_STRING: smart_str_append(out, Z_STR_P(val)); break;
                case IS_LONG: smart_str_append_long(out, Z_LVAL_P(val)); break;
                case IS_DOUBLE: { smart_str_append_printf(out, "%.*G", 14, Z_DVAL_P(val)); } break;
                case IS_TRUE: append_marker(out, "__TC_TRUE__"); break;
                case IS_FALSE: append_marker(out, "__TC_FALSE__"); break;
                case IS_NULL: append_marker(out, "__TC_NULL__"); break;
                default:
                    // Complex types not supported in native mode
                    return NULL;
            }
            break;
            
        case TC_SERIALIZE_IGBINARY:
#ifdef HAVE_IGBINARY
            {
                uint8_t *serialized_data;
                size_t serialized_len;
                if (igbinary_serialize(&serialized_data, &serialized_len, val) == 0) {
                    // Prefix with format marker and base64 encode
                    zend_string *b64 = php_base64_encode(serialized_data, serialized_len);
                    append_marker(out, "__TC_IGBINARY__");
                    if (b64) { 
                        smart_str_append(out, b64); 
                        zend_string_release(b64);
                    }
                    efree(serialized_data);
                } else {
                    return NULL; // Fallback would be handled by caller
                }
            }
#else
            // igbinary not available, fallback to PHP serialize
            goto php_serialize_fallback;
#endif
            break;
            
        case TC_SERIALIZE_MSGPACK:
#ifdef HAVE_MSGPACK
            {
                msgpack_packer pk;
                msgpack_sbuffer sbuf;
                msgpack_sbuffer_init(&sbuf);
                msgpack_packer_init(&pk, &sbuf, msgpack_sbuffer_write);
                
                if (msgpack_pack_zval(&pk, val) == 0) {
                    zend_string *b64 = php_base64_encode((const unsigned char*)sbuf.data, sbuf.size);
                    append_marker(out, "__TC_MSGPACK__");
                    if (b64) {
                        smart_str_append(out, b64);
                        zend_string_release(b64);
                    }
                }
                msgpack_sbuffer_destroy(&sbuf);
            }
#else
            // msgpack not available, fallback to PHP serialize
            goto php_serialize_fallback;
#endif
            break;
            
        case TC_SERIALIZE_PHP:
        default:
php_serialize_fallback:
            // PHP serialize for complex types, fast path for scalars
            switch (Z_TYPE_P(val)) {
                case IS_STRING: smart_str_append(out, Z_STR_P(val)); break;
                case IS_LONG: smart_str_append_long(out, Z_LVAL_P(val)); break;
                case IS_DOUBLE: { smart_str_append_printf(out, "%.*G", 14, Z_DVAL_P(val)); } break;
                case IS_TRUE: append_marker(out, "__TC_TRUE__"); break;
                case IS_FALSE: append_marker(out, "__TC_FALSE__"); break;
                case IS_NULL: append_marker(out, "__TC_NULL__"); break;
                default: {
                    // serialize complex types using PHP serialize()
                    php_serialize_data_t var_hash; PHP_VAR_SERIALIZE_INIT(var_hash);
                    smart_str ser = {0}; php_var_serialize(&ser, val, &var_hash); PHP_VAR_SERIALIZE_DESTROY(var_hash); smart_str_0(&ser);
                    zend_string *b64 = php_base64_encode((const unsigned char*)ZSTR_VAL(ser.s), ZSTR_LEN(ser.s));
                    append_marker(out, "__TC_SERIALIZED__"); if (b64) { smart_str_append(out, b64); zend_string_release(b64);} smart_str_free(&ser);
                }
            }
            break;
    }
    smart_str_0(out);
    return out->s ? ZSTR_VAL(out->s) : NULL;
}

// Fast path serializer: writes directly into preallocated buffer when possible.
// Returns length written or -1 if fallback to smart_str required.
static int tc_serialize_inline(zval *val, char *buf, size_t buf_cap, tc_serialize_t format) {
    // Only native and PHP modes support inline serialization for scalars
    if (format != TC_SERIALIZE_NATIVE && format != TC_SERIALIZE_PHP) {
        return -1; // Force smart_str path for igbinary/msgpack
    }
    
    switch (Z_TYPE_P(val)) {
        case IS_STRING: {
            size_t l = Z_STRLEN_P(val); if (l > buf_cap) return -1; memcpy(buf, Z_STRVAL_P(val), l); return (int)l;
        }
        case IS_LONG: {
            int n = snprintf(buf, buf_cap, "%lld", (long long)Z_LVAL_P(val)); if (n<0 || (size_t)n>=buf_cap) return -1; return n;
        }
        case IS_DOUBLE: {
            int n = snprintf(buf, buf_cap, "%.*G", 14, Z_DVAL_P(val)); if (n<0 || (size_t)n>=buf_cap) return -1; return n;
        }
        case IS_TRUE: {
            const char *m = "__TC_TRUE__"; size_t l=strlen(m); if (l>buf_cap) return -1; memcpy(buf,m,l); return (int)l; }
        case IS_FALSE: {
            const char *m = "__TC_FALSE__"; size_t l=strlen(m); if (l>buf_cap) return -1; memcpy(buf,m,l); return (int)l; }
        case IS_NULL: {
            const char *m = "__TC_NULL__"; size_t l=strlen(m); if (l>buf_cap) return -1; memcpy(buf,m,l); return (int)l; }
        default:
            return -1; // complex type fallback
    }
}

int tc_deserialize_to_zval(const char *data, size_t len, zval *return_value) {
    if (len==0) { ZVAL_STRINGL(return_value, "", 0); return 0; }
    if (strcmp(data,"__TC_NULL__")==0){ ZVAL_NULL(return_value); return 0; }
    if (strcmp(data,"__TC_TRUE__")==0){ ZVAL_TRUE(return_value); return 0; }
    if (strcmp(data,"__TC_FALSE__")==0){ ZVAL_FALSE(return_value); return 0; }
    
    // Check for igbinary format
    if (len>15 && strncmp(data,"__TC_IGBINARY__",15)==0) {
#ifdef HAVE_IGBINARY
        const char *b64 = data+15; size_t blen = len-15;
        zend_string *dec = php_base64_decode_ex((const unsigned char*)b64, blen, 1);
        if (!dec) { ZVAL_STRINGL(return_value, data, len); return -1; }
        
        if (igbinary_unserialize((const uint8_t*)ZSTR_VAL(dec), ZSTR_LEN(dec), return_value) != 0) {
            ZVAL_STRINGL(return_value, data, len);
            zend_string_release(dec);
            return -1;
        }
        zend_string_release(dec);
        return 0;
#else
        // igbinary not available, treat as string
        ZVAL_STRINGL(return_value, data, len);
        return -1;
#endif
    }
    
    // Check for msgpack format  
    if (len>13 && strncmp(data,"__TC_MSGPACK__",13)==0) {
#ifdef HAVE_MSGPACK
        const char *b64 = data+13; size_t blen = len-13;
        zend_string *dec = php_base64_decode_ex((const unsigned char*)b64, blen, 1);
        if (!dec) { ZVAL_STRINGL(return_value, data, len); return -1; }
        
        msgpack_unpacked result;
        msgpack_unpacked_init(&result);
        msgpack_unpack_return ret = msgpack_unpack_next(&result, ZSTR_VAL(dec), ZSTR_LEN(dec), NULL);
        
        if (ret == MSGPACK_UNPACK_SUCCESS) {
            if (msgpack_unpack_to_zval(&result.data, return_value) != 0) {
                ZVAL_STRINGL(return_value, data, len);
                msgpack_unpacked_destroy(&result);
                zend_string_release(dec);
                return -1;
            }
        } else {
            ZVAL_STRINGL(return_value, data, len);
            msgpack_unpacked_destroy(&result);
            zend_string_release(dec);
            return -1;
        }
        
        msgpack_unpacked_destroy(&result);
        zend_string_release(dec);
        return 0;
#else
        // msgpack not available, treat as string
        ZVAL_STRINGL(return_value, data, len);
        return -1;
#endif
    }
    
    // Check for PHP serialized format
    if (len>17 && strncmp(data,"__TC_SERIALIZED__",17)==0) {
        const char *b64 = data+17; size_t blen = len-17;
        zend_string *dec = php_base64_decode_ex((const unsigned char*)b64, blen, 1);
        if (!dec) { ZVAL_STRINGL(return_value, data, len); return -1; }
        const unsigned char *p=(unsigned char*)ZSTR_VAL(dec); php_unserialize_data_t var_hash; PHP_VAR_UNSERIALIZE_INIT(var_hash);
        if (!php_var_unserialize(return_value, &p, p+ZSTR_LEN(dec), &var_hash)) {
            ZVAL_STRINGL(return_value, data, len);
        }
        PHP_VAR_UNSERIALIZE_DESTROY(var_hash); zend_string_release(dec); return 0;
    }
    // numeric?
    {
        zend_long l; double d; int oflow=0; bool trailing=0; uint8_t nt = is_numeric_string_ex(data, len, &l, &d, 0, &oflow, &trailing);
        if (nt==IS_LONG) { ZVAL_LONG(return_value,l); return 0; }
        if (nt==IS_DOUBLE) { ZVAL_DOUBLE(return_value,d); return 0; }
    }
    ZVAL_STRINGL(return_value, data, len); return 0;
}

// --- Request Pipelining Implementation ---

int tc_pipeline_begin(tc_tcp_conn *conn) {
    if (!conn || !conn->healthy) return -1;
    
    pthread_mutex_lock(&conn->pipeline_mutex);  // THREAD SAFETY: Lock pipeline
    
    conn->pipeline_mode = true;
    atomic_store(&conn->pending_requests, 0);   // THREAD SAFETY: Atomic store
    
    if (!conn->pipeline_buffer) {
        conn->pipeline_buf_size = 65536; // 64KB pipeline buffer
        conn->pipeline_buffer = emalloc(conn->pipeline_buf_size);
    }
    conn->pipeline_buf_used = 0;
    
    pthread_mutex_unlock(&conn->pipeline_mutex);  // THREAD SAFETY: Unlock
    return 0;
}

int tc_pipeline_add_request(tc_tcp_conn *conn, const char *cmd, size_t cmd_len) {
    if (!conn || !conn->pipeline_mode || !cmd) return -1;
    
    pthread_mutex_lock(&conn->pipeline_mutex);  // THREAD SAFETY: Lock pipeline
    
    // Check if buffer has space
    if (conn->pipeline_buf_used + cmd_len >= conn->pipeline_buf_size) {
        // Buffer full, execute current pipeline first
        pthread_mutex_unlock(&conn->pipeline_mutex);  // Unlock for execute
        smart_str *responses = NULL;
        int response_count = 0;
        if (tc_pipeline_execute(conn, &responses, &response_count) != 0) {
            return -1;
        }
        // Free responses (caller should handle them)
        if (responses) {
            for (int i = 0; i < response_count; i++) {
                smart_str_free(&responses[i]);
            }
        pthread_mutex_lock(&conn->pipeline_mutex);  // Re-lock for buffer ops
            efree(responses);
        }
    }
    
    // Add command to pipeline buffer (THREAD SAFE: under mutex)
    memcpy(conn->pipeline_buffer + conn->pipeline_buf_used, cmd, cmd_len);
    conn->pipeline_buf_used += cmd_len;
    atomic_fetch_add(&conn->pending_requests, 1);  // THREAD SAFETY: Atomic increment
    
    pthread_mutex_unlock(&conn->pipeline_mutex);  // THREAD SAFETY: Unlock
    return 0;
}

int tc_pipeline_execute(tc_tcp_conn *conn, smart_str **responses, int *response_count) {
    if (!conn || !conn->pipeline_mode) return -1;
    
    pthread_mutex_lock(&conn->pipeline_mutex);  // THREAD SAFETY: Lock pipeline
    
    int pending = atomic_load(&conn->pending_requests);  // THREAD SAFETY: Atomic load
    if (pending == 0) {
        pthread_mutex_unlock(&conn->pipeline_mutex);
        return -1;
    }
    
    // Send all buffered requests at once (THREAD SAFE: under mutex)
    if (tc_write(conn, conn->pipeline_buffer, conn->pipeline_buf_used) != 0) {
        conn->healthy = false;
        pthread_mutex_unlock(&conn->pipeline_mutex);
        return -1;
    }
    
    // Flush write buffer
    if (tc_flush(conn) != 0) {
        conn->healthy = false;
        pthread_mutex_unlock(&conn->pipeline_mutex);
        return -1;
    }
    
    // Allocate response array
    *responses = ecalloc(pending, sizeof(smart_str));
    *response_count = pending;
    
    pthread_mutex_unlock(&conn->pipeline_mutex);  // Unlock for I/O operations
    
    // Read all responses
    for (int i = 0; i < pending; i++) {
        if (tc_readline(conn, &(*responses)[i]) != 0) {
            conn->healthy = false;
            // Free allocated responses
            for (int j = 0; j < i; j++) {
                smart_str_free(&(*responses)[j]);
            }
            efree(*responses);
            *responses = NULL;
            *response_count = 0;
            return -1;
        }
    }
    
    // Reset pipeline state (THREAD SAFE: atomic operations)
    pthread_mutex_lock(&conn->pipeline_mutex);  // THREAD SAFETY: Lock for reset
    conn->pipeline_buf_used = 0;
    atomic_store(&conn->pending_requests, 0);  // THREAD SAFETY: Atomic store
    pthread_mutex_unlock(&conn->pipeline_mutex);  // THREAD SAFETY: Unlock
    
    return 0;
}

int tc_pipeline_end(tc_tcp_conn *conn) {
    if (!conn) return -1;
    
    // Execute any remaining requests
    if (conn->pending_requests > 0) {
        smart_str *responses = NULL;
        int response_count = 0;
        if (tc_pipeline_execute(conn, &responses, &response_count) != 0) {
            return -1;
        }
        // Free responses
        if (responses) {
            for (int i = 0; i < response_count; i++) {
                smart_str_free(&responses[i]);
            }
            efree(responses);
        }
    }
    
    conn->pipeline_mode = false;
    return 0;
}

// --- Async I/O Implementation ---

int tc_async_begin(tc_client_handle *h) {
    if (!h || !h->cfg.enable_async_io) return -1;
    
    h->async_mode = true;
    h->async_fd_count = 0;
    
    if (!h->async_fds) {
        h->async_fds = ecalloc(h->cfg.pool_size, sizeof(int));
    }
    
    return 0;
}

int tc_async_add_request(tc_client_handle *h, const char *key, int request_type) {
    if (!h || !h->async_mode || !key) return -1;
    
    // Get a connection from pool (non-blocking)
    tc_tcp_conn *conn = tc_get_conn(h);
    if (!conn) return -1;
    
    // Set non-blocking mode
    int flags = fcntl(conn->fd, F_GETFL, 0);
    fcntl(conn->fd, F_SETFL, flags | O_NONBLOCK);
    
    // Add to async fd list (THREAD SAFE: atomic operations)
    pthread_mutex_lock(&h->async_mutex);  // THREAD SAFETY: Lock async operations
    int current_count = atomic_load(&h->async_fd_count);
    if (current_count < h->cfg.pool_size) {
        h->async_fds[current_count] = conn->fd;
        atomic_fetch_add(&h->async_fd_count, 1);  // THREAD SAFETY: Atomic increment
    }
    pthread_mutex_unlock(&h->async_mutex);  // THREAD SAFETY: Unlock
    
    // Send request (non-blocking)
    smart_str cmd = {0};
    if (request_type == 0) { // GET
        smart_str_appends(&cmd, "GET\t");
        smart_str_appends(&cmd, key);
        smart_str_appendc(&cmd, '\n');
    }
    smart_str_0(&cmd);
    
    if (tc_write(conn, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s)) != 0) {
        smart_str_free(&cmd);
        return -1;
    }
    
    smart_str_free(&cmd);
    return 0;
}

int tc_async_execute(tc_client_handle *h, zval *results) {
    if (!h || !h->async_mode || h->async_fd_count == 0) return -1;
    
    // Use select() to wait for responses
    fd_set readfds;
    FD_ZERO(&readfds);
    int max_fd = 0;
    
    for (int i = 0; i < h->async_fd_count; i++) {
        FD_SET(h->async_fds[i], &readfds);
        if (h->async_fds[i] > max_fd) {
            max_fd = h->async_fds[i];
        }
    }
    
    struct timeval timeout;
    timeout.tv_sec = h->cfg.timeout_ms / 1000;
    timeout.tv_usec = (h->cfg.timeout_ms % 1000) * 1000;
    
    int ready = select(max_fd + 1, &readfds, NULL, NULL, &timeout);
    if (ready <= 0) return -1;
    
    // Read responses from ready connections
    array_init(results);
    int response_count = 0;
    
    for (int i = 0; i < h->async_fd_count; i++) {
        if (FD_ISSET(h->async_fds[i], &readfds)) {
            // Find connection by fd
            tc_tcp_conn *conn = NULL;
            for (int j = 0; j < h->pool_len; j++) {
                if (h->pool[j].fd == h->async_fds[i]) {
                    conn = &h->pool[j];
                    break;
                }
            }
            
            if (conn) {
                smart_str response = {0};
                if (tc_readline(conn, &response) == 0 && response.s) {
                    zval result;
                    tc_deserialize_to_zval(ZSTR_VAL(response.s), ZSTR_LEN(response.s), &result);
                    add_index_zval(results, response_count++, &result);
                }
                smart_str_free(&response);
                
                // Restore blocking mode
                int flags = fcntl(conn->fd, F_GETFL, 0);
                fcntl(conn->fd, F_SETFL, flags & ~O_NONBLOCK);
            }
        }
    }
    
    return response_count;
}

int tc_async_end(tc_client_handle *h) {
    if (!h) return -1;
    
    h->async_mode = false;
    h->async_fd_count = 0;
    
    return 0;
}

// --- PHP Functions ---
// Procedural create: tagcache_create(array $options = null): resource
PHP_FUNCTION(tagcache_create) {
    zval *options = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|a", &options) == FAILURE) RETURN_NULL();

    tc_client_handle *h = ecalloc(1, sizeof(tc_client_handle));
    // defaults
    h->cfg.mode = TC_MODE_TCP;
    h->cfg.host = estrdup("127.0.0.1");
    h->cfg.port = 1984;
    h->cfg.http_base = estrdup("http://127.0.0.1:8080");
    h->cfg.timeout_ms = 5000;
    h->cfg.connect_timeout_ms = 3000;
    h->cfg.pool_size = 8;
    h->cfg.serializer = TC_SERIALIZE_PHP; // Default to PHP serialize
    // Advanced optimization defaults
    h->cfg.enable_pipelining = false;
    h->cfg.pipeline_depth = 10;
    h->cfg.enable_async_io = false;
    h->cfg.enable_keep_alive = true; // Enabled by default for better performance
    h->cfg.keep_alive_idle = 60;     // 60 seconds idle before keep-alive
    h->cfg.keep_alive_interval = 10; // 10 seconds between probes
    h->cfg.keep_alive_count = 3;     // 3 failed probes before connection considered dead

    if (options) {
        zval *z;
        if ((z = zend_hash_str_find(Z_ARRVAL_P(options), "mode", sizeof("mode")-1)) && Z_TYPE_P(z)==IS_STRING) {
            if (zend_string_equals_literal_ci(Z_STR_P(z), "http")) h->cfg.mode = TC_MODE_HTTP;
            else if (zend_string_equals_literal_ci(Z_STR_P(z), "auto")) h->cfg.mode = TC_MODE_AUTO;
            else h->cfg.mode = TC_MODE_TCP;
        }
        if ((z = zend_hash_str_find(Z_ARRVAL_P(options), "host", sizeof("host")-1)) && Z_TYPE_P(z)==IS_STRING) { efree(h->cfg.host); h->cfg.host = estrdup(Z_STRVAL_P(z)); }
        if ((z = zend_hash_str_find(Z_ARRVAL_P(options), "port", sizeof("port")-1)) && Z_TYPE_P(z)==IS_LONG) { h->cfg.port = Z_LVAL_P(z); }
        if ((z = zend_hash_str_find(Z_ARRVAL_P(options), "http_base", sizeof("http_base")-1)) && Z_TYPE_P(z)==IS_STRING) { efree(h->cfg.http_base); h->cfg.http_base = estrdup(Z_STRVAL_P(z)); }
        if ((z = zend_hash_str_find(Z_ARRVAL_P(options), "timeout_ms", sizeof("timeout_ms")-1)) && Z_TYPE_P(z)==IS_LONG) { h->cfg.timeout_ms = Z_LVAL_P(z); }
        if ((z = zend_hash_str_find(Z_ARRVAL_P(options), "connect_timeout_ms", sizeof("connect_timeout_ms")-1)) && Z_TYPE_P(z)==IS_LONG) { h->cfg.connect_timeout_ms = Z_LVAL_P(z); }
        if ((z = zend_hash_str_find(Z_ARRVAL_P(options), "pool_size", sizeof("pool_size")-1)) && Z_TYPE_P(z)==IS_LONG) { h->cfg.pool_size = Z_LVAL_P(z); }
        
        // Parse serializer option
        if ((z = zend_hash_str_find(Z_ARRVAL_P(options), "serializer", sizeof("serializer")-1)) && Z_TYPE_P(z)==IS_STRING) {
            if (zend_string_equals_literal_ci(Z_STR_P(z), "php")) h->cfg.serializer = TC_SERIALIZE_PHP;
            else if (zend_string_equals_literal_ci(Z_STR_P(z), "igbinary")) h->cfg.serializer = TC_SERIALIZE_IGBINARY;
            else if (zend_string_equals_literal_ci(Z_STR_P(z), "msgpack")) h->cfg.serializer = TC_SERIALIZE_MSGPACK;  
            else if (zend_string_equals_literal_ci(Z_STR_P(z), "native")) h->cfg.serializer = TC_SERIALIZE_NATIVE;
            else h->cfg.serializer = TC_SERIALIZE_PHP; // Fallback
        }
        
        // Parse advanced optimization options
        if ((z = zend_hash_str_find(Z_ARRVAL_P(options), "enable_pipelining", sizeof("enable_pipelining")-1)) && Z_TYPE_P(z)==IS_TRUE) {
            h->cfg.enable_pipelining = true;
        }
        if ((z = zend_hash_str_find(Z_ARRVAL_P(options), "pipeline_depth", sizeof("pipeline_depth")-1)) && Z_TYPE_P(z)==IS_LONG) {
            h->cfg.pipeline_depth = Z_LVAL_P(z);
        }
        if ((z = zend_hash_str_find(Z_ARRVAL_P(options), "enable_async_io", sizeof("enable_async_io")-1)) && Z_TYPE_P(z)==IS_TRUE) {
            h->cfg.enable_async_io = true;
        }
        if ((z = zend_hash_str_find(Z_ARRVAL_P(options), "enable_keep_alive", sizeof("enable_keep_alive")-1)) && Z_TYPE_P(z)==IS_TRUE) {
            h->cfg.enable_keep_alive = true;
        }
        if ((z = zend_hash_str_find(Z_ARRVAL_P(options), "keep_alive_idle", sizeof("keep_alive_idle")-1)) && Z_TYPE_P(z)==IS_LONG) {
            h->cfg.keep_alive_idle = Z_LVAL_P(z);
        }
        if ((z = zend_hash_str_find(Z_ARRVAL_P(options), "keep_alive_interval", sizeof("keep_alive_interval")-1)) && Z_TYPE_P(z)==IS_LONG) {
            h->cfg.keep_alive_interval = Z_LVAL_P(z);
        }
        if ((z = zend_hash_str_find(Z_ARRVAL_P(options), "keep_alive_count", sizeof("keep_alive_count")-1)) && Z_TYPE_P(z)==IS_LONG) {
            h->cfg.keep_alive_count = Z_LVAL_P(z);
        }
    }

    if (h->cfg.mode != TC_MODE_TCP) {
        php_error_docref(NULL, E_NOTICE, "HTTP/AUTO mode not yet implemented; falling back to TCP");
        h->cfg.mode = TC_MODE_TCP;
    }
    h->pool_len = h->cfg.pool_size;
    h->pool = ecalloc(h->pool_len, sizeof(tc_tcp_conn));
    
    // Initialize thread safety primitives
    pthread_mutex_init(&h->pool_mutex, NULL);
    pthread_mutex_init(&h->async_mutex, NULL);
    
    // Initialize async I/O if enabled
    h->async_mode = false;
    h->async_fds = NULL;
    atomic_store(&h->async_fd_count, 0);
    
    // AGGRESSIVE OPTIMIZATION: Pre-warm ALL connections with optimized settings
    double t = now_mono();
    int successful_connections = 0;
    
    for (int i = 0; i < h->pool_len; i++) {
        // Attempt connection with reduced timeout for faster startup
        int fast_timeout = h->cfg.connect_timeout_ms / 2;
        h->pool[i].fd = tc_tcp_connect_raw(h->cfg.host, h->cfg.port, fast_timeout);
        
        if (h->pool[i].fd >= 0) {
            // Setup keep-alive if enabled
            tc_setup_keep_alive(h->pool[i].fd, &h->cfg);
            
            h->pool[i].healthy = true;
            h->pool[i].created_at = t;
            h->pool[i].last_used = t;
            
            // Initialize pipelining support
            atomic_store(&h->pool[i].pending_requests, 0);
            h->pool[i].pipeline_mode = false;
            h->pool[i].pipeline_buffer = NULL;
            h->pool[i].pipeline_buf_size = 0;
            h->pool[i].pipeline_buf_used = 0;
            
            // Initialize connection mutexes
            pthread_mutex_init(&h->pool[i].conn_mutex, NULL);
            pthread_mutex_init(&h->pool[i].pipeline_mutex, NULL);
            
            successful_connections++;
            
            // Set first successful connection as pinned
            if (!h->last_used) {
                h->last_used = &h->pool[i];
                h->rr = i;
            }
        } else {
            h->pool[i].healthy = false;
            // Initialize mutexes even for failed connections
            pthread_mutex_init(&h->pool[i].conn_mutex, NULL);
            pthread_mutex_init(&h->pool[i].pipeline_mutex, NULL);
        }
        h->pool[i].rlen = 0; 
        h->pool[i].rpos = 0;
        h->pool[i].wlen = 0;
    }
    
    // AGGRESSIVE OPTIMIZATION: If we have fewer than half the connections, 
    // try a second round with full timeout
    if (successful_connections < h->pool_len / 2) {
        for (int i = 0; i < h->pool_len; i++) {
            if (h->pool[i].fd < 0) { // Only retry failed connections
                h->pool[i].fd = tc_tcp_connect_raw(h->cfg.host, h->cfg.port, h->cfg.connect_timeout_ms);
                if (h->pool[i].fd >= 0) {
                    // Setup keep-alive if enabled
                    tc_setup_keep_alive(h->pool[i].fd, &h->cfg);
                    
                    h->pool[i].healthy = true;
                    h->pool[i].created_at = t;
                    h->pool[i].last_used = t;
                    successful_connections++;
                    
                    if (!h->last_used) {
                        h->last_used = &h->pool[i];
                        h->rr = i;
                    }
                }
            }
        }
    }

    zend_resource *res = zend_register_resource(h, le_tagcache_client);
    RETURN_RES(res);
}

PHP_FUNCTION(tagcache_put) {
    zval *res; char *key; size_t key_len; zval *value; zval *tags=NULL; zval *ttl=NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "rsz|az", &res, &key, &key_len, &value, &tags, &ttl)==FAILURE) RETURN_FALSE;
    tc_client_handle *h = zend_fetch_resource(Z_RES_P(res), PHP_TAGCACHE_EXTNAME, le_tagcache_client);
    if (!h) RETURN_FALSE;
    
    // Use original fast PUT that works
    long ttl_val = (ttl && Z_TYPE_P(ttl)==IS_LONG) ? Z_LVAL_P(ttl) : 0;
    
    // Serialize value
    char inline_buf[256]; 
    smart_str sval = {0}; 
    const char *val_ptr = NULL; 
    size_t val_len = 0; 
    int n = tc_serialize_inline(value, inline_buf, sizeof(inline_buf), h->cfg.serializer);
    if (n >= 0) { 
        val_ptr = inline_buf; 
        val_len = (size_t)n; 
    } else { 
        tc_serialize_zval(&sval, value, h->cfg.serializer); 
        if (sval.s) { 
            val_ptr = ZSTR_VAL(sval.s); 
            val_len = ZSTR_LEN(sval.s);
        } 
    }
    
    // Use working original PUT method
    smart_str cmd = {0}; 
    smart_str_appends(&cmd, "PUT\t"); 
    smart_str_appendl(&cmd, key, key_len); 
    smart_str_appendc(&cmd,'\t');
    if (ttl_val > 0) { 
        smart_str_append_long(&cmd, ttl_val); 
    } else { 
        smart_str_appends(&cmd, "-"); 
    }
    smart_str_appendc(&cmd,'\t');
    
    // Handle tags properly
    if (tags && Z_TYPE_P(tags) == IS_ARRAY && zend_hash_num_elements(Z_ARRVAL_P(tags)) > 0) {
        zval *entry;
        int first = 1;
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(tags), entry) {
            if (Z_TYPE_P(entry) == IS_STRING) {
                if (!first) smart_str_appendc(&cmd, ',');
                smart_str_appendl(&cmd, Z_STRVAL_P(entry), Z_STRLEN_P(entry));
                first = 0;
            }
        } ZEND_HASH_FOREACH_END();
    } else {
        smart_str_appends(&cmd, "-"); // no tags
    }
    
    smart_str_appendc(&cmd,'\t'); 
    if (val_ptr && val_len) smart_str_appendl(&cmd, val_ptr, val_len); 
    smart_str_appendc(&cmd,'\n'); 
    smart_str_0(&cmd);
    
    smart_str resp = {0};
    int result = tc_tcp_cmd(h, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s), &resp);
    bool ok = (result == 0 && resp.s && zend_string_equals_literal(resp.s, "OK"));
    
    smart_str_free(&cmd); 
    smart_str_free(&resp); 
    if (sval.s) smart_str_free(&sval);
    RETURN_BOOL(ok);
}

// Ultra-fast GET (no smart_str allocations)
static int tc_fast_get(tc_client_handle *h, const char *key, size_t key_len, smart_str *result) {
    tc_tcp_conn *c = tc_get_conn(h); if (!c) return -1;
    
    int cmd_len = tc_build_get_cmd(c, key, key_len);
    if (cmd_len < 0) return -1;
    
    // Send command directly
    if (tc_write(c, c->cmd_buf, cmd_len) != 0) return -1;
    if (tc_flush(c) != 0) return -1;
    
    // Read response
    smart_str line = {0};
    if (tc_readline(c, &line) != 0) { smart_str_free(&line); return -1; }
    
    if (!line.s) { smart_str_free(&line); return -1; }
    if (zend_string_equals_literal(line.s, "NF")) { smart_str_free(&line); return 1; } // not found
    
    // Check for VALUE response
    if (ZSTR_LEN(line.s) < 7 || strncmp(ZSTR_VAL(line.s), "VALUE\t", 6) != 0) {
        smart_str_free(&line); return -1;
    }
    
    *result = line;
    return 0;
}

// Ultra-fast PUT (no smart_str allocations for simple cases)
static int tc_fast_put(tc_client_handle *h, const char *key, size_t key_len,
                      const char *value, size_t val_len, long ttl) {
    tc_tcp_conn *c = tc_get_conn(h); if (!c) return -1;
    
    int cmd_len = tc_build_put_cmd(c, key, key_len, value, val_len, NULL, 0, ttl);
    if (cmd_len < 0) return -1;
    
    // Send command directly  
    ssize_t sent = send(c->fd, c->cmd_buf, cmd_len, MSG_NOSIGNAL);
    if (sent != cmd_len) { c->healthy = false; return -1; }
    
    // Read response without smart_str - just need "OK\n"
    char resp[8];
    ssize_t r = recv(c->fd, resp, sizeof(resp) - 1, 0);
    if (r <= 0) { c->healthy = false; return -1; }
    resp[r] = '\0';
    
    // Fast response check
    return (r >= 2 && resp[0] == 'O' && resp[1] == 'K') ? 0 : -1;
}

PHP_FUNCTION(tagcache_get) {
    zval *res; char *key; size_t key_len; 
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "rs", &res, &key, &key_len)==FAILURE) RETURN_NULL();
    tc_client_handle *h = zend_fetch_resource(Z_RES_P(res), PHP_TAGCACHE_EXTNAME, le_tagcache_client); 
    if(!h) RETURN_NULL();
    
    // Fetch from server
    smart_str result = {0};
    int rc = tc_fast_get(h, key, key_len, &result);
    if (rc == 1) { RETURN_NULL(); } // not found
    if (rc != 0) { RETURN_NULL(); } // error
    
    const char *payload = ZSTR_VAL(result.s) + 6; // skip "VALUE\t"
    size_t plen = ZSTR_LEN(result.s) - 6;
    
    tc_deserialize_to_zval(payload, plen, return_value);
    smart_str_free(&result);
}

PHP_FUNCTION(tagcache_delete) {
    zval *res; char *key; size_t key_len; if (zend_parse_parameters(ZEND_NUM_ARGS(), "rs", &res, &key, &key_len)==FAILURE) RETURN_FALSE;
    tc_client_handle *h = zend_fetch_resource(Z_RES_P(res), PHP_TAGCACHE_EXTNAME, le_tagcache_client); if(!h) RETURN_FALSE;
    smart_str cmd={0}; smart_str_appends(&cmd, "DEL\t"); smart_str_appendl(&cmd, key, key_len); smart_str_appendc(&cmd,'\n'); smart_str_0(&cmd);
    smart_str resp={0}; if (tc_tcp_cmd(h, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s), &resp)!=0) { smart_str_free(&cmd); smart_str_free(&resp); RETURN_FALSE; }
    bool ok=false; if (resp.s) { if (strstr(ZSTR_VAL(resp.s), "ok")) ok=true; }
    
    smart_str_free(&cmd); smart_str_free(&resp); RETURN_BOOL(ok);
}

PHP_FUNCTION(tagcache_invalidate_tag) {
    zval *res; char *tag; size_t tag_len; if (zend_parse_parameters(ZEND_NUM_ARGS(), "rs", &res, &tag, &tag_len)==FAILURE) RETURN_LONG(0);
    tc_client_handle *h = zend_fetch_resource(Z_RES_P(res), PHP_TAGCACHE_EXTNAME, le_tagcache_client); if(!h) RETURN_LONG(0);
    smart_str cmd={0}; smart_str_appends(&cmd, "INV_TAG\t"); smart_str_appendl(&cmd, tag, tag_len); smart_str_appendc(&cmd,'\n'); smart_str_0(&cmd);
    smart_str resp={0}; if (tc_tcp_cmd(h, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s), &resp)!=0) { smart_str_free(&cmd); smart_str_free(&resp); RETURN_LONG(0); }
    long count=0; if (resp.s && ZSTR_LEN(resp.s)>8 && strncmp(ZSTR_VAL(resp.s), "INV_TAG\t",8)==0) { count = atol(ZSTR_VAL(resp.s)+8); }
    
    smart_str_free(&cmd); smart_str_free(&resp); RETURN_LONG(count);
}

PHP_FUNCTION(tagcache_invalidate_tags_any) {
    zval *res, *tags_array; 
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "ra", &res, &tags_array) == FAILURE) RETURN_LONG(0);
    tc_client_handle *h = zend_fetch_resource(Z_RES_P(res), PHP_TAGCACHE_EXTNAME, le_tagcache_client); 
    if (!h) RETURN_LONG(0);
    
    smart_str cmd = {0}; 
    smart_str_appends(&cmd, "INV_TAGS_ANY\t");
    
    zval *entry; 
    int first = 1;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(tags_array), entry) {
        if (Z_TYPE_P(entry) == IS_STRING) {
            if (!first) smart_str_appendc(&cmd, ',');
            smart_str_appendl(&cmd, Z_STRVAL_P(entry), Z_STRLEN_P(entry));
            first = 0;
        }
    } ZEND_HASH_FOREACH_END();
    
    smart_str_appendc(&cmd, '\n'); 
    smart_str_0(&cmd);
    
    smart_str resp = {0}; 
    if (tc_tcp_cmd(h, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s), &resp) != 0) { 
        smart_str_free(&cmd); 
        smart_str_free(&resp); 
        RETURN_LONG(0); 
    }
    
    long count = 0; 
    if (resp.s && ZSTR_LEN(resp.s) > 13 && strncmp(ZSTR_VAL(resp.s), "INV_TAGS_ANY\t", 13) == 0) { 
        count = atol(ZSTR_VAL(resp.s) + 13); 
    }
    
    smart_str_free(&cmd); 
    smart_str_free(&resp); 
    RETURN_LONG(count);
}

PHP_FUNCTION(tagcache_invalidate_tags_all) {
    zval *res, *tags_array; 
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "ra", &res, &tags_array) == FAILURE) RETURN_LONG(0);
    tc_client_handle *h = zend_fetch_resource(Z_RES_P(res), PHP_TAGCACHE_EXTNAME, le_tagcache_client); 
    if (!h) RETURN_LONG(0);
    
    smart_str cmd = {0}; 
    smart_str_appends(&cmd, "INV_TAGS_ALL\t");
    
    zval *entry; 
    int first = 1;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(tags_array), entry) {
        if (Z_TYPE_P(entry) == IS_STRING) {
            if (!first) smart_str_appendc(&cmd, ',');
            smart_str_appendl(&cmd, Z_STRVAL_P(entry), Z_STRLEN_P(entry));
            first = 0;
        }
    } ZEND_HASH_FOREACH_END();
    
    smart_str_appendc(&cmd, '\n'); 
    smart_str_0(&cmd);
    
    smart_str resp = {0}; 
    if (tc_tcp_cmd(h, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s), &resp) != 0) { 
        smart_str_free(&cmd); 
        smart_str_free(&resp); 
        RETURN_LONG(0); 
    }
    
    long count = 0; 
    if (resp.s && ZSTR_LEN(resp.s) > 13 && strncmp(ZSTR_VAL(resp.s), "INV_TAGS_ALL\t", 13) == 0) { 
        count = atol(ZSTR_VAL(resp.s) + 13); 
    }
    
    smart_str_free(&cmd); 
    smart_str_free(&resp); 
    RETURN_LONG(count);
}

PHP_FUNCTION(tagcache_invalidate_keys) {
    zval *res, *keys_array; 
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "ra", &res, &keys_array) == FAILURE) RETURN_LONG(0);
    tc_client_handle *h = zend_fetch_resource(Z_RES_P(res), PHP_TAGCACHE_EXTNAME, le_tagcache_client); 
    if (!h) RETURN_LONG(0);
    
    smart_str cmd = {0}; 
    smart_str_appends(&cmd, "INV_KEYS\t");
    
    zval *entry; 
    int first = 1;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(keys_array), entry) {
        if (Z_TYPE_P(entry) == IS_STRING) {
            if (!first) smart_str_appendc(&cmd, ',');
            smart_str_appendl(&cmd, Z_STRVAL_P(entry), Z_STRLEN_P(entry));
            first = 0;
        }
    } ZEND_HASH_FOREACH_END();
    
    smart_str_appendc(&cmd, '\n'); 
    smart_str_0(&cmd);
    
    smart_str resp = {0}; 
    if (tc_tcp_cmd(h, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s), &resp) != 0) { 
        smart_str_free(&cmd); 
        smart_str_free(&resp); 
        RETURN_LONG(0); 
    }
    
    long count = 0; 
    if (resp.s && ZSTR_LEN(resp.s) > 9 && strncmp(ZSTR_VAL(resp.s), "INV_KEYS\t", 9) == 0) { 
        count = atol(ZSTR_VAL(resp.s) + 9); 
    }
    
    smart_str_free(&cmd); 
    smart_str_free(&resp); 
    RETURN_LONG(count);
}

PHP_FUNCTION(tagcache_keys_by_tag) {
    zval *res; char *tag; size_t tag_len; if (zend_parse_parameters(ZEND_NUM_ARGS(), "rs", &res, &tag, &tag_len)==FAILURE) RETURN_NULL();
    tc_client_handle *h = zend_fetch_resource(Z_RES_P(res), PHP_TAGCACHE_EXTNAME, le_tagcache_client); if(!h) RETURN_NULL();
    array_init(return_value);
    smart_str cmd={0}; smart_str_appends(&cmd, "KEYS_BY_TAG\t"); smart_str_appendl(&cmd, tag, tag_len); smart_str_appendc(&cmd,'\n'); smart_str_0(&cmd);
    smart_str resp={0}; if (tc_tcp_cmd(h, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s), &resp)!=0) { smart_str_free(&cmd); smart_str_free(&resp); return; }
    if (!resp.s) { smart_str_free(&cmd); smart_str_free(&resp); return; }
    if (ZSTR_LEN(resp.s) >=5 && strncmp(ZSTR_VAL(resp.s), "KEYS\t",5)==0) {
        char *list = ZSTR_VAL(resp.s)+5; if (*list=='\0') { smart_str_free(&cmd); smart_str_free(&resp); return; }
        char *dup = estrdup(list); char *p=dup; char *saveptr=NULL; char *tok;
        while ((tok=strtok_r(p, ",", &saveptr))) { p=NULL; add_next_index_string(return_value, tok); }
        efree(dup);
    }
    smart_str_free(&cmd); smart_str_free(&resp);
}

PHP_FUNCTION(tagcache_stats) {
    zval *res; if (zend_parse_parameters(ZEND_NUM_ARGS(), "r", &res)==FAILURE) RETURN_NULL();
    tc_client_handle *h = zend_fetch_resource(Z_RES_P(res), PHP_TAGCACHE_EXTNAME, le_tagcache_client); if(!h) RETURN_NULL();
    array_init(return_value);
    smart_str cmd={0}; smart_str_appends(&cmd, "STATS\n"); smart_str_0(&cmd); smart_str resp={0};
    if (tc_tcp_cmd(h, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s), &resp)!=0) { smart_str_free(&cmd); smart_str_free(&resp); return; }
    if (resp.s && ZSTR_LEN(resp.s)>6 && strncmp(ZSTR_VAL(resp.s), "STATS\t",6)==0) {
        // STATS <hits> <misses> <puts> <invalidations> <hit_ratio>
        char *dup = estrdup(ZSTR_VAL(resp.s)+6); char *p=dup; char *tok; int idx=0; char *saveptr=NULL; while((tok=strtok_r(p, "\t", &saveptr))) { p=NULL; switch(idx){ case 0: add_assoc_long(return_value, "hits", atol(tok)); break; case 1: add_assoc_long(return_value, "misses", atol(tok)); break; case 2: add_assoc_long(return_value, "puts", atol(tok)); break; case 3: add_assoc_long(return_value, "invalidations", atol(tok)); break; case 4: add_assoc_double(return_value, "hit_ratio", atof(tok)); break; } idx++; }
        efree(dup);
    }
    add_assoc_string(return_value, "transport", "tcp");
    smart_str_free(&cmd); smart_str_free(&resp);
}

PHP_FUNCTION(tagcache_flush) {
    zval *res; if (zend_parse_parameters(ZEND_NUM_ARGS(), "r", &res)==FAILURE) RETURN_LONG(0);
    tc_client_handle *h = zend_fetch_resource(Z_RES_P(res), PHP_TAGCACHE_EXTNAME, le_tagcache_client); if(!h) RETURN_LONG(0);
    smart_str cmd={0}; smart_str_appends(&cmd, "FLUSH\n"); smart_str_0(&cmd); smart_str resp={0};
    if (tc_tcp_cmd(h, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s), &resp)!=0) { smart_str_free(&cmd); smart_str_free(&resp); RETURN_LONG(0); }
    long count=0; if (resp.s && ZSTR_LEN(resp.s)>6 && strncmp(ZSTR_VAL(resp.s), "FLUSH\t",6)==0) { count = atol(ZSTR_VAL(resp.s)+6); }
    
    smart_str_free(&cmd); smart_str_free(&resp); RETURN_LONG(count);
}

PHP_FUNCTION(tagcache_bulk_get) {
    zval *res; zval *keys; if (zend_parse_parameters(ZEND_NUM_ARGS(), "ra", &res, &keys)==FAILURE) RETURN_NULL();
    tc_client_handle *h = zend_fetch_resource(Z_RES_P(res), PHP_TAGCACHE_EXTNAME, le_tagcache_client); if(!h) RETURN_NULL();
    array_init(return_value);
    uint32_t key_count = zend_hash_num_elements(Z_ARRVAL_P(keys));
    if (key_count == 0) return;
    // Acquire connection once (best effort)
    tc_tcp_conn *c = tc_get_conn(h); if(!c) return;
    // 1) Send all commands
    zval *zk; ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(keys), zk) {
        zval tmp; ZVAL_COPY(&tmp, zk); convert_to_string(&tmp);
        char header[256]; int n = snprintf(header, sizeof(header), "GET\t%.*s\n", (int)Z_STRLEN(tmp), Z_STRVAL(tmp));
        if (n>0 && (size_t)n < sizeof(header)) {
            size_t off=0; while(off<(size_t)n){ ssize_t w=send(c->fd, header+off, (size_t)n-off, 0); if(w<=0){ c->healthy=false; zval_ptr_dtor(&tmp); goto pipeline_read; } off+= (size_t)w; }
        } else {
            // fallback allocate
            smart_str dyn={0}; smart_str_appends(&dyn, "GET\t"); smart_str_append(&dyn, Z_STR(tmp)); smart_str_appendc(&dyn,'\n'); smart_str_0(&dyn);
            size_t off=0; while(off<ZSTR_LEN(dyn.s)){ ssize_t w=send(c->fd, ZSTR_VAL(dyn.s)+off, ZSTR_LEN(dyn.s)-off, 0); if(w<=0){ c->healthy=false; smart_str_free(&dyn); zval_ptr_dtor(&tmp); goto pipeline_read; } off+=(size_t)w; }
            smart_str_free(&dyn);
        }
        zval_ptr_dtor(&tmp);
    } ZEND_HASH_FOREACH_END();

pipeline_read:
    // 2) Read responses for each key in same order
    zend_ulong idx=0; zend_string *str_key; zval *val_key;
    ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(keys), idx, str_key, val_key) {
        (void)idx; // key order preserved
        zval tmp; ZVAL_COPY(&tmp, val_key); convert_to_string(&tmp);
        smart_str line={0}; if (tc_readline(c, &line)!=0 || !line.s) { smart_str_free(&line); zval_ptr_dtor(&tmp); break; }
        if (!zend_string_equals_literal(line.s, "NF") && ZSTR_LEN(line.s)>6 && strncmp(ZSTR_VAL(line.s), "VALUE\t",6)==0) {
            const char *payload=ZSTR_VAL(line.s)+6; size_t plen=ZSTR_LEN(line.s)-6; zval zv; tc_deserialize_to_zval(payload, plen, &zv); add_assoc_zval(return_value, Z_STRVAL(tmp), &zv);
        }
        smart_str_free(&line); zval_ptr_dtor(&tmp);
    } ZEND_HASH_FOREACH_END();
}

// AGGRESSIVE OPTIMIZATION: Pipelined bulk PUT for maximum throughput
static int tc_pipelined_bulk_put(tc_client_handle *h, HashTable *items, long ttl) {
    tc_tcp_conn *c = tc_get_conn(h); 
    if (!c) return 0;
    
    int item_count = zend_hash_num_elements(items);
    if (item_count == 0) return 0;
    
    // OPTIMIZATION: Allocate command arrays for pipelining
    const char **commands = emalloc(sizeof(char*) * item_count);
    size_t *cmd_lens = emalloc(sizeof(size_t) * item_count);
    char **cmd_buffers = emalloc(sizeof(char*) * item_count); // Track for cleanup
    smart_str *responses = emalloc(sizeof(smart_str) * item_count);
    
    int cmd_idx = 0;
    zend_string *k; zval *v;
    
    // PHASE 1: Build all commands in parallel
    ZEND_HASH_FOREACH_STR_KEY_VAL(items, k, v) {
        if (!k) continue;
        
        // Serialize value efficiently
        char inline_buf[256]; 
        smart_str sval = {0}; 
        const char *val_ptr = NULL; 
        size_t val_len = 0; 
        int n = tc_serialize_inline(v, inline_buf, sizeof(inline_buf), h->cfg.serializer);
        if (n >= 0) { 
            val_ptr = inline_buf; 
            val_len = (size_t)n; 
        } else { 
            tc_serialize_zval(&sval, v, h->cfg.serializer); 
            if (sval.s) { 
                val_ptr = ZSTR_VAL(sval.s); 
                val_len = ZSTR_LEN(sval.s);
            } 
        }
        
        // AGGRESSIVE OPTIMIZATION: Use ultra-fast command building
        int cmd_len = tc_build_put_cmd(c, ZSTR_VAL(k), ZSTR_LEN(k), val_ptr, val_len, "-", 1, ttl);
        if (cmd_len > 0) {
            // Copy command to permanent buffer
            char *cmd_buf = emalloc(cmd_len + 1);
            memcpy(cmd_buf, c->cmd_buf, cmd_len);
            cmd_buf[cmd_len] = '\0';
            
            commands[cmd_idx] = cmd_buf;
            cmd_lens[cmd_idx] = cmd_len;
            cmd_buffers[cmd_idx] = cmd_buf;
            cmd_idx++;
        }
        
        if (sval.s) smart_str_free(&sval);
        
    } ZEND_HASH_FOREACH_END();
    
    int success_count = 0;
    
    // PHASE 2: Execute pipelined commands
    if (cmd_idx > 0) {
        if (tc_tcp_pipeline_cmds(h, commands, cmd_lens, cmd_idx, responses) == 0) {
            // PHASE 3: Process responses
            for (int i = 0; i < cmd_idx; i++) {
                if (responses[i].s && zend_string_equals_literal(responses[i].s, "OK")) {
                    success_count++;
                }
                smart_str_free(&responses[i]);
            }
        }
    }
    
    // Cleanup
    for (int i = 0; i < cmd_idx; i++) {
        efree(cmd_buffers[i]);
    }
    efree(commands);
    efree(cmd_lens);
    efree(cmd_buffers);
    efree(responses);
    
    return success_count;
}

// Working bulk PUT using original reliable functions (fallback)
static int tc_working_bulk_put(tc_client_handle *h, HashTable *items, long ttl) {
    // Use pipelined version for better performance
    return tc_pipelined_bulk_put(h, items, ttl);
}

PHP_FUNCTION(tagcache_bulk_put) {
    zval *res; zval *items; zval *ttl=NULL; 
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "ra|z", &res, &items, &ttl)==FAILURE) RETURN_LONG(0);
    tc_client_handle *h = zend_fetch_resource(Z_RES_P(res), PHP_TAGCACHE_EXTNAME, le_tagcache_client); 
    if(!h) RETURN_LONG(0);
    if (Z_TYPE_P(items)!=IS_ARRAY) RETURN_LONG(0);
    
    long ttl_val = (ttl && Z_TYPE_P(ttl)==IS_LONG) ? Z_LVAL_P(ttl) : 0;
    int result = tc_working_bulk_put(h, Z_ARRVAL_P(items), ttl_val);
    RETURN_LONG(result);
}

// Simple search any: union of keys for each tag
PHP_FUNCTION(tagcache_search_any) {
    zval *res; zval *tags; if (zend_parse_parameters(ZEND_NUM_ARGS(), "ra", &res, &tags)==FAILURE) RETURN_NULL();
    tc_client_handle *h = zend_fetch_resource(Z_RES_P(res), PHP_TAGCACHE_EXTNAME, le_tagcache_client); if(!h) RETURN_NULL();
    HashTable *seen; ALLOC_HASHTABLE(seen); zend_hash_init(seen, 16, NULL, NULL, 0);
    array_init(return_value);
    zval *zt; ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(tags), zt) {
        zval tmp; ZVAL_COPY(&tmp, zt); convert_to_string(&tmp);
        smart_str cmd={0}; smart_str_appends(&cmd, "KEYS_BY_TAG\t"); smart_str_append(&cmd, Z_STR(tmp)); smart_str_appendc(&cmd,'\n'); smart_str_0(&cmd);
        smart_str resp={0}; if (tc_tcp_cmd(h, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s), &resp)==0 && resp.s && ZSTR_LEN(resp.s)>5 && strncmp(ZSTR_VAL(resp.s), "KEYS\t",5)==0) {
            char *list = ZSTR_VAL(resp.s)+5; if (*list!='\0') { char *dup=estrdup(list); char *p=dup; char *saveptr=NULL; char *tok; while((tok=strtok_r(p, ",", &saveptr))) { p=NULL; if (!zend_hash_str_exists(seen, tok, strlen(tok))) { add_next_index_string(return_value, tok); zend_hash_str_add_empty_element(seen, tok, strlen(tok)); } } efree(dup); }
        }
        smart_str_free(&cmd); smart_str_free(&resp); zval_ptr_dtor(&tmp);
    } ZEND_HASH_FOREACH_END();
    zend_hash_destroy(seen); FREE_HASHTABLE(seen);
}

// Simple search all: intersection
PHP_FUNCTION(tagcache_search_all) {
    zval *res; zval *tags; if (zend_parse_parameters(ZEND_NUM_ARGS(), "ra", &res, &tags)==FAILURE) RETURN_NULL();
    tc_client_handle *h = zend_fetch_resource(Z_RES_P(res), PHP_TAGCACHE_EXTNAME, le_tagcache_client); if(!h) RETURN_NULL();
    array_init(return_value);
    int tag_count = zend_hash_num_elements(Z_ARRVAL_P(tags)); if (tag_count==0) return;
    // First tag keys as base set
    zval *first = zend_hash_get_current_data(Z_ARRVAL_P(tags)); if(!first) return; zval first_c; ZVAL_COPY(&first_c, first); convert_to_string(&first_c);
    smart_str cmd={0}; smart_str_appends(&cmd, "KEYS_BY_TAG\t"); smart_str_append(&cmd, Z_STR(first_c)); smart_str_appendc(&cmd,'\n'); smart_str_0(&cmd);
    smart_str resp={0}; if (tc_tcp_cmd(h, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s), &resp)!=0 || !resp.s) { smart_str_free(&cmd); smart_str_free(&resp); zval_ptr_dtor(&first_c); return; }
    HashTable base; zend_hash_init(&base, 32, NULL, NULL, 0);
    if (ZSTR_LEN(resp.s)>5 && strncmp(ZSTR_VAL(resp.s), "KEYS\t",5)==0) { char *list=ZSTR_VAL(resp.s)+5; if(*list!='\0'){ char *dup=estrdup(list); char *p=dup; char *saveptr=NULL; char *tok; while((tok=strtok_r(p, ",", &saveptr))) { p=NULL; zend_hash_str_add_empty_element(&base, tok, strlen(tok)); } efree(dup);} }
    smart_str_free(&cmd); smart_str_free(&resp); zval_ptr_dtor(&first_c);
    // For each remaining tag, filter base
    HashTable *ht_tags = Z_ARRVAL_P(tags); HashPosition pos; zend_hash_internal_pointer_reset_ex(ht_tags, &pos); zend_hash_move_forward_ex(ht_tags, &pos); // skip first
    zval *zt;
    while ((zt = zend_hash_get_current_data_ex(ht_tags, &pos))) {
        zval tmp; ZVAL_COPY(&tmp, zt); convert_to_string(&tmp);
        smart_str cmd2={0}; smart_str_appends(&cmd2, "KEYS_BY_TAG\t"); smart_str_append(&cmd2, Z_STR(tmp)); smart_str_appendc(&cmd2,'\n'); smart_str_0(&cmd2);
        smart_str resp2={0}; if (tc_tcp_cmd(h, ZSTR_VAL(cmd2.s), ZSTR_LEN(cmd2.s), &resp2)==0 && resp2.s && ZSTR_LEN(resp2.s)>5 && strncmp(ZSTR_VAL(resp2.s), "KEYS\t",5)==0) {
            HashTable current; zend_hash_init(&current, 32, NULL, NULL, 0);
            char *list=ZSTR_VAL(resp2.s)+5; if(*list!='\0'){ char *dup=estrdup(list); char *p=dup; char *saveptr=NULL; char *tok; while((tok=strtok_r(p, ",", &saveptr))) { p=NULL; zend_hash_str_add_empty_element(&current, tok, strlen(tok)); } efree(dup);} 
            // iterate base and remove if not in current
            // Collect keys to remove then delete (avoid mutating while iterating positions incorrectly)
            zend_array *base_ht = &base; 
            HashTable remove; zend_hash_init(&remove, 8, NULL, NULL, 0);
            zend_string *bk; zend_ulong bidx;
            ZEND_HASH_FOREACH_KEY(base_ht, bidx, bk) {
                if (bk && !zend_hash_exists(&current, bk)) {
                    zend_hash_add_empty_element(&remove, bk);
                }
            } ZEND_HASH_FOREACH_END();
            ZEND_HASH_FOREACH_KEY(&remove, bidx, bk) {
                if (bk) zend_hash_del(&base, bk);
            } ZEND_HASH_FOREACH_END();
            zend_hash_destroy(&remove);
            zend_hash_destroy(&current);
        }
        smart_str_free(&cmd2); smart_str_free(&resp2); zval_ptr_dtor(&tmp);
        zend_hash_move_forward_ex(ht_tags, &pos);
    }
    // Export remaining base keys
    zend_string *fk; zend_ulong fidx; ZEND_HASH_FOREACH_KEY(&base, fidx, fk) { if (fk) add_next_index_string(return_value, ZSTR_VAL(fk)); } ZEND_HASH_FOREACH_END();
    zend_hash_destroy(&base);
}

PHP_FUNCTION(tagcache_close) {
    zval *res; if (zend_parse_parameters(ZEND_NUM_ARGS(), "r", &res)==FAILURE) RETURN_NULL();
    // Let resource dtor handle cleanup
    zend_list_close(Z_RES_P(res));
    RETURN_NULL();
}

// OO wrapper struct (must be fully defined before TagCache method bodies use its fields)
typedef struct _tagcache_obj_wrapper {
    tc_client_handle *h;
    zend_object std;
} tagcache_obj_wrapper;
static inline tagcache_obj_wrapper *php_tagcache_obj_fetch(zend_object *obj);
static zend_object *tagcache_obj_create(zend_class_entry *ce);

// --- OO method implementations ---
PHP_METHOD(TagCache, create) {
    zval *options=NULL; if (zend_parse_parameters(ZEND_NUM_ARGS(), "|a", &options)==FAILURE) RETURN_NULL();
    object_init_ex(return_value, tagcache_ce);
    // call underlying create
    zval rv; zval params[1]; int param_count=0; if (options) { ZVAL_COPY(&params[0], options); param_count=1; }
    // Directly reuse tagcache_create internal code (duplicated minimal for speed)
    tc_client_handle *h = ecalloc(1, sizeof(tc_client_handle));
    h->cfg.mode = TC_MODE_TCP; h->cfg.host = estrdup("127.0.0.1"); h->cfg.port=1984; h->cfg.http_base=estrdup("http://127.0.0.1:8080"); h->cfg.timeout_ms=5000; h->cfg.connect_timeout_ms=3000; h->cfg.pool_size=8; h->cfg.serializer=TC_SERIALIZE_PHP;
    if (options) {
        zval *z;
        if ((z=zend_hash_str_find(Z_ARRVAL_P(options), "mode", sizeof("mode")-1))) {
            if (Z_TYPE_P(z)==IS_STRING) {
                if (zend_string_equals_literal_ci(Z_STR_P(z), "http")) h->cfg.mode=TC_MODE_HTTP; else if (zend_string_equals_literal_ci(Z_STR_P(z), "auto")) h->cfg.mode=TC_MODE_AUTO; else h->cfg.mode=TC_MODE_TCP;
            }
        }
        if ((z=zend_hash_str_find(Z_ARRVAL_P(options), "host", sizeof("host")-1)) && Z_TYPE_P(z)==IS_STRING) { efree(h->cfg.host); h->cfg.host=estrdup(Z_STRVAL_P(z)); }
        if ((z=zend_hash_str_find(Z_ARRVAL_P(options), "port", sizeof("port")-1)) && Z_TYPE_P(z)==IS_LONG) { h->cfg.port=Z_LVAL_P(z); }
        if ((z=zend_hash_str_find(Z_ARRVAL_P(options), "http_base", sizeof("http_base")-1)) && Z_TYPE_P(z)==IS_STRING) { efree(h->cfg.http_base); h->cfg.http_base=estrdup(Z_STRVAL_P(z)); }
        if ((z=zend_hash_str_find(Z_ARRVAL_P(options), "timeout_ms", sizeof("timeout_ms")-1)) && Z_TYPE_P(z)==IS_LONG) { h->cfg.timeout_ms=Z_LVAL_P(z); }
        if ((z=zend_hash_str_find(Z_ARRVAL_P(options), "connect_timeout_ms", sizeof("connect_timeout_ms")-1)) && Z_TYPE_P(z)==IS_LONG) { h->cfg.connect_timeout_ms=Z_LVAL_P(z); }
        if ((z=zend_hash_str_find(Z_ARRVAL_P(options), "pool_size", sizeof("pool_size")-1)) && Z_TYPE_P(z)==IS_LONG) { h->cfg.pool_size=Z_LVAL_P(z); }
        
        // Parse serializer option  
        if ((z=zend_hash_str_find(Z_ARRVAL_P(options), "serializer", sizeof("serializer")-1)) && Z_TYPE_P(z)==IS_STRING) {
            if (zend_string_equals_literal_ci(Z_STR_P(z), "php")) h->cfg.serializer=TC_SERIALIZE_PHP;
            else if (zend_string_equals_literal_ci(Z_STR_P(z), "igbinary")) h->cfg.serializer=TC_SERIALIZE_IGBINARY;
            else if (zend_string_equals_literal_ci(Z_STR_P(z), "msgpack")) h->cfg.serializer=TC_SERIALIZE_MSGPACK;
            else if (zend_string_equals_literal_ci(Z_STR_P(z), "native")) h->cfg.serializer=TC_SERIALIZE_NATIVE;
            else h->cfg.serializer=TC_SERIALIZE_PHP;
        }
    }
    if (h->cfg.mode != TC_MODE_TCP) {
        php_error_docref(NULL, E_NOTICE, "HTTP/AUTO mode not yet implemented; falling back to TCP");
        h->cfg.mode = TC_MODE_TCP;
    }
    h->pool_len = h->cfg.pool_size; h->pool = ecalloc(h->pool_len, sizeof(tc_tcp_conn));
    for(int i=0;i<h->pool_len;i++){
        h->pool[i].fd = tc_tcp_connect_raw(h->cfg.host, h->cfg.port, h->cfg.connect_timeout_ms);
        if (h->pool[i].fd>=0) { 
            // Setup keep-alive if enabled
            tc_setup_keep_alive(h->pool[i].fd, &h->cfg);
            
            h->pool[i].healthy=true; h->pool[i].created_at=h->pool[i].last_used=now_mono(); 
        }
        h->pool[i].rlen=0; h->pool[i].rpos=0;
    }
    tagcache_obj_wrapper *ow = php_tagcache_obj_fetch(Z_OBJ_P(return_value));
    ow->h = h;
}

PHP_METHOD(TagCache, set) {
    char *key; size_t key_len; zval *value; zval *tags=NULL; zval *ttl=NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "sz|az", &key, &key_len, &value, &tags, &ttl)==FAILURE) RETURN_FALSE;
    tagcache_obj_wrapper *ow = php_tagcache_obj_fetch(Z_OBJ_P(getThis())); if(!ow->h) RETURN_FALSE;
    char inline_buf[256]; smart_str sval={0}; const char *val_ptr=NULL; size_t val_len=0; int n=tc_serialize_inline(value, inline_buf, sizeof(inline_buf), ow->h->cfg.serializer);
    if (n>=0) { val_ptr=inline_buf; val_len=(size_t)n; } else { tc_serialize_zval(&sval, value, ow->h->cfg.serializer); if (sval.s){ val_ptr=ZSTR_VAL(sval.s); val_len=ZSTR_LEN(sval.s);} }
    char cmd_buf[512]; size_t pos=0; bool used_stack=true;
    #define APPEND_LIT2(lit) do { size_t ll = sizeof(lit)-1; if (pos+ll < sizeof(cmd_buf)) { memcpy(cmd_buf+pos, lit, ll); pos+=ll; } else { used_stack=false; } } while(0)
    #define APPEND_RAW2(ptr,len) do { size_t ll=(len); if (pos+ll < sizeof(cmd_buf)) { memcpy(cmd_buf+pos, (ptr), ll); pos+=ll; } else { used_stack=false; } } while(0)
    APPEND_LIT2("PUT\t"); if (used_stack) APPEND_RAW2(key, key_len); if (used_stack) APPEND_LIT2("\t");
    if (used_stack) {
        if (ttl && Z_TYPE_P(ttl)==IS_LONG) { char num[32]; int wn=snprintf(num,sizeof(num),"%lld", (long long)Z_LVAL_P(ttl)); if(wn<=0 || (size_t)wn>=sizeof(num) || pos+(size_t)wn>=sizeof(cmd_buf)) used_stack=false; else { memcpy(cmd_buf+pos,num,(size_t)wn); pos+=(size_t)wn; } }
        else APPEND_LIT2("-");
    }
    if (used_stack) APPEND_LIT2("\t");
    if (used_stack) {
        if (tags && Z_TYPE_P(tags)==IS_ARRAY && zend_hash_num_elements(Z_ARRVAL_P(tags))>0) {
            int first=1; zval *zt; ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(tags), zt) { if(!first) APPEND_LIT2(","); first=0; zval tmp; ZVAL_COPY(&tmp, zt); convert_to_string(&tmp); if (used_stack) APPEND_RAW2(Z_STRVAL(tmp), Z_STRLEN(tmp)); zval_ptr_dtor(&tmp); if(!used_stack) break; } ZEND_HASH_FOREACH_END();
        } else APPEND_LIT2("-");
    }
    if (used_stack) APPEND_LIT2("\t"); if (used_stack && val_ptr && val_len) APPEND_RAW2(val_ptr, val_len); if (used_stack) APPEND_LIT2("\n");
    smart_str resp={0};
    if (used_stack) {
        tc_tcp_conn *c = tc_get_conn(ow->h);
        if (!c) used_stack=false; else {
            if (tc_write(c, cmd_buf, pos)!=0 || tc_flush(c)!=0) used_stack=false;
            if (used_stack) { smart_str line={0}; if (tc_readline(c,&line)!=0) { smart_str_free(&line); if(sval.s) smart_str_free(&sval); RETURN_FALSE; } resp=line; bool ok=(resp.s && zend_string_equals_literal(resp.s, "OK")); smart_str_free(&resp); if(sval.s) smart_str_free(&sval); RETURN_BOOL(ok); }
        }
    }
    // fallback smart_str
    smart_str cmd={0}; smart_str_appends(&cmd, "PUT\t"); smart_str_appendl(&cmd, key, key_len); smart_str_appendc(&cmd,'\t');
    if (ttl && Z_TYPE_P(ttl)==IS_LONG) smart_str_append_long(&cmd, Z_LVAL_P(ttl)); else smart_str_appends(&cmd, "-");
    smart_str_appendc(&cmd,'\t');
    if (tags && Z_TYPE_P(tags)==IS_ARRAY && zend_hash_num_elements(Z_ARRVAL_P(tags))>0) { int first=1; zval *zt; ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(tags), zt) { if(!first) smart_str_appendc(&cmd, ','); first=0; convert_to_string_ex(zt); smart_str_append(&cmd, Z_STR_P(zt)); } ZEND_HASH_FOREACH_END(); } else smart_str_appends(&cmd, "-");
    smart_str_appendc(&cmd,'\t'); if (val_ptr && val_len) smart_str_appendl(&cmd, val_ptr, val_len); smart_str_appendc(&cmd,'\n'); smart_str_0(&cmd);
    if (tc_tcp_cmd(ow->h, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s), &resp)!=0) { smart_str_free(&cmd); smart_str_free(&resp); if(sval.s) smart_str_free(&sval); RETURN_FALSE; }
    bool ok=(resp.s && zend_string_equals_literal(resp.s, "OK")); smart_str_free(&cmd); smart_str_free(&resp); if (sval.s) smart_str_free(&sval); RETURN_BOOL(ok);
}

PHP_METHOD(TagCache, get) {
    char *key; size_t key_len; 
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &key, &key_len)==FAILURE) RETURN_NULL();
    tagcache_obj_wrapper *ow = php_tagcache_obj_fetch(Z_OBJ_P(getThis())); 
    if(!ow->h) RETURN_NULL();
    
    // Use ultra-fast path
    smart_str result = {0};
    int rc = tc_fast_get(ow->h, key, key_len, &result);
    if (rc == 1) { RETURN_NULL(); } // not found
    if (rc != 0) { RETURN_NULL(); } // error
    
    const char *payload = ZSTR_VAL(result.s) + 6; // skip "VALUE\t"
    size_t plen = ZSTR_LEN(result.s) - 6;
    tc_deserialize_to_zval(payload, plen, return_value);
    smart_str_free(&result);
}

PHP_METHOD(TagCache, delete) {
    char *key; size_t key_len; if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &key, &key_len)==FAILURE) RETURN_FALSE;
    tagcache_obj_wrapper *ow = php_tagcache_obj_fetch(Z_OBJ_P(getThis())); if(!ow->h) RETURN_FALSE;
    smart_str cmd={0}; smart_str_appends(&cmd, "DEL\t"); smart_str_appendl(&cmd, key, key_len); smart_str_appendc(&cmd,'\n'); smart_str_0(&cmd);
    smart_str resp={0}; if (tc_tcp_cmd(ow->h, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s), &resp)!=0) { smart_str_free(&cmd); smart_str_free(&resp); RETURN_FALSE; }
    bool ok=false; if (resp.s) { if (strstr(ZSTR_VAL(resp.s), "ok")) ok=true; }
    smart_str_free(&cmd); smart_str_free(&resp); RETURN_BOOL(ok);
}

PHP_METHOD(TagCache, invalidateTag) {
    char *tag; size_t tag_len; if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &tag, &tag_len)==FAILURE) RETURN_LONG(0);
    tagcache_obj_wrapper *ow = php_tagcache_obj_fetch(Z_OBJ_P(getThis())); if(!ow->h) RETURN_LONG(0);
    smart_str cmd={0}; smart_str_appends(&cmd, "INV_TAG\t"); smart_str_appendl(&cmd, tag, tag_len); smart_str_appendc(&cmd,'\n'); smart_str_0(&cmd);
    smart_str resp={0}; if (tc_tcp_cmd(ow->h, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s), &resp)!=0) { smart_str_free(&cmd); smart_str_free(&resp); RETURN_LONG(0); }
    long count=0; if (resp.s && ZSTR_LEN(resp.s)>8 && strncmp(ZSTR_VAL(resp.s), "INV_TAG\t",8)==0) { count = atol(ZSTR_VAL(resp.s)+8); }
    smart_str_free(&cmd); smart_str_free(&resp); RETURN_LONG(count);
}

PHP_METHOD(TagCache, invalidateTagsAny) {
    zval *tags_array; 
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "a", &tags_array) == FAILURE) RETURN_LONG(0);
    tagcache_obj_wrapper *ow = php_tagcache_obj_fetch(Z_OBJ_P(getThis())); 
    if (!ow->h) RETURN_LONG(0);
    
    smart_str cmd = {0}; 
    smart_str_appends(&cmd, "INV_TAGS_ANY\t");
    
    zval *entry; 
    int first = 1;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(tags_array), entry) {
        if (Z_TYPE_P(entry) == IS_STRING) {
            if (!first) smart_str_appendc(&cmd, ',');
            smart_str_appendl(&cmd, Z_STRVAL_P(entry), Z_STRLEN_P(entry));
            first = 0;
        }
    } ZEND_HASH_FOREACH_END();
    
    smart_str_appendc(&cmd, '\n'); 
    smart_str_0(&cmd);
    
    smart_str resp = {0}; 
    if (tc_tcp_cmd(ow->h, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s), &resp) != 0) { 
        smart_str_free(&cmd); 
        smart_str_free(&resp); 
        RETURN_LONG(0); 
    }
    
    long count = 0; 
    if (resp.s && ZSTR_LEN(resp.s) > 13 && strncmp(ZSTR_VAL(resp.s), "INV_TAGS_ANY\t", 13) == 0) { 
        count = atol(ZSTR_VAL(resp.s) + 13); 
    }
    
    smart_str_free(&cmd); 
    smart_str_free(&resp); 
    RETURN_LONG(count);
}

PHP_METHOD(TagCache, invalidateTagsAll) {
    zval *tags_array; 
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "a", &tags_array) == FAILURE) RETURN_LONG(0);
    tagcache_obj_wrapper *ow = php_tagcache_obj_fetch(Z_OBJ_P(getThis())); 
    if (!ow->h) RETURN_LONG(0);
    
    smart_str cmd = {0}; 
    smart_str_appends(&cmd, "INV_TAGS_ALL\t");
    
    zval *entry; 
    int first = 1;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(tags_array), entry) {
        if (Z_TYPE_P(entry) == IS_STRING) {
            if (!first) smart_str_appendc(&cmd, ',');
            smart_str_appendl(&cmd, Z_STRVAL_P(entry), Z_STRLEN_P(entry));
            first = 0;
        }
    } ZEND_HASH_FOREACH_END();
    
    smart_str_appendc(&cmd, '\n'); 
    smart_str_0(&cmd);
    
    smart_str resp = {0}; 
    if (tc_tcp_cmd(ow->h, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s), &resp) != 0) { 
        smart_str_free(&cmd); 
        smart_str_free(&resp); 
        RETURN_LONG(0); 
    }
    
    long count = 0; 
    if (resp.s && ZSTR_LEN(resp.s) > 13 && strncmp(ZSTR_VAL(resp.s), "INV_TAGS_ALL\t", 13) == 0) { 
        count = atol(ZSTR_VAL(resp.s) + 13); 
    }
    
    smart_str_free(&cmd); 
    smart_str_free(&resp); 
    RETURN_LONG(count);
}

PHP_METHOD(TagCache, invalidateKeys) {
    zval *keys_array; 
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "a", &keys_array) == FAILURE) RETURN_LONG(0);
    tagcache_obj_wrapper *ow = php_tagcache_obj_fetch(Z_OBJ_P(getThis())); 
    if (!ow->h) RETURN_LONG(0);
    
    smart_str cmd = {0}; 
    smart_str_appends(&cmd, "INV_KEYS\t");
    
    zval *entry; 
    int first = 1;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(keys_array), entry) {
        if (Z_TYPE_P(entry) == IS_STRING) {
            if (!first) smart_str_appendc(&cmd, ',');
            smart_str_appendl(&cmd, Z_STRVAL_P(entry), Z_STRLEN_P(entry));
            first = 0;
        }
    } ZEND_HASH_FOREACH_END();
    
    smart_str_appendc(&cmd, '\n'); 
    smart_str_0(&cmd);
    
    smart_str resp = {0}; 
    if (tc_tcp_cmd(ow->h, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s), &resp) != 0) { 
        smart_str_free(&cmd); 
        smart_str_free(&resp); 
        RETURN_LONG(0); 
    }
    
    long count = 0; 
    if (resp.s && ZSTR_LEN(resp.s) > 9 && strncmp(ZSTR_VAL(resp.s), "INV_KEYS\t", 9) == 0) { 
        count = atol(ZSTR_VAL(resp.s) + 9); 
    }
    
    smart_str_free(&cmd); 
    smart_str_free(&resp); 
    RETURN_LONG(count);
}

PHP_METHOD(TagCache, keysByTag) {
    char *tag; size_t tag_len; if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &tag, &tag_len)==FAILURE) RETURN_NULL();
    tagcache_obj_wrapper *ow = php_tagcache_obj_fetch(Z_OBJ_P(getThis())); if(!ow->h) RETURN_NULL();
    array_init(return_value);
    smart_str cmd={0}; smart_str_appends(&cmd, "KEYS_BY_TAG\t"); smart_str_appendl(&cmd, tag, tag_len); smart_str_appendc(&cmd,'\n'); smart_str_0(&cmd);
    smart_str resp={0}; if (tc_tcp_cmd(ow->h, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s), &resp)!=0) { smart_str_free(&cmd); smart_str_free(&resp); return; }
    if (!resp.s) { smart_str_free(&cmd); smart_str_free(&resp); return; }
    if (ZSTR_LEN(resp.s) >=5 && strncmp(ZSTR_VAL(resp.s), "KEYS\t",5)==0) {
        char *list = ZSTR_VAL(resp.s)+5; if (*list=='\0') { smart_str_free(&cmd); smart_str_free(&resp); return; }
        char *dup = estrdup(list); char *p=dup; char *saveptr=NULL; char *tok;
        while ((tok=strtok_r(p, ",", &saveptr))) { p=NULL; add_next_index_string(return_value, tok); }
        efree(dup);
    }
    smart_str_free(&cmd); smart_str_free(&resp);
}

PHP_METHOD(TagCache, mGet) {
    zval *keys; if (zend_parse_parameters(ZEND_NUM_ARGS(), "a", &keys)==FAILURE) RETURN_NULL();
    tagcache_obj_wrapper *ow = php_tagcache_obj_fetch(Z_OBJ_P(getThis())); if(!ow->h) RETURN_NULL();
    array_init(return_value);
    uint32_t key_count = zend_hash_num_elements(Z_ARRVAL_P(keys)); if (key_count==0) return;
    tc_tcp_conn *c = tc_get_conn(ow->h); if(!c) return;
    // Send all GET commands
    zval *zk; ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(keys), zk) {
        zval tmp; ZVAL_COPY(&tmp, zk); convert_to_string(&tmp);
        char header[256]; int n = snprintf(header, sizeof(header), "GET\t%.*s\n", (int)Z_STRLEN(tmp), Z_STRVAL(tmp));
        if (n>0 && (size_t)n < sizeof(header)) {
            size_t off=0; while(off<(size_t)n){ ssize_t w=send(c->fd, header+off, (size_t)n-off, 0); if(w<=0){ c->healthy=false; zval_ptr_dtor(&tmp); goto pipeline_read_oo; } off+=(size_t)w; }
        } else {
            smart_str dyn={0}; smart_str_appends(&dyn, "GET\t"); smart_str_append(&dyn, Z_STR(tmp)); smart_str_appendc(&dyn,'\n'); smart_str_0(&dyn);
            size_t off=0; while(off<ZSTR_LEN(dyn.s)){ ssize_t w=send(c->fd, ZSTR_VAL(dyn.s)+off, ZSTR_LEN(dyn.s)-off, 0); if(w<=0){ c->healthy=false; smart_str_free(&dyn); zval_ptr_dtor(&tmp); goto pipeline_read_oo; } off+=(size_t)w; }
            smart_str_free(&dyn);
        }
        zval_ptr_dtor(&tmp);
    } ZEND_HASH_FOREACH_END();
pipeline_read_oo:
    // Read responses
    zend_ulong idx=0; zend_string *str_key; zval *val_key; ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(keys), idx, str_key, val_key) {
        (void)idx;
        zval tmp; ZVAL_COPY(&tmp, val_key); convert_to_string(&tmp);
        smart_str line={0}; if (tc_readline(c, &line)!=0 || !line.s) { smart_str_free(&line); zval_ptr_dtor(&tmp); break; }
        if (!zend_string_equals_literal(line.s, "NF") && ZSTR_LEN(line.s)>6 && strncmp(ZSTR_VAL(line.s), "VALUE\t",6)==0) {
            const char *payload=ZSTR_VAL(line.s)+6; size_t plen=ZSTR_LEN(line.s)-6; zval zv; tc_deserialize_to_zval(payload, plen, &zv); add_assoc_zval(return_value, Z_STRVAL(tmp), &zv);
        }
        smart_str_free(&line); zval_ptr_dtor(&tmp);
    } ZEND_HASH_FOREACH_END();
}

PHP_METHOD(TagCache, mSet) {
    zval *items; zval *ttl=NULL; if (zend_parse_parameters(ZEND_NUM_ARGS(), "a|z", &items, &ttl)==FAILURE) RETURN_LONG(0);
    tagcache_obj_wrapper *ow = php_tagcache_obj_fetch(Z_OBJ_P(getThis())); if(!ow->h) RETURN_LONG(0);
    if (Z_TYPE_P(items)!=IS_ARRAY) RETURN_LONG(0);
    HashTable *ht = Z_ARRVAL_P(items); zend_string *k; zval *v; long count=0;
    tc_tcp_conn *c = tc_get_conn(ow->h); if(!c) RETURN_LONG(0);
    // send all
    ZEND_HASH_FOREACH_STR_KEY_VAL(ht, k, v) {
        if (!k) continue; char inline_buf[256]; smart_str sval={0}; const char *val_ptr=NULL; size_t val_len=0; int in=tc_serialize_inline(v, inline_buf, sizeof(inline_buf), ow->h->cfg.serializer); if(in>=0){ val_ptr=inline_buf; val_len=(size_t)in; } else { tc_serialize_zval(&sval,v, ow->h->cfg.serializer); if(sval.s){ val_ptr=ZSTR_VAL(sval.s); val_len=ZSTR_LEN(sval.s);} }
        smart_str cmd={0}; smart_str_appends(&cmd, "PUT\t"); smart_str_append(&cmd, k); smart_str_appendc(&cmd,'\t'); if (ttl && Z_TYPE_P(ttl)==IS_LONG) smart_str_append_long(&cmd, Z_LVAL_P(ttl)); else smart_str_appends(&cmd, "-"); smart_str_appendc(&cmd,'\t'); smart_str_appends(&cmd, "-"); smart_str_appendc(&cmd,'\t'); if (val_ptr && val_len) smart_str_appendl(&cmd, val_ptr, val_len); smart_str_appendc(&cmd,'\n'); smart_str_0(&cmd);
    if (tc_write(c, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s))!=0) { c->healthy=false; }
        smart_str_free(&cmd); if(sval.s) smart_str_free(&sval); if(!c->healthy) break; }
    ZEND_HASH_FOREACH_END(); if(!c->healthy) RETURN_LONG(0);
    // read responses
    if (c->healthy && tc_flush(c)!=0) RETURN_LONG(0);
    ZEND_HASH_FOREACH_STR_KEY_VAL(ht, k, v) { if(!k) continue; smart_str line={0}; if(tc_readline(c,&line)!=0 || !line.s){ smart_str_free(&line); break; } if (zend_string_equals_literal(line.s, "OK")) count++; smart_str_free(&line);} ZEND_HASH_FOREACH_END();
    RETURN_LONG(count);
}

PHP_METHOD(TagCache, stats) {
    tagcache_obj_wrapper *ow = php_tagcache_obj_fetch(Z_OBJ_P(getThis())); if(!ow->h) RETURN_NULL();
    array_init(return_value);
    smart_str cmd={0}; smart_str_appends(&cmd, "STATS\n"); smart_str_0(&cmd); smart_str resp={0};
    if (tc_tcp_cmd(ow->h, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s), &resp)!=0) { smart_str_free(&cmd); smart_str_free(&resp); return; }
    if (resp.s && ZSTR_LEN(resp.s)>6 && strncmp(ZSTR_VAL(resp.s), "STATS\t",6)==0) {
        char *dup=estrdup(ZSTR_VAL(resp.s)+6); char *p=dup; char *tok; int idx=0; char *saveptr=NULL; while((tok=strtok_r(p, "\t", &saveptr))){ p=NULL; switch(idx){ case 0: add_assoc_long(return_value, "hits", atol(tok)); break; case 1: add_assoc_long(return_value, "misses", atol(tok)); break; case 2: add_assoc_long(return_value, "puts", atol(tok)); break; case 3: add_assoc_long(return_value, "invalidations", atol(tok)); break; case 4: add_assoc_double(return_value, "hit_ratio", atof(tok)); break; } idx++; } efree(dup);
    }
    add_assoc_string(return_value, "transport", "tcp");
    smart_str_free(&cmd); smart_str_free(&resp);
}

PHP_METHOD(TagCache, flush) {
    tagcache_obj_wrapper *ow = php_tagcache_obj_fetch(Z_OBJ_P(getThis())); if(!ow->h) RETURN_LONG(0);
    smart_str cmd={0}; smart_str_appends(&cmd, "FLUSH\n"); smart_str_0(&cmd); smart_str resp={0};
    if (tc_tcp_cmd(ow->h, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s), &resp)!=0) { smart_str_free(&cmd); smart_str_free(&resp); RETURN_LONG(0); }
    long count=0; if (resp.s && ZSTR_LEN(resp.s)>6 && strncmp(ZSTR_VAL(resp.s), "FLUSH\t",6)==0) { count = atol(ZSTR_VAL(resp.s)+6); }
    smart_str_free(&cmd); smart_str_free(&resp); RETURN_LONG(count);
}

PHP_METHOD(TagCache, searchAny) {
    zval *tags; if (zend_parse_parameters(ZEND_NUM_ARGS(), "a", &tags)==FAILURE) RETURN_NULL();
    tagcache_obj_wrapper *ow = php_tagcache_obj_fetch(Z_OBJ_P(getThis())); if(!ow->h) RETURN_NULL();
    HashTable *seen; ALLOC_HASHTABLE(seen); zend_hash_init(seen, 16, NULL, NULL, 0);
    array_init(return_value);
    zval *zt; ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(tags), zt) {
        zval tmp; ZVAL_COPY(&tmp, zt); convert_to_string(&tmp);
        smart_str cmd={0}; smart_str_appends(&cmd, "KEYS_BY_TAG\t"); smart_str_append(&cmd, Z_STR(tmp)); smart_str_appendc(&cmd,'\n'); smart_str_0(&cmd);
        smart_str resp={0}; if (tc_tcp_cmd(ow->h, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s), &resp)==0 && resp.s && ZSTR_LEN(resp.s)>5 && strncmp(ZSTR_VAL(resp.s), "KEYS\t",5)==0) {
            char *list = ZSTR_VAL(resp.s)+5; if (*list!='\0') { char *dup=estrdup(list); char *p=dup; char *saveptr=NULL; char *tok; while((tok=strtok_r(p, ",", &saveptr))) { p=NULL; if (!zend_hash_str_exists(seen, tok, strlen(tok))) { add_next_index_string(return_value, tok); zend_hash_str_add_empty_element(seen, tok, strlen(tok)); } } efree(dup); }
        }
        smart_str_free(&cmd); smart_str_free(&resp); zval_ptr_dtor(&tmp);
    } ZEND_HASH_FOREACH_END();
    zend_hash_destroy(seen); FREE_HASHTABLE(seen);
}

PHP_METHOD(TagCache, searchAll) {
    zval *tags; if (zend_parse_parameters(ZEND_NUM_ARGS(), "a", &tags)==FAILURE) RETURN_NULL();
    tagcache_obj_wrapper *ow = php_tagcache_obj_fetch(Z_OBJ_P(getThis())); if(!ow->h) RETURN_NULL();
    array_init(return_value);
    int tag_count = zend_hash_num_elements(Z_ARRVAL_P(tags)); if (tag_count==0) return;
    zval *first = zend_hash_get_current_data(Z_ARRVAL_P(tags)); if(!first) return; zval first_c; ZVAL_COPY(&first_c, first); convert_to_string(&first_c);
    smart_str cmd={0}; smart_str_appends(&cmd, "KEYS_BY_TAG\t"); smart_str_append(&cmd, Z_STR(first_c)); smart_str_appendc(&cmd,'\n'); smart_str_0(&cmd);
    smart_str resp={0}; if (tc_tcp_cmd(ow->h, ZSTR_VAL(cmd.s), ZSTR_LEN(cmd.s), &resp)!=0 || !resp.s) { smart_str_free(&cmd); smart_str_free(&resp); zval_ptr_dtor(&first_c); return; }
    HashTable base; zend_hash_init(&base, 32, NULL, NULL, 0);
    if (ZSTR_LEN(resp.s)>5 && strncmp(ZSTR_VAL(resp.s), "KEYS\t",5)==0) { char *list=ZSTR_VAL(resp.s)+5; if(*list!='\0'){ char *dup=estrdup(list); char *p=dup; char *saveptr=NULL; char *tok; while((tok=strtok_r(p, ",", &saveptr))) { p=NULL; zend_hash_str_add_empty_element(&base, tok, strlen(tok)); } efree(dup);} }
    smart_str_free(&cmd); smart_str_free(&resp); zval_ptr_dtor(&first_c);
    HashTable *ht_tags = Z_ARRVAL_P(tags); HashPosition pos; zend_hash_internal_pointer_reset_ex(ht_tags, &pos); zend_hash_move_forward_ex(ht_tags, &pos);
    zval *zt;
    while ((zt = zend_hash_get_current_data_ex(ht_tags, &pos))) {
        zval tmp; ZVAL_COPY(&tmp, zt); convert_to_string(&tmp);
        smart_str cmd2={0}; smart_str_appends(&cmd2, "KEYS_BY_TAG\t"); smart_str_append(&cmd2, Z_STR(tmp)); smart_str_appendc(&cmd2,'\n'); smart_str_0(&cmd2);
        smart_str resp2={0}; if (tc_tcp_cmd(ow->h, ZSTR_VAL(cmd2.s), ZSTR_LEN(cmd2.s), &resp2)==0 && resp2.s && ZSTR_LEN(resp2.s)>5 && strncmp(ZSTR_VAL(resp2.s), "KEYS\t",5)==0) {
            HashTable current; zend_hash_init(&current, 32, NULL, NULL, 0);
            char *list=ZSTR_VAL(resp2.s)+5; if(*list!='\0'){ char *dup=estrdup(list); char *p=dup; char *saveptr=NULL; char *tok; while((tok=strtok_r(p, ",", &saveptr))) { p=NULL; zend_hash_str_add_empty_element(&current, tok, strlen(tok)); } efree(dup);} 
            HashTable remove; zend_hash_init(&remove, 8, NULL, NULL, 0);
            zend_string *bk; zend_ulong bidx;
            ZEND_HASH_FOREACH_KEY(&base, bidx, bk) { if (bk && !zend_hash_exists(&current, bk)) { zend_hash_add_empty_element(&remove, bk); } } ZEND_HASH_FOREACH_END();
            ZEND_HASH_FOREACH_KEY(&remove, bidx, bk) { if (bk) zend_hash_del(&base, bk); } ZEND_HASH_FOREACH_END();
            zend_hash_destroy(&remove); zend_hash_destroy(&current);
        }
        smart_str_free(&cmd2); smart_str_free(&resp2); zval_ptr_dtor(&tmp); zend_hash_move_forward_ex(ht_tags, &pos);
    }
    zend_string *fk; zend_ulong fidx; ZEND_HASH_FOREACH_KEY(&base, fidx, fk) { if (fk) add_next_index_string(return_value, ZSTR_VAL(fk)); } ZEND_HASH_FOREACH_END();
    zend_hash_destroy(&base);
}

PHP_METHOD(TagCache, close) {
    tagcache_obj_wrapper *ow = php_tagcache_obj_fetch(Z_OBJ_P(getThis())); if(!ow->h) RETURN_NULL();
    // free underlying handle
    if (ow->h) {
        if (ow->h->pool) { for(int i=0;i<ow->h->pool_len;i++){ if (ow->h->pool[i].fd>=0) close(ow->h->pool[i].fd); } efree(ow->h->pool); }
        if (ow->h->cfg.host) efree(ow->h->cfg.host);
        if (ow->h->cfg.http_base) efree(ow->h->cfg.http_base);
        efree(ow->h); ow->h=NULL;
    }
    RETURN_NULL();
}

// (Other functions will be added in subsequent iterations)

// Function entries
/* duplicate helper definitions removed */
static inline tagcache_obj_wrapper *php_tagcache_obj_fetch(zend_object *obj) {
    return (tagcache_obj_wrapper*)((char*)(obj) - XtOffsetOf(tagcache_obj_wrapper, std));
}
static zend_object *tagcache_obj_create(zend_class_entry *ce) {
    tagcache_obj_wrapper *obj = zend_object_alloc(sizeof(tagcache_obj_wrapper), ce);
    obj->h = NULL;
    zend_object_std_init(&obj->std, ce);
    object_properties_init(&obj->std, ce);
    obj->std.handlers = zend_get_std_object_handlers();
    return &obj->std;
}

static const zend_function_entry tagcache_functions[] = {
    PHP_FE(tagcache_create, arginfo_tagcache_create)
    PHP_FE(tagcache_put, arginfo_tagcache_put)
    PHP_FE(tagcache_get, arginfo_tagcache_get)
    PHP_FE(tagcache_delete, arginfo_tagcache_delete)
    PHP_FE(tagcache_invalidate_tag, arginfo_tagcache_invalidate_tag)
    PHP_FE(tagcache_invalidate_tags_any, arginfo_tagcache_invalidate_tags)
    PHP_FE(tagcache_invalidate_tags_all, arginfo_tagcache_invalidate_tags)
    PHP_FE(tagcache_invalidate_keys, arginfo_tagcache_invalidate_keys)
    PHP_FE(tagcache_keys_by_tag, arginfo_tagcache_keys_by_tag)
    PHP_FE(tagcache_bulk_get, arginfo_tagcache_bulk_get)
    PHP_FE(tagcache_bulk_put, arginfo_tagcache_bulk_put)
    PHP_FE(tagcache_stats, arginfo_tagcache_stats)
    PHP_FE(tagcache_flush, arginfo_tagcache_flush)
    PHP_FE(tagcache_search_any, arginfo_tagcache_search_any)
    PHP_FE(tagcache_search_all, arginfo_tagcache_search_all)
    PHP_FE(tagcache_close, arginfo_tagcache_close)
    PHP_FE_END
};

// Forward declarations for OO methods
PHP_METHOD(TagCache, create);
PHP_METHOD(TagCache, set);
PHP_METHOD(TagCache, get);
PHP_METHOD(TagCache, delete);
PHP_METHOD(TagCache, invalidateTag);
PHP_METHOD(TagCache, invalidateTagsAny);
PHP_METHOD(TagCache, invalidateTagsAll);
PHP_METHOD(TagCache, invalidateKeys);
PHP_METHOD(TagCache, keysByTag);
PHP_METHOD(TagCache, mGet);
PHP_METHOD(TagCache, mSet);
PHP_METHOD(TagCache, stats);
PHP_METHOD(TagCache, flush);
PHP_METHOD(TagCache, searchAny);
PHP_METHOD(TagCache, searchAll);
PHP_METHOD(TagCache, close);

ZEND_BEGIN_ARG_INFO_EX(arginfo_tc_create_static, 0, 0, 0)
    ZEND_ARG_ARRAY_INFO(0, options, 1)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_INFO_EX(arginfo_tc_set, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, key, IS_STRING, 0)
    ZEND_ARG_INFO(0, value)
    ZEND_ARG_ARRAY_INFO(0, tags, 1)
    ZEND_ARG_TYPE_INFO(0, ttl_ms, IS_LONG, 1)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_INFO_EX(arginfo_tc_key, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, key, IS_STRING, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_INFO_EX(arginfo_tc_tag, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, tag, IS_STRING, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_INFO_EX(arginfo_tc_tags_array, 0, 0, 1)
    ZEND_ARG_ARRAY_INFO(0, tags, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_INFO_EX(arginfo_tc_keys_array, 0, 0, 1)
    ZEND_ARG_ARRAY_INFO(0, keys, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_INFO_EX(arginfo_tc_void, 0, 0, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry tagcache_class_methods[] = {
    ZEND_ME(TagCache, create, arginfo_tc_create_static, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
    ZEND_ME(TagCache, set, arginfo_tc_set, ZEND_ACC_PUBLIC)
    ZEND_ME(TagCache, get, arginfo_tc_key, ZEND_ACC_PUBLIC)
    ZEND_ME(TagCache, delete, arginfo_tc_key, ZEND_ACC_PUBLIC)
    ZEND_ME(TagCache, invalidateTag, arginfo_tc_tag, ZEND_ACC_PUBLIC)
    ZEND_ME(TagCache, invalidateTagsAny, arginfo_tc_tags_array, ZEND_ACC_PUBLIC)
    ZEND_ME(TagCache, invalidateTagsAll, arginfo_tc_tags_array, ZEND_ACC_PUBLIC)
    ZEND_ME(TagCache, invalidateKeys, arginfo_tc_keys_array, ZEND_ACC_PUBLIC)
    ZEND_ME(TagCache, keysByTag, arginfo_tc_tag, ZEND_ACC_PUBLIC)
    ZEND_ME(TagCache, mGet, arginfo_tc_keys_array, ZEND_ACC_PUBLIC)
    ZEND_ME(TagCache, mSet, arginfo_tagcache_bulk_put, ZEND_ACC_PUBLIC)
    ZEND_ME(TagCache, stats, arginfo_tc_void, ZEND_ACC_PUBLIC)
    ZEND_ME(TagCache, flush, arginfo_tc_void, ZEND_ACC_PUBLIC)
    ZEND_ME(TagCache, searchAny, arginfo_tc_tags_array, ZEND_ACC_PUBLIC)
    ZEND_ME(TagCache, searchAll, arginfo_tc_tags_array, ZEND_ACC_PUBLIC)
    ZEND_ME(TagCache, close, arginfo_tc_void, ZEND_ACC_PUBLIC)
    ZEND_FE_END
};


PHP_MINIT_FUNCTION(tagcache) {
    le_tagcache_client = zend_register_list_destructors_ex(tc_client_dtor, NULL, PHP_TAGCACHE_EXTNAME, module_number);
    // Register TagCache class (OO wrapper)
    zend_class_entry ce; INIT_CLASS_ENTRY(ce, "TagCache", tagcache_class_methods);
    tagcache_ce = zend_register_internal_class(&ce);
    tagcache_ce->create_object = tagcache_obj_create;
    // Methods will be added dynamically below via function entries list
    return SUCCESS;
}

PHP_MINFO_FUNCTION(tagcache) {
    php_info_print_table_start();
    php_info_print_table_row(2, "tagcache support", "enabled");
    php_info_print_table_row(2, "Version", PHP_TAGCACHE_VERSION);
    php_info_print_table_end();
}

zend_module_entry tagcache_module_entry = {
    STANDARD_MODULE_HEADER,
    PHP_TAGCACHE_EXTNAME,
    tagcache_functions,
    PHP_MINIT(tagcache),
    NULL,
    NULL,
    NULL,
    PHP_MINFO(tagcache),
    PHP_TAGCACHE_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_TAGCACHE
# ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
# endif
ZEND_GET_MODULE(tagcache)
#endif
