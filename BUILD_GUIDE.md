# TagCache Distribution Build & Release Guide

This document explains how to build all TagCache distributions locally and upload them to GitHub.

## 🎯 Quick Start

### Option 1: Automatic Build & Release (Recommended)
```bash
# Run the complete build and release pipeline
./scripts/build-and-release.sh

# Follow the instructions to push the tag
git push origin v1.0.5
```

### Option 2: Check Release Readiness
```bash
# Check if everything is ready for release
./scripts/release-guide.sh

# Then manually tag and push
git tag -a v1.0.5 -m "TagCache v1.0.5"
git push origin v1.0.5
```

## 📦 What Gets Built

### Automated by GitHub Actions (when you push a tag):
- **macOS Intel**: `tagcache-macos-x86_64.tar.gz`
- **macOS Apple Silicon**: `tagcache-macos-arm64.tar.gz`
- **Linux x86_64**: `tagcache-linux-x86_64.tar.gz`
- **Linux ARM64**: `tagcache-linux-arm64.tar.gz`
- **Linux x86_64 (musl)**: `tagcache-linux-x86_64-musl.tar.gz`
- **Linux ARM64 (musl)**: `tagcache-linux-arm64-musl.tar.gz`
- **Windows x86_64**: `tagcache-windows-x86_64.zip`
- **Debian Package**: `tagcache_X.X.X_amd64.deb`
- **RPM Package**: `tagcache-X.X.X-1.x86_64.rpm`

### Built Locally (for testing):
- Current platform binary (macOS ARM64 in your case)
- **Both `tagcache` and `bench_tcp` binaries**
- Packaged tarball for distribution

## 🔍 Distribution Verification

Always verify that both binaries are included in distributions:

```bash
# Run verification script
./scripts/verify-distributions.sh

# Or manually check a tarball
tar -tzf dist/tagcache-*.tar.gz
# Should show both: tagcache and bench_tcp

# Test both binaries work
./target/release/tagcache --version
./target/release/bench_tcp --help
```

## 🛠️ Local Build Prerequisites

### Install Required Tools
```bash
# Install build tools (run once)
./scripts/setup-release.sh

# Or manually install:
cargo install cross --git https://github.com/cross-rs/cross
cargo install cargo-deb
cargo install cargo-generate-rpm
```

### Install Frontend Dependencies
```bash
cd app
pnpm install  # or npm install
```

## 🚀 Complete Release Process

### 1. Prepare for Release
```bash
# Ensure working directory is clean
git status

# Update version in Cargo.toml if needed
# Current version: 1.0.5

# Test that everything builds
cargo build --release --features embed-ui
```

### 2. Build and Tag
```bash
# Run complete build and release script
./scripts/build-and-release.sh

# This will:
# ✅ Build frontend (React app)
# ✅ Test local Rust build
# ✅ Package for current platform
# ✅ Create git tag
# ✅ Show next steps
```

### 3. Trigger GitHub Release
```bash
# Push the tag to trigger GitHub Actions
git push origin v1.0.5

# Monitor the build at:
# https://github.com/aminshamim/tagcache/actions
```

### 4. Verify Release
```bash
# After GitHub Actions completes, check:
# https://github.com/aminshamim/tagcache/releases

# Test downloads:
curl -L https://github.com/aminshamim/tagcache/releases/latest/download/tagcache-macos-arm64.tar.gz -o test.tar.gz
tar xzf test.tar.gz
./tagcache --version
```

### 5. Update Homebrew (Optional)
```bash
# Update Homebrew formula with new SHA256 hashes
./scripts/update-homebrew-shas.sh 1.0.5
```

## 📋 Build Scripts Reference

| Script | Purpose |
|--------|---------|
| `build-and-release.sh` | Complete build and release pipeline |
| `release-guide.sh` | Check readiness and show options |
| `build-all.sh` | Build for all platforms (cross-compilation) |
| `setup-release.sh` | Install build tools and setup |

## 🐛 Troubleshooting

### Build Fails
```bash
# Clean and retry
cargo clean
cd app && rm -rf node_modules dist && pnpm install && pnpm build && cd ..
cargo build --release --features embed-ui
```

### Missing bench_tcp Binary
If `bench_tcp` is missing from distributions:
```bash
# Verify both binaries build
cargo build --release --bin tagcache --bin bench_tcp

# Check they exist
ls -la target/release/{tagcache,bench_tcp}

# Verify distribution verification
./scripts/verify-distributions.sh

# Rebuild distributions with both binaries
rm -rf dist/
./scripts/build-and-release.sh
```

### Tag Already Exists
```bash
# Delete and recreate
git tag -d v1.0.5
git push origin :refs/tags/v1.0.5  # Delete remote tag
# Then run build-and-release.sh again
```

### GitHub Actions Fails
- Check build logs at: https://github.com/aminshamim/tagcache/actions
- Common issues: missing dependencies, frontend build failures
- Fix and re-tag if necessary

## 📦 Distribution Testing

### Test Local Binary
```bash
# Test the built binary
./target/release/tagcache --version
./target/release/tagcache server &
./target/release/tagcache --username admin --password password stats
killall tagcache
```

### Test Packages (Linux)
```bash
# Test .deb package
sudo dpkg -i dist/tagcache_*.deb
tagcache --version
sudo dpkg -r tagcache

# Test .rpm package  
sudo rpm -ivh dist/tagcache-*.rpm
tagcache --version
sudo rpm -e tagcache
```

## 🎯 Current Release: v1.0.5

The current version in `Cargo.toml` is **1.0.5**. 

- ✅ Frontend with embedded UI
- ✅ Full authentication system
- ✅ Configuration management
- ✅ TCP and HTTP protocols
- ✅ Tag-based invalidation
- ✅ Web dashboard
- ✅ Cross-platform support

Ready for distribution! 🚀
