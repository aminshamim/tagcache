# TagCache Docker Setup (Ubuntu)

This Docker setup uses the official v1.0.8 release of TagCache from GitHub releases.

## Quick Start

### Using Docker Compose (Recommended)

```bash
# Navigate to the docker/ubuntu directory
cd docker/ubuntu

# Build and start the container
docker-compose up -d

# Check the logs
docker-compose logs -f

# Check if TagCache is running and get version
curl http://localhost:8080/health
curl http://localhost:8080/stats
```

### Using Docker directly

```bash
# Build the image
docker build -t tagcache:v1.0.8 .

# Run the container
docker run -d \
  --name tagcache \
  -p 8080:8080 \
  -p 1984:1984 \
  tagcache:v1.0.8

# Check version
docker exec tagcache tagcache --version
```

## Ports

- **8080**: HTTP API endpoint
- **1984**: TCP protocol endpoint

## Testing the Installation

```bash
# Check health
curl http://localhost:8080/health

# Get version info
curl http://localhost:8080/stats

# Test setting a value via HTTP API
curl -X POST http://localhost:8080/set \
  -H "Content-Type: application/json" \
  -d '{"key": "test", "value": "hello world"}'

# Test getting a value
curl http://localhost:8080/get/test

# Check stats
curl http://localhost:8080/stats
```

## Container Management

```bash
# View logs
docker-compose logs -f tagcache

# Stop the container
docker-compose down

# Restart the container
docker-compose restart

# Remove everything (including volumes)
docker-compose down -v
```

## Version Verification

To verify that the container is running the correct version (1.0.8):

```bash
# Check version via command line
docker exec tagcache tagcache --version

# Check version via HTTP API (in stats)
curl http://localhost:8080/stats | grep -i version
```
