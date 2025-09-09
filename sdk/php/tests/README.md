# TagCache PHP SDK Test Configuration

This document outlines the test configuration and port settings for the TagCache PHP SDK.

## Default Ports (from tagcache.sh)

- **HTTP Port**: 8080 (can be overridden with `PORT` env var)
- **TCP Port**: 1984 (can be overridden with `TCP_PORT` env var)
- **Frontend Port**: 5173 (Vite dev server)

## Test Categories

### Unit Tests (`tests/Unit/`)
- `ConfigTest.php` - Configuration management tests
- `Models/ItemTest.php` - Data model tests
- `Exceptions/ExceptionTest.php` - Exception handling tests

### Transport Tests (`tests/Transport/`)
- `HttpTransportTest.php` - HTTP transport layer tests
- `TcpTransportTest.php` - TCP transport layer tests
- `TransportFactoryTest.php` - Transport creation tests

### Integration Tests (`tests/Integration/`)
- `LiveServerTest.php` - Full integration with running TagCache server
- `AuthenticationTest.php` - Authentication flow tests
- `BulkOperationsTest.php` - Bulk operation tests

### Feature Tests (`tests/Feature/`)
- `ClientTest.php` - Client facade feature tests
- `TagOperationsTest.php` - Tag-based operations tests
- `SearchTest.php` - Search functionality tests

### Performance Tests (`tests/Performance/`)
- `PerformanceTest.php` - Performance benchmarks
- `ConcurrencyTest.php` - Concurrent operation tests
- `MemoryUsageTest.php` - Memory usage tests

## Running Tests

```bash
# All tests
./vendor/bin/phpunit

# Unit tests only
./vendor/bin/phpunit --testsuite=unit

# Integration tests (requires running server)
./vendor/bin/phpunit --testsuite=integration

# Performance tests
./vendor/bin/phpunit --testsuite=performance

# Static analysis
./vendor/bin/phpstan analyse src --level=max
```

## Environment Setup

For tests, set these environment variables:
```bash
export TAGCACHE_HTTP_URL="http://localhost:8080"
export TAGCACHE_TCP_HOST="localhost"
export TAGCACHE_TCP_PORT="1984"
```
