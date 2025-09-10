# TagCache Release Documentation

## Overview

This document provides comprehensive instructions for building, packaging, and releasing TagCache across multiple platforms.

## Prerequisites

### Development Environment
- Rust toolchain (1.70.0 or later)
- Git
- Platform-specific tools (see sections below)

### Required Cargo Tools
```bash
# Install packaging tools
cargo install cargo-deb          # For Debian packages
cargo install cargo-generate-rpm # For RPM packages
cargo install cross --git https://github.com/cross-rs/cross  # For cross-compilation
```

## Release Process

### 1. Prepare Release

```bash
# Make sure you're on the main branch and it's clean
git checkout main
git pull origin main
git status  # Should be clean

# Run the release script
./scripts/release.sh 1.0.0
```

This script will:
- Update version in `Cargo.toml`
- Update version in Homebrew formula
- Run tests
- Create git tag
- Commit changes

### 2. Push to GitHub

```bash
# Push the changes and tag
git push origin main
git push origin v1.0.0
```

This triggers the GitHub Actions workflow which automatically:
- Builds binaries for all platforms
- Creates `.deb` and `.rpm` packages
- Uploads all assets to GitHub Releases

### 3. Update Package Managers

After the GitHub release is published:

```bash
# Update Homebrew formula with correct SHA256 hashes
./scripts/update-homebrew-shas.sh 1.0.0
```

## Platform-Specific Instructions

### Homebrew (macOS/Linux)

#### Initial Setup
1. Fork the [homebrew-core](https://github.com/Homebrew/homebrew-core) repository
2. Copy `packaging/homebrew/tagcache.rb` to `Formula/tagcache.rb` in your fork
3. Update SHA256 hashes using the update script
4. Submit a PR to homebrew-core

#### User Installation
```bash
brew install tagcache

# Or from a tap (before homebrew-core inclusion):
brew tap aminshamim/tagcache
brew install tagcache
```

### Debian/Ubuntu

#### Package Building
The `.deb` package is automatically built by GitHub Actions and includes:
- Binary: `/usr/bin/tagcache`
- Systemd service: `/lib/systemd/system/tagcache.service`
- User creation and directory setup

#### User Installation
```bash
# Download from releases
wget https://github.com/aminshamim/tagcache/releases/download/v1.0.0/tagcache_1.0.0_amd64.deb

# Install
sudo dpkg -i tagcache_1.0.0_amd64.deb

# Start service
sudo systemctl enable tagcache
sudo systemctl start tagcache
```

### RHEL/CentOS/Fedora (RPM)

#### User Installation
```bash
# Download from releases
wget https://github.com/aminshamim/tagcache/releases/download/v1.0.0/tagcache-1.0.0-1.x86_64.rpm

# Install
sudo rpm -ivh tagcache-1.0.0-1.x86_64.rpm

# Or with yum/dnf
sudo yum localinstall tagcache-1.0.0-1.x86_64.rpm
```

### Windows

#### Using the Installer (NSIS)
1. Download `tagcache-installer-1.0.0.exe` from releases
2. Run as administrator
3. Follow installation wizard

#### Manual Installation
1. Download `tagcache-windows-x86_64.zip`
2. Extract to desired location
3. Add to PATH manually

## Local Development Builds

### Build All Platforms
```bash
./scripts/build-all.sh
```

### Individual Platform Builds
```bash
# Native build
cargo build --release

# Cross-compilation examples
cross build --release --target x86_64-unknown-linux-musl
cross build --release --target aarch64-unknown-linux-gnu
```

### Package Testing
```bash
# Test Debian package locally
cargo deb
sudo dpkg -i target/debian/tagcache_*.deb

# Test RPM package locally  
cargo generate-rpm
sudo rpm -ivh target/generate-rpm/tagcache-*.rpm
```

## Configuration

### Environment Variables
- `PORT`: HTTP server port (default: 8080)
- `TCP_PORT`: TCP server port (default: 1984)
- `NUM_SHARDS`: Number of cache shards (default: 16)
- `CLEANUP_INTERVAL_MS`: Cleanup interval in milliseconds (default: 60000)
- `LOG_LEVEL`: Logging level (default: info)

### Systemd Service
The systemd service is configured to:
- Run as `tagcache` user
- Use `/var/lib/tagcache` as working directory
- Log to systemd journal
- Restart automatically on failure

### Service Management
```bash
# Status
sudo systemctl status tagcache

# Start/Stop
sudo systemctl start tagcache
sudo systemctl stop tagcache

# Enable/Disable auto-start
sudo systemctl enable tagcache
sudo systemctl disable tagcache

# View logs
sudo journalctl -u tagcache -f
```

## Security Considerations

### File Permissions
- Binary: 755 (executable by all, writable by owner)
- Config files: 644 (readable by all, writable by owner)
- Data directory: 755 owned by `tagcache:tagcache`

### Systemd Security
The service includes security hardening:
- `NoNewPrivileges=true`
- `PrivateTmp=true`
- `ProtectSystem=strict`
- `MemoryDenyWriteExecute=true`
- Resource limits (file descriptors, processes)

## Troubleshooting

### Build Issues
```bash
# Clean build cache
cargo clean

# Update Rust toolchain
rustup update

# Install missing targets
rustup target add x86_64-unknown-linux-musl
```

### Cross-compilation Issues
```bash
# Update cross
cargo install cross --git https://github.com/cross-rs/cross --force

# Check Docker
docker --version
```

### Package Issues
```bash
# Debian package info
dpkg -l | grep tagcache
dpkg -L tagcache  # List files

# RPM package info
rpm -qa | grep tagcache
rpm -ql tagcache  # List files
```

## CI/CD Pipeline

The GitHub Actions workflow (`.github/workflows/release.yml`) handles:

1. **Multi-platform Builds**: Linux (x86_64, ARM64), macOS (Intel, Apple Silicon), Windows
2. **Package Creation**: `.deb`, `.rpm` packages with proper metadata
3. **Asset Upload**: All binaries and packages attached to GitHub release
4. **Security**: Uses official GitHub runners, caches dependencies

### Workflow Triggers
- Push to tags matching `v*` (e.g., `v1.0.0`)
- Manual workflow dispatch

### Build Matrix
- **Linux**: GNU libc and musl variants for x86_64 and ARM64
- **macOS**: Universal support for Intel and Apple Silicon
- **Windows**: x86_64 with MSVC toolchain

## Release Checklist

- [ ] Update version in `Cargo.toml`
- [ ] Update `CHANGELOG.md` with new features/fixes
- [ ] Run `cargo test` and ensure all tests pass
- [ ] Run `./scripts/build-all.sh` to test local builds
- [ ] Create and push git tag
- [ ] Verify GitHub Actions workflow completes successfully
- [ ] Test installation on target platforms
- [ ] Update Homebrew formula SHA256 hashes
- [ ] Submit Homebrew PR (if needed)
- [ ] Update documentation
- [ ] Announce release

## Support

For release-related issues:
1. Check GitHub Actions logs for build failures
2. Test locally with `./scripts/build-all.sh`
3. Verify package installation on clean systems
4. Check platform-specific package managers for validation errors
