# TagCache Docker Setup (Ubuntu x86-64 with dpkg)

This Docker setup uses the official TagCache v1.0.8 DEB package and installs it using `dpkg` for a proper Ubuntu installation experience.

## Features

- **Proper dpkg installation**: Uses the official .deb package
- **systemd integration**: Full Ubuntu-style package installation
- **User management**: Creates proper `tagcache` user and permissions
- **Configuration management**: Standard Ubuntu file locations
- **Health checks**: Built-in container health monitoring

## Quick Start

### Using Docker Compose (Recommended)

```bash
# Navigate to the docker/ubuntux86-64 directory
cd docker/ubuntux86-64

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
docker build -t tagcache:v1.0.8-ubuntu-x86_64-dpkg .

# Run the container
docker run -d \
  --name tagcache-ubuntu-x86_64 \
  -p 8080:8080 \
  -p 1984:1984 \
  tagcache:v1.0.8-ubuntu-x86_64-dpkg

# Check version
docker exec tagcache-ubuntu-x86_64 tagcache --version
```

## Ports

- **8080**: HTTP API endpoint
- **1984**: TCP protocol endpoint

## File Locations (Inside Container)

This setup follows standard Ubuntu/Debian package conventions:

- **Binary**: `/usr/bin/tagcache`
- **Config**: `/etc/tagcache/tagcache.conf`
- **Data**: `/var/lib/tagcache/`
- **Logs**: `/var/log/tagcache/`
- **User**: `tagcache` (created by dpkg installation)

## Testing the Installation

```bash
# Check health
curl http://localhost:8080/health

# Get version info and stats
curl http://localhost:8080/stats

# Test setting a value via HTTP API
curl -X POST http://localhost:8080/set \
  -H "Content-Type: application/json" \
  -d '{"key": "test", "value": "hello ubuntu dpkg world"}'

# Test getting a value
curl http://localhost:8080/get/test

# Check performance stats
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

# Enter the container for debugging
docker exec -it tagcache-ubuntu-x86_64 /bin/bash
```

## Version Verification

To verify that the container is running the correct version (1.0.8):

```bash
# Check version via command line
docker exec tagcache-ubuntu-x86_64 tagcache --version

# Check version via HTTP API (in stats)
curl http://localhost:8080/stats | grep -i version

# Verify dpkg installation
docker exec tagcache-ubuntu-x86_64 dpkg -l | grep tagcache
```

## Package Information

```bash
# Check installed package details
docker exec tagcache-ubuntu-x86_64 dpkg -s tagcache

# List package files
docker exec tagcache-ubuntu-x86_64 dpkg -L tagcache
```

## Troubleshooting

### Common Issues

1. **Port already in use**: Change port mapping in docker-compose.yml
2. **Container won't start**: Check logs with `docker-compose logs tagcache`
3. **Health check failing**: Ensure TagCache is binding to 0.0.0.0, not localhost

### Debug Commands

```bash
# Check if TagCache process is running
docker exec tagcache-ubuntu-x86_64 ps aux | grep tagcache

# Check network connectivity
docker exec tagcache-ubuntu-x86_64 netstat -tlnp | grep -E "(8080|1984)"

# Check file permissions
docker exec tagcache-ubuntu-x86_64 ls -la /var/lib/tagcache /var/log/tagcache

# Test internal connectivity
docker exec tagcache-ubuntu-x86_64 curl -I http://localhost:8080/health
```

## Performance Testing

```bash
# Basic HTTP benchmark (if bench_tcp is available)
docker exec tagcache-ubuntu-x86_64 bench_tcp --help

# External benchmark from host
curl -w "@curl-format.txt" -s -o /dev/null http://localhost:8080/health

# Load testing with multiple requests
for i in {1..100}; do 
  curl -s -X POST http://localhost:8080/set \
    -H "Content-Type: application/json" \
    -d "{\"key\": \"test$i\", \"value\": \"value$i\"}" &
done
wait
```

## Volume Persistence

The docker-compose setup includes persistent volumes:

- `tagcache_data`: Persists `/var/lib/tagcache`
- `tagcache_logs`: Persists `/var/log/tagcache`

Data will survive container restarts and updates.

## Differences from Standard Docker Setup

This setup differs from the basic Docker installation:

1. **dpkg installation**: Uses proper Debian package manager
2. **System integration**: Full Ubuntu-style file layout
3. **User management**: Proper system user creation
4. **Configuration**: Standard Ubuntu configuration file locations
5. **Package metadata**: Full dpkg package information available

## Building from Source

If you need to rebuild the DEB package:

```bash
# Go back to project root and run Ubuntu release script
cd ../../
./scripts/ubuntu-release.sh

# Copy new DEB package
cp dist/tagcache_1.0.8_amd64.deb docker/ubuntux86-64/

# Rebuild Docker image
cd docker/ubuntux86-64
docker-compose build --no-cache
```
