#ifndef PHP_TAGCACHE_H
#define PHP_TAGCACHE_H

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include <php.h>
#include <stdint.h>
#include <stdbool.h>

extern zend_module_entry tagcache_module_entry;
#define phpext_tagcache_ptr &tagcache_module_entry

#define PHP_TAGCACHE_EXTNAME "tagcache"
#define PHP_TAGCACHE_VERSION "0.1.0-dev"

// Resource type id
extern int le_tagcache_client;

typedef enum { TC_MODE_TCP=0, TC_MODE_HTTP=1, TC_MODE_AUTO=2 } tc_mode_t;

typedef enum { 
    TC_SERIALIZE_PHP=0,      // Default PHP serialize() 
    TC_SERIALIZE_IGBINARY=1, // igbinary (if available)
    TC_SERIALIZE_MSGPACK=2,  // msgpack (if available)
    TC_SERIALIZE_NATIVE=3    // Native types only (fastest)
} tc_serialize_t;

typedef struct _tc_client_config {
    tc_mode_t mode;
    char *host;
    int port;
    char *http_base;
    int timeout_ms;
    int connect_timeout_ms;
    int pool_size;
    tc_serialize_t serializer;  // Serialization method
    // Advanced optimizations
    bool enable_pipelining;     // Enable request pipelining
    int pipeline_depth;         // Max requests in pipeline
    bool enable_async_io;       // Enable async I/O operations
    bool enable_keep_alive;     // Enable TCP keep-alive
    int keep_alive_idle;        // Keep-alive idle time (seconds)
    int keep_alive_interval;    // Keep-alive probe interval (seconds)
    int keep_alive_count;       // Keep-alive probe count
} tc_client_config;

typedef struct _tc_tcp_conn {
    int fd;
    bool healthy;
    double created_at;
    double last_used;
    // Phase 1 optimization: buffered read state
    char  rbuf[8192];
    size_t rlen;
    size_t rpos;
    // Write buffer (small aggregation to reduce syscalls)
    char wbuf[8192];
    size_t wlen;
    // Ultra-fast command assembly buffer (16KB for complex commands)
    char cmd_buf[16384];
    // Pipelining support
    int pending_requests;      // Number of requests sent but not yet responded
    bool pipeline_mode;        // Whether connection is in pipeline mode
    char *pipeline_buffer;     // Buffer for batched requests
    size_t pipeline_buf_size;  // Size of pipeline buffer
    size_t pipeline_buf_used;  // Used bytes in pipeline buffer
} tc_tcp_conn;

typedef struct _tc_client_handle {
    tc_client_config cfg;
    tc_tcp_conn *pool; // dynamic array
    int pool_len;
    int rr;
    // Connection pinning optimization
    tc_tcp_conn *last_used;
    // Shell integration features
    bool shell_integration_enabled;
    char *shell_detection_buffer;
    size_t shell_buffer_size;
    int command_hints;
    bool auto_retry_enabled;
    // Async I/O support
    bool async_mode;
    int *async_fds;           // File descriptors for async operations
    int async_fd_count;       // Number of async connections
} tc_client_handle;

// Internal helpers
char *tc_serialize_zval(smart_str *buf, zval *val, tc_serialize_t format);
int tc_deserialize_to_zval(const char *data, size_t len, zval *return_value);
int tc_tcp_cmd(tc_client_handle *h, const char *cmd, size_t cmd_len, smart_str *resp);

// Pipelining functions
int tc_pipeline_begin(tc_tcp_conn *conn);
int tc_pipeline_add_request(tc_tcp_conn *conn, const char *cmd, size_t cmd_len);
int tc_pipeline_execute(tc_tcp_conn *conn, smart_str **responses, int *response_count);
int tc_pipeline_end(tc_tcp_conn *conn);

// Async I/O functions
int tc_async_begin(tc_client_handle *h);
int tc_async_add_request(tc_client_handle *h, const char *key, int request_type);
int tc_async_execute(tc_client_handle *h, zval *results);
int tc_async_end(tc_client_handle *h);

// Keep-alive functions
void tc_setup_keep_alive(int fd, tc_client_config *cfg);

#endif // PHP_TAGCACHE_H
