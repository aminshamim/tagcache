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

typedef struct _tc_client_config {
    tc_mode_t mode;
    char *host;
    int port;
    char *http_base;
    int timeout_ms;
    int connect_timeout_ms;
    int pool_size;
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
} tc_client_handle;

// Internal helpers
char *tc_serialize_zval(smart_str *buf, zval *val);
int tc_deserialize_to_zval(const char *data, size_t len, zval *return_value);
int tc_tcp_cmd(tc_client_handle *h, const char *cmd, size_t cmd_len, smart_str *resp);

#endif // PHP_TAGCACHE_H
