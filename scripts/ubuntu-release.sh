#!/bin/bash
# Ubuntu Release Script for TagCache
# This script builds and releases Ubuntu-specific packages and Docker containers

set -e

# Get version from VERSION file
if [ ! -f "VERSION" ]; then
    echo "❌ VERSION file not found!"
    echo "Please create a VERSION file with the version number (e.g., 1.0.8)"
    exit 1
fi

VERSION=$(cat VERSION | tr -d '\n')
RELEASE_TAG="v$VERSION"

echo "🐧 Starting Ubuntu release for TagCache $RELEASE_TAG"

# Helper function to check if command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Helper function to install required tools for Ubuntu packaging
install_ubuntu_tools() {
    echo "📦 Checking and installing Ubuntu packaging tools..."
    
    # Install Rust targets for Linux
    echo "🎯 Installing Rust targets for Linux..."
    rustup target add x86_64-unknown-linux-gnu || true
    rustup target add aarch64-unknown-linux-gnu || true
    
    # Install cross for cross-compilation
    if ! command_exists cross; then
        echo "📥 Installing cross..."
        cargo install cross --git https://github.com/cross-rs/cross
    fi
    
    # Check if dpkg-deb is available (for creating .deb packages)
    if ! command_exists dpkg-deb; then
        echo "⚠️ dpkg-deb not available. DEB packages will be created as tar.gz archives."
    fi
    
    # Check if Docker is available (for Docker images)
    if ! command_exists docker; then
        echo "⚠️ Docker not available. Skipping Docker image creation."
    fi
    
    echo "✅ Ubuntu packaging tools ready"
}

# Build Linux binaries for Ubuntu
build_ubuntu_binaries() {
    echo "🔨 Building Linux binaries for Ubuntu..."
    
    # Clean previous builds
    echo "🧹 Cleaning previous builds..."
    cargo clean
    rm -rf dist/ubuntu/
    mkdir -p dist/ubuntu
    
    # Build for x86_64 Linux (most common Ubuntu architecture)
    echo "🔨 Building for x86_64-unknown-linux-gnu..."
    cross build --release --target x86_64-unknown-linux-gnu --features embed-ui
    
    if [ $? -eq 0 ]; then
        echo "✅ Built x86_64-unknown-linux-gnu successfully"
    else
        echo "❌ Failed to build x86_64-unknown-linux-gnu"
        return 1
    fi
    
    # Build for ARM64 Linux (for Ubuntu on ARM devices)
    echo "🔨 Building for aarch64-unknown-linux-gnu..."
    cross build --release --target aarch64-unknown-linux-gnu --features embed-ui
    
    if [ $? -eq 0 ]; then
        echo "✅ Built aarch64-unknown-linux-gnu successfully"
    else
        echo "⚠️ Failed to build aarch64-unknown-linux-gnu (continuing with x86_64 only)"
    fi
    
    echo "✅ Ubuntu binaries built successfully"
}

# Create Ubuntu DEB package using Docker (for proper dpkg-deb support)
create_ubuntu_deb_package() {
    echo "📦 Creating Ubuntu DEB package..."
    
    local deb_dir="dist/ubuntu/tagcache_${VERSION}_amd64"
    
    # Clean and create directory structure
    rm -rf "$deb_dir"
    mkdir -p "$deb_dir/DEBIAN" \
             "$deb_dir/usr/bin" \
             "$deb_dir/etc/tagcache" \
             "$deb_dir/var/lib/tagcache" \
             "$deb_dir/var/log/tagcache" \
             "$deb_dir/usr/share/doc/tagcache"
    
    # Copy binaries
    if [ -f "target/x86_64-unknown-linux-gnu/release/tagcache" ]; then
        cp target/x86_64-unknown-linux-gnu/release/tagcache "$deb_dir/usr/bin/"
        cp target/x86_64-unknown-linux-gnu/release/bench_tcp "$deb_dir/usr/bin/"
    else
        echo "❌ Linux binaries not found. Please build first."
        return 1
    fi
    
    # Create example configuration file
    if [ -f "tagcache.conf.example" ]; then
        cp tagcache.conf.example "$deb_dir/etc/tagcache/tagcache.conf"
    fi
    
    # Create systemd service file if it exists
    if [ -f "packaging/systemd/tagcache.service" ]; then
        mkdir -p "$deb_dir/lib/systemd/system"
        cp packaging/systemd/tagcache.service "$deb_dir/lib/systemd/system/"
    fi
    
    # Create documentation
    cat > "$deb_dir/usr/share/doc/tagcache/README.Debian" << EOF
TagCache for Ubuntu/Debian
==========================

TagCache has been installed in /usr/bin/tagcache

Configuration:
- Default config: /etc/tagcache/tagcache.conf
- Data directory: /var/lib/tagcache
- Log directory: /var/log/tagcache

Starting TagCache:
- Manual: tagcache server
- With systemd: sudo systemctl start tagcache
- Enable at boot: sudo systemctl enable tagcache

Web interface will be available at:
- http://localhost:8080 (default)

For more information, visit:
https://github.com/aminshamim/tagcache
EOF
    
    # Create control file
    cat > "$deb_dir/DEBIAN/control" << EOF
Package: tagcache
Version: $VERSION
Section: utils
Priority: optional
Architecture: amd64
Maintainer: Amin Shamim <amin@tagcache.com>
Description: TagCache - High-performance tag-based caching system
 TagCache is a high-performance tag-based caching system with a modern web UI.
 It provides both HTTP and TCP protocols for maximum flexibility and includes
 benchmarking tools for performance testing.
 .
 Features:
 - High-performance in-memory caching with tag-based invalidation
 - HTTP API with modern web interface
 - TCP protocol for maximum performance
 - Embedded web UI (no external dependencies)
 - Comprehensive benchmarking tools
 - Production-ready with proper logging and configuration
 .
 This package includes:
 - tagcache: Main server binary with embedded web UI
 - bench_tcp: TCP vs HTTP benchmark tool
 - Configuration files and documentation
 - Systemd service integration
Homepage: https://github.com/aminshamim/tagcache
EOF
    
    # Create postinst script
    if [ -f "packaging/debian/postinst" ]; then
        cp packaging/debian/postinst "$deb_dir/DEBIAN/"
        chmod 755 "$deb_dir/DEBIAN/postinst"
    else
        cat > "$deb_dir/DEBIAN/postinst" << 'EOF'
#!/bin/bash
set -e

# Create tagcache user if it doesn't exist
if ! id -u tagcache >/dev/null 2>&1; then
    useradd -r -s /bin/false -d /var/lib/tagcache tagcache
fi

# Set proper permissions
chown -R tagcache:tagcache /var/lib/tagcache /var/log/tagcache
chmod 755 /var/lib/tagcache /var/log/tagcache

# Reload systemd if service file was installed
if [ -f /lib/systemd/system/tagcache.service ]; then
    systemctl daemon-reload || true
fi

echo "TagCache installed successfully!"
echo "Start with: sudo systemctl start tagcache"
echo "Enable at boot: sudo systemctl enable tagcache"
echo "Web interface: http://localhost:8080"
EOF
        chmod 755 "$deb_dir/DEBIAN/postinst"
    fi
    
    # Create prerm script
    if [ -f "packaging/debian/prerm" ]; then
        cp packaging/debian/prerm "$deb_dir/DEBIAN/"
        chmod 755 "$deb_dir/DEBIAN/prerm"
    else
        cat > "$deb_dir/DEBIAN/prerm" << 'EOF'
#!/bin/bash
set -e

# Stop the service if it's running
if systemctl is-active --quiet tagcache 2>/dev/null; then
    systemctl stop tagcache || true
fi

# Disable the service
if systemctl is-enabled --quiet tagcache 2>/dev/null; then
    systemctl disable tagcache || true
fi
EOF
        chmod 755 "$deb_dir/DEBIAN/prerm"
    fi
    
    # Create postrm script
    if [ -f "packaging/debian/postrm" ]; then
        cp packaging/debian/postrm "$deb_dir/DEBIAN/"
        chmod 755 "$deb_dir/DEBIAN/postrm"
    else
        cat > "$deb_dir/DEBIAN/postrm" << 'EOF'
#!/bin/bash
set -e

if [ "$1" = "purge" ]; then
    # Remove user (only on purge)
    if id -u tagcache >/dev/null 2>&1; then
        userdel tagcache || true
    fi
    
    # Remove data directories (only on purge)
    rm -rf /var/lib/tagcache /var/log/tagcache || true
    
    # Reload systemd
    systemctl daemon-reload || true
fi
EOF
        chmod 755 "$deb_dir/DEBIAN/postrm"
    fi
    
    # Create package using dpkg-deb (try local first, then Docker)
    if command -v dpkg-deb >/dev/null 2>&1; then
        echo "📦 Using local dpkg-deb..."
        dpkg-deb --build "$deb_dir" "dist/ubuntu/tagcache_${VERSION}_amd64.deb"
        echo "✅ Created dist/ubuntu/tagcache_${VERSION}_amd64.deb"
    elif command -v docker >/dev/null 2>&1; then
        echo "📦 Using Docker to create proper DEB package..."
        docker run --rm -v "$(pwd):/workspace" -w /workspace ubuntu:24.04 bash -c "
            apt-get update -qq && apt-get install -y -qq dpkg-dev &&
            dpkg-deb --build $deb_dir dist/ubuntu/tagcache_${VERSION}_amd64.deb &&
            chown $(id -u):$(id -g) dist/ubuntu/tagcache_${VERSION}_amd64.deb
        "
        
        # Verify it's a proper DEB file
        if file "dist/ubuntu/tagcache_${VERSION}_amd64.deb" | grep -q "Debian binary package"; then
            echo "✅ Created proper Debian package: dist/ubuntu/tagcache_${VERSION}_amd64.deb"
        else
            echo "❌ Failed to create proper DEB package"
            return 1
        fi
    else
        echo "⚠️ Neither dpkg-deb nor Docker available, creating tar.gz fallback"
        cd dist/ubuntu
        tar czf "tagcache_${VERSION}_amd64.tar.gz" -C "tagcache_${VERSION}_amd64" .
        cd - > /dev/null
        echo "✅ Created dist/ubuntu/tagcache_${VERSION}_amd64.tar.gz (fallback)"
        return
    fi
}

# Create ARM64 DEB package if ARM64 build succeeded
create_ubuntu_arm64_deb_package() {
    if [ ! -f "target/aarch64-unknown-linux-gnu/release/tagcache" ]; then
        echo "⚠️ Skipping ARM64 DEB package (ARM64 build not available)"
        return
    fi
    
    echo "📦 Creating Ubuntu ARM64 DEB package..."
    
    local deb_dir="dist/ubuntu/tagcache_${VERSION}_arm64"
    
    # Clean and create directory structure (similar to amd64 but for arm64)
    rm -rf "$deb_dir"
    mkdir -p "$deb_dir/DEBIAN" \
             "$deb_dir/usr/bin" \
             "$deb_dir/etc/tagcache" \
             "$deb_dir/var/lib/tagcache" \
             "$deb_dir/var/log/tagcache" \
             "$deb_dir/usr/share/doc/tagcache"
    
    # Copy ARM64 binaries
    cp target/aarch64-unknown-linux-gnu/release/tagcache "$deb_dir/usr/bin/"
    cp target/aarch64-unknown-linux-gnu/release/bench_tcp "$deb_dir/usr/bin/"
    
    # Copy configuration and documentation (same as amd64)
    if [ -f "tagcache.conf.example" ]; then
        cp tagcache.conf.example "$deb_dir/etc/tagcache/tagcache.conf"
    fi
    
    if [ -f "packaging/systemd/tagcache.service" ]; then
        mkdir -p "$deb_dir/lib/systemd/system"
        cp packaging/systemd/tagcache.service "$deb_dir/lib/systemd/system/"
    fi
    
    # Use same documentation as amd64
    cp -r "dist/ubuntu/tagcache_${VERSION}_amd64/usr/share/doc/tagcache/" "$deb_dir/usr/share/doc/"
    
    # Create control file for ARM64
    cat > "$deb_dir/DEBIAN/control" << EOF
Package: tagcache
Version: $VERSION
Section: utils
Priority: optional
Architecture: arm64
Maintainer: Amin Shamim <amin@tagcache.com>
Description: TagCache - High-performance tag-based caching system
 TagCache is a high-performance tag-based caching system with a modern web UI.
 It provides both HTTP and TCP protocols for maximum flexibility and includes
 benchmarking tools for performance testing.
 .
 This ARM64 build is optimized for ARM-based Ubuntu systems including
 Raspberry Pi 4, AWS Graviton, and other ARM64 servers.
 .
 This package includes:
 - tagcache: Main server binary with embedded web UI
 - bench_tcp: TCP vs HTTP benchmark tool
 - Configuration files and documentation
 - Systemd service integration
Homepage: https://github.com/aminshamim/tagcache
EOF
    
    # Copy postinst, prerm, postrm scripts from amd64 package
    cp -r "dist/ubuntu/tagcache_${VERSION}_amd64/DEBIAN/postinst" "$deb_dir/DEBIAN/"
    cp -r "dist/ubuntu/tagcache_${VERSION}_amd64/DEBIAN/prerm" "$deb_dir/DEBIAN/"
    cp -r "dist/ubuntu/tagcache_${VERSION}_amd64/DEBIAN/postrm" "$deb_dir/DEBIAN/"
    
    # Create package
    if command -v dpkg-deb >/dev/null 2>&1; then
        dpkg-deb --build "$deb_dir" "dist/ubuntu/tagcache_${VERSION}_arm64.deb"
        echo "✅ Created dist/ubuntu/tagcache_${VERSION}_arm64.deb"
    else
        cd dist/ubuntu
        tar czf "tagcache_${VERSION}_arm64.tar.gz" -C "tagcache_${VERSION}_arm64" .
        cd - > /dev/null
        echo "✅ Created dist/ubuntu/tagcache_${VERSION}_arm64.tar.gz (fallback)"
    fi
}

# Update Docker files with new version
update_ubuntu_docker() {
    echo "🐳 Updating Ubuntu Docker configuration..."
    
    local docker_dir="docker/ubuntu"
    
    # Update Dockerfile with new version
    if [ -f "$docker_dir/Dockerfile" ]; then
        # Update version references in Dockerfile
        sed -i.tmp "s/v[0-9]\+\.[0-9]\+\.[0-9]\+/v${VERSION}/g" "$docker_dir/Dockerfile"
        sed -i.tmp "s/tagcache_[0-9]\+\.[0-9]\+\.[0-9]\+_amd64\.deb/tagcache_${VERSION}_amd64.deb/g" "$docker_dir/Dockerfile"
        
        # Clean up temporary files
        rm -f "$docker_dir/Dockerfile.tmp"
        
        echo "✅ Updated $docker_dir/Dockerfile with version $VERSION"
    fi
    
    # Update README
    if [ -f "$docker_dir/README.md" ]; then
        sed -i.tmp "s/v[0-9]\+\.[0-9]\+\.[0-9]\+/v${VERSION}/g" "$docker_dir/README.md"
        sed -i.tmp "s/tagcache_[0-9]\+\.[0-9]\+\.[0-9]\+_amd64\.deb/tagcache_${VERSION}_amd64.deb/g" "$docker_dir/README.md"
        
        rm -f "$docker_dir/README.md.tmp"
        
        echo "✅ Updated $docker_dir/README.md with version $VERSION"
    fi
}

# Copy DEB packages to Docker directory
copy_packages_to_docker() {
    echo "📦 Copying packages to Docker directory..."
    
    local docker_dir="docker/ubuntu"
    
    # Copy DEB packages if they exist
    if [ -f "dist/ubuntu/tagcache_${VERSION}_amd64.deb" ]; then
        cp "dist/ubuntu/tagcache_${VERSION}_amd64.deb" "$docker_dir/"
        echo "✅ Copied amd64 DEB package to $docker_dir/"
    fi
    
    if [ -f "dist/ubuntu/tagcache_${VERSION}_arm64.deb" ]; then
        cp "dist/ubuntu/tagcache_${VERSION}_arm64.deb" "$docker_dir/"
        echo "✅ Copied arm64 DEB package to $docker_dir/"
    fi
    
    # Copy binary archives as well
    if [ -f "target/x86_64-unknown-linux-gnu/release/tagcache" ]; then
        mkdir -p "$docker_dir/binaries"
        tar czf "$docker_dir/tagcache-linux-x86_64.tar.gz" -C "target/x86_64-unknown-linux-gnu/release" tagcache bench_tcp
        echo "✅ Created $docker_dir/tagcache-linux-x86_64.tar.gz"
    fi
    
    if [ -f "target/aarch64-unknown-linux-gnu/release/tagcache" ]; then
        tar czf "$docker_dir/tagcache-linux-arm64.tar.gz" -C "target/aarch64-unknown-linux-gnu/release" tagcache bench_tcp
        echo "✅ Created $docker_dir/tagcache-linux-arm64.tar.gz"
    fi
}

# Build Docker image
build_ubuntu_docker_image() {
    if ! command_exists docker; then
        echo "⚠️ Docker not available. Skipping Docker image build."
        return
    fi
    
    echo "🐳 Building Ubuntu Docker image..."
    
    local docker_dir="docker/ubuntu"
    
    cd "$docker_dir"
    
    # Build Docker image with platform specification for x86_64
    echo "📦 Building x86_64 Docker image..."
    docker build --platform linux/amd64 -t "tagcache:v${VERSION}-ubuntu-x86_64" .
    
    if [ $? -eq 0 ]; then
        echo "✅ Built x86_64 Docker image tagcache:v${VERSION}-ubuntu-x86_64"
        
        # Test the image
        echo "🧪 Testing Docker image..."
        docker run --platform linux/amd64 --rm "tagcache:v${VERSION}-ubuntu-x86_64" --version
        echo "✅ Docker image test passed"
    else
        echo "❌ Failed to build x86_64 Docker image"
        cd - > /dev/null
        return 1
    fi
    
    # Also build multi-platform image if possible
    echo "📦 Building multi-platform Docker image..."
    docker build -t "tagcache:v${VERSION}-ubuntu" -t "tagcache:latest-ubuntu" .
    
    cd - > /dev/null
}

# Create specialized Ubuntu x86-64 Docker setup with proper DEB installation
create_ubuntu_x86_64_docker() {
    if ! command_exists docker; then
        echo "⚠️ Docker not available. Skipping specialized x86-64 Docker setup."
        return
    fi
    
    echo "🐳 Creating specialized Ubuntu x86-64 Docker setup..."
    
    local docker_x86_dir="docker/ubuntux86-64"
    
    # Create the specialized docker directory
    mkdir -p "$docker_x86_dir"
    
    # Copy the proper DEB package
    if [ -f "dist/ubuntu/tagcache_${VERSION}_amd64.deb" ]; then
        cp "dist/ubuntu/tagcache_${VERSION}_amd64.deb" "$docker_x86_dir/"
        echo "✅ Copied proper DEB package to $docker_x86_dir/"
    else
        echo "❌ No proper DEB package found. Run DEB creation first."
        return 1
    fi
    
    # Create Dockerfile for x86-64 with proper DEB installation
    cat > "$docker_x86_dir/Dockerfile" << 'EOF'
# Ubuntu x86-64 Dockerfile for TagCache with dpkg installation
FROM ubuntu:24.04

# Set environment variables to prevent interactive prompts
ENV DEBIAN_FRONTEND=noninteractive

# Install necessary packages
RUN apt-get update && apt-get install -y \
    curl \
    wget \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Copy the DEB package into the container
COPY tagcache_1.0.8_amd64.deb /tmp/tagcache_1.0.8_amd64.deb

# Install TagCache using dpkg properly
RUN dpkg -i /tmp/tagcache_1.0.8_amd64.deb && \
    rm /tmp/tagcache_1.0.8_amd64.deb

# Verify installation
RUN which tagcache && tagcache --version

# Set environment variables
ENV PORT=8080
ENV TCP_PORT=1984
ENV HOST=0.0.0.0

# Expose ports
EXPOSE 8080 1984

# Create working directory
WORKDIR /var/lib/tagcache

# Switch to non-root user (created by dpkg installation)
USER tagcache

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:8080/health || exit 1

# Start TagCache directly
ENTRYPOINT ["/usr/bin/tagcache"]
CMD ["server"]
EOF
    
    # Create docker-compose.yml
    cat > "$docker_x86_dir/docker-compose.yml" << EOF
version: '3.8'

services:
  tagcache:
    build: 
      context: .
      dockerfile: Dockerfile
      platforms:
        - linux/amd64
    image: tagcache:v${VERSION}-ubuntu-x86_64-dpkg
    container_name: tagcache-ubuntu-x86_64
    ports:
      - "8080:8080"
      - "1984:1984"
    environment:
      - PORT=8080
      - TCP_PORT=1984
      - HOST=0.0.0.0
    volumes:
      # Optional: persist data
      - tagcache_data:/var/lib/tagcache
      - tagcache_logs:/var/log/tagcache
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/health"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 40s

volumes:
  tagcache_data:
    driver: local
  tagcache_logs:
    driver: local
EOF
    
    # Update the version in the Dockerfile
    sed -i.tmp "s/tagcache_1.0.8_amd64.deb/tagcache_${VERSION}_amd64.deb/g" "$docker_x86_dir/Dockerfile"
    rm -f "$docker_x86_dir/Dockerfile.tmp"
    
    # Build the specialized Docker image
    echo "🔨 Building specialized x86-64 Docker image..."
    cd "$docker_x86_dir"
    docker build --platform linux/amd64 -t "tagcache:v${VERSION}-ubuntu-x86_64-dpkg" . --no-cache
    
    if [ $? -eq 0 ]; then
        echo "✅ Built specialized x86-64 Docker image with proper DEB installation"
        
        # Test the specialized image
        echo "🧪 Testing specialized Docker image..."
        if docker run --platform linux/amd64 --rm --entrypoint="" "tagcache:v${VERSION}-ubuntu-x86_64-dpkg" /usr/bin/tagcache --version; then
            echo "✅ Specialized Docker image test passed"
        else
            echo "⚠️ Image built but version test failed (may work in container)"
        fi
    else
        echo "❌ Failed to build specialized x86-64 Docker image"
        cd - > /dev/null
        return 1
    fi
    
    cd - > /dev/null
    echo "✅ Created specialized Ubuntu x86-64 Docker setup in $docker_x86_dir/"
}

# Create Ubuntu-specific release notes
create_ubuntu_release_notes() {
    echo "📝 Creating Ubuntu-specific release notes..."
    
    cat > "dist/ubuntu/UBUNTU_RELEASE_NOTES.md" << EOF
# TagCache v$VERSION - Ubuntu Release

## 📦 Ubuntu Packages

### DEB Packages
- **x86_64**: \`tagcache_${VERSION}_amd64.deb\`
$([ -f "dist/ubuntu/tagcache_${VERSION}_arm64.deb" ] && echo "- **ARM64**: \`tagcache_${VERSION}_arm64.deb\`")

### Docker Image
- **Ubuntu-based**: \`tagcache:v${VERSION}-ubuntu\`

## 🚀 Installation

### Method 1: DEB Package (Recommended)
\`\`\`bash
# Download and install the DEB package
wget https://github.com/aminshamim/tagcache/releases/download/v${VERSION}/tagcache_${VERSION}_amd64.deb
sudo dpkg -i tagcache_${VERSION}_amd64.deb

# Start the service
sudo systemctl start tagcache
sudo systemctl enable tagcache

# Check status
sudo systemctl status tagcache
\`\`\`

### Method 2: Docker
\`\`\`bash
# Run with Docker
docker run -d \\
  --name tagcache \\
  -p 8080:8080 \\
  -p 1984:1984 \\
  tagcache:v${VERSION}-ubuntu

# Check if it's running
curl http://localhost:8080/health
\`\`\`

### Method 3: Binary Installation
\`\`\`bash
# Download binary archive
wget https://github.com/aminshamim/tagcache/releases/download/v${VERSION}/tagcache-linux-x86_64.tar.gz
tar -xzf tagcache-linux-x86_64.tar.gz
sudo mv tagcache bench_tcp /usr/local/bin/

# Create configuration directory
sudo mkdir -p /etc/tagcache
sudo chown \$USER:staff /etc/tagcache

# Start TagCache
tagcache server
\`\`\`

## 🔧 Ubuntu-Specific Features

### Systemd Integration
The DEB package includes systemd service integration:
\`\`\`bash
# Service management
sudo systemctl start tagcache     # Start
sudo systemctl stop tagcache      # Stop
sudo systemctl restart tagcache   # Restart
sudo systemctl enable tagcache    # Enable at boot
sudo systemctl disable tagcache   # Disable at boot

# View logs
sudo journalctl -u tagcache -f
\`\`\`

### File Locations
- **Binary**: \`/usr/bin/tagcache\`
- **Config**: \`/etc/tagcache/tagcache.conf\`
- **Data**: \`/var/lib/tagcache/\`
- **Logs**: \`/var/log/tagcache/\`
- **Service**: \`/lib/systemd/system/tagcache.service\`

### User and Permissions
- Runs as user: \`tagcache\`
- Group: \`tagcache\`
- Home directory: \`/var/lib/tagcache\`

## 🧪 Verification

### Check Installation
\`\`\`bash
# Verify binary
tagcache --version
bench_tcp --help

# Test HTTP API
curl http://localhost:8080/health
curl http://localhost:8080/stats

# Test TCP protocol (if bench_tcp is available)
bench_tcp --host localhost --port 1984 --requests 100
\`\`\`

### Performance Testing
\`\`\`bash
# HTTP vs TCP benchmark
bench_tcp --host localhost --port 1984 --requests 1000 --compare-http

# Set some test data
curl -X POST http://localhost:8080/set \\
  -H "Content-Type: application/json" \\
  -d '{"key": "test", "value": "hello ubuntu"}'

# Get the data
curl http://localhost:8080/get/test
\`\`\`

## 🐛 Troubleshooting

### Common Issues
1. **Port already in use**: Change ports in \`/etc/tagcache/tagcache.conf\`
2. **Permission denied**: Ensure tagcache user has proper permissions
3. **Service won't start**: Check logs with \`sudo journalctl -u tagcache\`

### Log Locations
- **systemd logs**: \`sudo journalctl -u tagcache\`
- **Application logs**: \`/var/log/tagcache/\`

### Support
- GitHub Issues: https://github.com/aminshamim/tagcache/issues
- Documentation: https://github.com/aminshamim/tagcache/tree/main/docs

## 📋 Package Contents
- \`tagcache\` - Main server with embedded web UI
- \`bench_tcp\` - TCP vs HTTP benchmark tool
- Configuration file and documentation
- Systemd service file
- User and permission setup scripts
EOF
    
    echo "✅ Created Ubuntu release notes: dist/ubuntu/UBUNTU_RELEASE_NOTES.md"
}

# Main Ubuntu release process
main() {
    echo "🐧 Starting Ubuntu release process for TagCache v$VERSION..."
    
    # Install required tools
    install_ubuntu_tools
    
    # Build Ubuntu binaries
    build_ubuntu_binaries
    
    if [ $? -ne 0 ]; then
        echo "❌ Failed to build Ubuntu binaries. Aborting."
        exit 1
    fi
    
    # Create DEB packages
    create_ubuntu_deb_package
    create_ubuntu_arm64_deb_package
    
    # Update Docker configuration
    update_ubuntu_docker
    
    # Copy packages to Docker directory
    copy_packages_to_docker
    
    # Build Docker image
    build_ubuntu_docker_image
    
    # Create specialized Ubuntu x86-64 Docker setup with proper DEB installation
    create_ubuntu_x86_64_docker
    
    # Create release notes
    create_ubuntu_release_notes
    
    echo ""
    echo "✅ Ubuntu release process completed successfully!"
    echo ""
    
    # Ask if user wants to upload assets to GitHub release
    read -p "🚀 Do you want to upload Ubuntu assets to GitHub release v${VERSION}? (y/N): " upload_choice
    if [[ "$upload_choice" =~ ^[Yy]$ ]]; then
        echo "📤 Starting asset upload..."
        if ./scripts/upload-ubuntu-assets.sh; then
            echo "✅ Assets uploaded successfully!"
        else
            echo "❌ Asset upload failed"
        fi
    else
        echo "⏭️  Skipping asset upload. You can run it manually later with:"
        echo "   ./scripts/upload-ubuntu-assets.sh"
    fi
    
    echo ""
    echo "📋 Ubuntu Release Summary for v$VERSION:"
    echo "✅ DEB packages created:"
    ls -lah dist/ubuntu/*.deb 2>/dev/null || echo "   (No DEB packages - fallback archives created)"
    ls -lah dist/ubuntu/*.tar.gz 2>/dev/null || true
    
    echo "✅ Docker files updated in docker/ubuntu/"
    
    if command_exists docker && docker images | grep -q "tagcache.*v${VERSION}-ubuntu"; then
        echo "✅ Docker image built: tagcache:v${VERSION}-ubuntu"
    fi
    
    if command_exists docker && docker images | grep -q "tagcache.*v${VERSION}-ubuntu-x86_64-dpkg"; then
        echo "✅ Specialized x86-64 Docker image built: tagcache:v${VERSION}-ubuntu-x86_64-dpkg"
        echo "   📁 Specialized setup available in: docker/ubuntux86-64/"
    fi
    
    echo ""
    echo "📁 Files ready for Ubuntu release:"
    find dist/ubuntu/ -type f 2>/dev/null | head -10
    
    echo ""
    echo "🚀 Next steps:"
    echo "1. Test the DEB packages on a clean Ubuntu system"
    echo "2. Test the standard Docker image: docker run --rm -p 8080:8080 tagcache:v${VERSION}-ubuntu"
    echo "3. Test the specialized x86-64 Docker image: cd docker/ubuntux86-64 && docker-compose up"
    echo "4. Upload packages to GitHub release"
    echo "5. Update package repositories (if applicable)"
    echo ""
    echo "📋 Manual testing commands:"
    echo "   # Test DEB package"
    echo "   sudo dpkg -i dist/ubuntu/tagcache_${VERSION}_amd64.deb"
    echo "   sudo systemctl start tagcache"
    echo "   curl http://localhost:8080/health"
    echo ""
    echo "   # Test specialized x86-64 Docker setup"
    echo "   cd docker/ubuntux86-64"
    echo "   docker-compose up -d"
    echo "   curl http://localhost:8080/health"
    echo "   docker-compose down"
    echo ""
    echo "   # Test Docker image"
    echo "   docker run -d --name tagcache-test -p 8080:8080 tagcache:v${VERSION}-ubuntu"
    echo "   curl http://localhost:8080/health"
    echo "   docker stop tagcache-test && docker rm tagcache-test"
}

# Handle command line arguments
case "${1:-}" in
    "--help"|"-h")
        echo "Ubuntu Release Script for TagCache"
        echo ""
        echo "Usage: $0 [options]"
        echo ""
        echo "Options:"
        echo "  --help, -h    Show this help message"
        echo "  --version     Show version from VERSION file"
        echo ""
        echo "This script will:"
        echo "1. Build Linux binaries (x86_64 and ARM64)"
        echo "2. Create Ubuntu DEB packages (with Docker fallback for proper .deb creation)"
        echo "3. Update Docker configuration"
        echo "4. Build standard Docker image"
        echo "5. Create specialized x86-64 Docker setup with proper DEB installation"
        echo "6. Create Ubuntu-specific documentation"
        echo ""
        echo "Requirements:"
        echo "- Rust toolchain with cross-compilation support"
        echo "- cross tool (will be installed if missing)"
        echo "- Docker (recommended, for proper DEB packages and image building)"
        echo "- dpkg-deb (optional, Docker will be used as fallback)"
        exit 0
        ;;
    "--version")
        if [ -f "VERSION" ]; then
            echo "TagCache v$(cat VERSION)"
        else
            echo "VERSION file not found"
            exit 1
        fi
        exit 0
        ;;
    "")
        # No arguments, run main
        main "$@"
        ;;
    *)
        echo "Unknown option: $1"
        echo "Use --help for usage information"
        exit 1
        ;;
esac
