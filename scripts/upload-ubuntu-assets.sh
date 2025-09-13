#!/bin/bash
# Upload Ubuntu assets to current tag
# Script for uploading Ubuntu x86_64 build artifacts to the current GitHub tag

set -euo pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored output
log() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

warn() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

# Check if gh CLI is installed
if ! command -v gh &> /dev/null; then
    error "GitHub CLI (gh) is required but not installed."
    echo "Install it with: brew install gh"
    exit 1
fi

# Check if logged in to GitHub
if ! gh auth status &> /dev/null; then
    error "Not logged in to GitHub CLI. Please run: gh auth login"
    exit 1
fi

# Get current version from VERSION file
if [[ ! -f "VERSION" ]]; then
    error "VERSION file not found. Please run from the repository root."
    exit 1
fi

VERSION=$(cat VERSION)
log "Current version: ${VERSION}"

# Check if current tag exists
if ! gh release view "v${VERSION}" &> /dev/null; then
    error "Release v${VERSION} not found. Please create the release first."
    exit 1
fi

# Define asset paths
ASSETS_DIR="dist/ubuntu"
DEB_FILE="${ASSETS_DIR}/tagcache_${VERSION}_amd64.deb"
BINARY_X86_64="target/x86_64-unknown-linux-gnu/release/tagcache"
BENCH_X86_64="target/x86_64-unknown-linux-gnu/release/bench_tcp"
TARBALL="tagcache-linux-x86_64.tar.gz"

log "Checking for Ubuntu assets..."

# Check if DEB file exists
if [[ ! -f "${DEB_FILE}" ]]; then
    error "DEB file not found: ${DEB_FILE}"
    echo "Please run ubuntu-release.sh first to build the assets."
    exit 1
fi

# Check if binary exists
if [[ ! -f "${BINARY_X86_64}" ]]; then
    error "x86_64 binary not found: ${BINARY_X86_64}"
    echo "Please run ubuntu-release.sh first to build the assets."
    exit 1
fi

# Create tarball for x86_64 binaries
log "Creating x86_64 tarball..."
mkdir -p dist/ubuntu
pushd target/x86_64-unknown-linux-gnu/release/
tar -czf "../../../dist/ubuntu/${TARBALL}" tagcache bench_tcp
popd

success "Created tarball: dist/ubuntu/${TARBALL}"

# Function to upload asset with retry
upload_asset() {
    local file_path="$1"
    local asset_name="$2"
    local max_retries=3
    local retry=0
    
    while [[ $retry -lt $max_retries ]]; do
        log "Uploading ${asset_name} (attempt $((retry + 1))/${max_retries})..."
        
        # Check if asset already exists and delete it
        if gh release view "v${VERSION}" --json assets | jq -r '.assets[].name' | grep -q "^${asset_name}$"; then
            warn "Asset ${asset_name} already exists. Deleting..."
            gh release delete-asset "v${VERSION}" "${asset_name}" --yes
        fi
        
        # Upload the asset
        if gh release upload "v${VERSION}" "${file_path}" --clobber; then
            success "Successfully uploaded ${asset_name}"
            return 0
        else
            error "Failed to upload ${asset_name} (attempt $((retry + 1)))"
            retry=$((retry + 1))
            if [[ $retry -lt $max_retries ]]; then
                log "Retrying in 5 seconds..."
                sleep 5
            fi
        fi
    done
    
    error "Failed to upload ${asset_name} after ${max_retries} attempts"
    return 1
}

# Upload assets
log "Starting asset upload to release v${VERSION}..."

# Upload DEB package
upload_asset "${DEB_FILE}" "tagcache_${VERSION}_amd64.deb"

# Upload x86_64 tarball
upload_asset "dist/ubuntu/${TARBALL}" "${TARBALL}"

# Verify uploads
log "Verifying uploads..."
gh release view "v${VERSION}" --json assets | jq -r '.assets[] | select(.name | test("ubuntu|amd64|x86_64")) | "\(.name) (\(.size) bytes)"'

success "Ubuntu assets successfully uploaded to release v${VERSION}!"
log "Release URL: $(gh release view "v${VERSION}" --json url | jq -r '.url')"

# Clean up temporary tarball
rm -f "dist/ubuntu/${TARBALL}"
log "Cleaned up temporary files"
