#!/bin/bash
# Complete automated release pipeline for TagCache
set -e

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

print_step() {
    echo -e "${BLUE}🚀 $1${NC}"
}

print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

# Show help
show_help() {
    cat << EOF
🚀 TagCache Automated Release Pipeline

USAGE:
    $0 [VERSION]

DESCRIPTION:
    Complete automated release process for TagCache.
    Updates version across all files, builds, tests, and creates release.

EXAMPLES:
    $0 1.0.6          # Release version 1.0.6
    $0                # Use version from VERSION file

WHAT IT DOES:
    1. ✅ Updates version in all files (VERSION, Cargo.toml, etc.)
    2. ✅ Builds and tests locally
    3. ✅ Creates git tag
    4. ✅ Provides push commands for GitHub Actions
    5. ✅ Maintains backward compatibility for old download links

SAFETY FEATURES:
    - Checks git working directory is clean
    - Validates version format
    - Tests build before tagging
    - Verifies both binaries (tagcache + bench_tcp) are included
    - Creates backup of previous VERSION

EOF
}

# Check prerequisites
check_prerequisites() {
    print_step "Checking prerequisites"
    
    # Check git
    if ! git rev-parse --git-dir > /dev/null 2>&1; then
        print_error "Not in a git repository"
        exit 1
    fi
    
    # Check clean working directory
    if [ -n "$(git status --porcelain)" ]; then
        print_error "Git working directory is not clean. Commit or stash changes first:"
        git status --short
        exit 1
    fi
    
    # Check Rust
    if ! command -v cargo > /dev/null 2>&1; then
        print_error "Rust/Cargo not found. Install from https://rustup.rs/"
        exit 1
    fi
    
    print_success "Prerequisites OK"
}

# Update version across all files
update_version() {
    local new_version="$1"
    
    print_step "Updating version to $new_version"
    
    # Backup current VERSION file
    if [ -f "VERSION" ]; then
        cp VERSION VERSION.backup
    fi
    
    # Run version update script
    ./scripts/update-version.sh "$new_version"
    
    print_success "Version updated to $new_version"
}

# Build and test
build_and_test() {
    print_step "Building and testing"
    
    # Build frontend
    cd app
    if command -v pnpm > /dev/null 2>&1; then
        pnpm install --frozen-lockfile || pnpm install
        pnpm build
    else
        npm install
        npm run build
    fi
    cd ..
    
    # Build Rust with embedded UI
    cargo build --release --features embed-ui
    
    # Verify both binaries exist and work
    if [ ! -f "target/release/tagcache" ]; then
        print_error "tagcache binary not found"
        exit 1
    fi
    
    if [ ! -f "target/release/bench_tcp" ]; then
        print_error "bench_tcp binary not found"
        exit 1
    fi
    
    # Test binaries
    if ! ./target/release/tagcache --version > /dev/null 2>&1; then
        print_error "tagcache binary test failed"
        exit 1
    fi
    
    if ! timeout 5 ./target/release/bench_tcp --help > /dev/null 2>&1; then
        print_error "bench_tcp binary test failed"
        exit 1
    fi
    
    print_success "Build and test completed"
}

# Create local distribution for testing
create_test_distribution() {
    local version="$1"
    
    print_step "Creating test distribution"
    
    # Clean and create dist directory
    rm -rf dist/
    mkdir -p dist/
    
    # Determine platform
    OS=$(uname -s)
    ARCH=$(uname -m)
    
    case "$OS" in
        Darwin)
            if [ "$ARCH" = "arm64" ]; then
                DIST_NAME="tagcache-macos-arm64"
            else
                DIST_NAME="tagcache-macos-x86_64"
            fi
            ;;
        Linux)
            if [ "$ARCH" = "aarch64" ]; then
                DIST_NAME="tagcache-linux-arm64"
            else
                DIST_NAME="tagcache-linux-x86_64"
            fi
            ;;
        *)
            DIST_NAME="tagcache-local"
            ;;
    esac
    
    # Create distribution
    cd target/release
    tar czf "../../dist/${DIST_NAME}.tar.gz" tagcache bench_tcp
    cd ../..
    
    # Verify distribution
    if ./scripts/verify-distributions.sh | grep -q "✅.*found"; then
        print_success "Test distribution created and verified"
    else
        print_error "Distribution verification failed"
        exit 1
    fi
}

# Create git tag and prepare for release
create_release() {
    local version="$1"
    local tag_name="v$version"
    
    print_step "Creating release tag"
    
    # Check if tag already exists
    if git tag -l | grep -q "^$tag_name$"; then
        print_warning "Tag $tag_name already exists"
        read -p "Delete and recreate? (y/N): " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            git tag -d "$tag_name"
            print_success "Deleted existing tag"
        else
            print_error "Aborted"
            exit 1
        fi
    fi
    
    # Commit version changes
    git add .
    git commit -m "Bump version to $tag_name"
    
    # Create annotated tag
    git tag -a "$tag_name" -m "TagCache $tag_name

🚀 Release $tag_name

This release includes:
- ✅ tagcache server binary
- ✅ bench_tcp benchmark tool
- ✅ Cross-platform support
- ✅ Embedded web UI
- ✅ Complete installation packages

Installation:
- Homebrew: brew install aminshamim/tap/tagcache
- Direct download: Check release assets
- Package managers: .deb and .rpm available

Both tagcache and bench_tcp binaries are included in all distributions."
    
    print_success "Created tag $tag_name"
}

# Show next steps
show_next_steps() {
    local version="$1"
    local tag_name="v$version"
    
    echo ""
    echo "🎉 Release $tag_name Ready!"
    echo "=========================="
    echo ""
    echo "📋 What was done:"
    echo "  ✅ Updated VERSION file to $version"
    echo "  ✅ Updated all references in Cargo.toml, README.md, etc."
    echo "  ✅ Built and tested both binaries"
    echo "  ✅ Created test distribution"
    echo "  ✅ Committed changes"
    echo "  ✅ Created git tag $tag_name"
    echo ""
    echo "🚀 Next steps:"
    echo ""
    echo "1. Push the changes and tag to GitHub:"
    echo "   ${GREEN}git push origin main${NC}"
    echo "   ${GREEN}git push origin $tag_name${NC}"
    echo ""
    echo "2. Monitor GitHub Actions build:"
    echo "   ${BLUE}https://github.com/aminshamim/tagcache/actions${NC}"
    echo ""
    echo "3. After release is published, verify downloads work:"
    echo "   ${BLUE}https://github.com/aminshamim/tagcache/releases/tag/$tag_name${NC}"
    echo ""
    echo "4. Update Homebrew SHA256 hashes (if needed):"
    echo "   ${YELLOW}./scripts/update-homebrew-shas.sh $version${NC}"
    echo ""
    echo "📦 Release will include:"
    echo "  - Binary releases for all platforms"
    echo "  - Debian package: tagcache_${version}_amd64.deb"
    echo "  - RPM package: tagcache-${version}-1.x86_64.rpm"  
    echo "  - Both tagcache and bench_tcp binaries"
    echo ""
    echo "🔗 Backward compatibility:"
    echo "  - Old download links will continue working"
    echo "  - Previous versions remain available"
    echo "  - GitHub automatically manages release assets"
}

# Main function
main() {
    local new_version="$1"
    
    echo "🏷️  TagCache Automated Release Pipeline"
    echo "======================================="
    
    # Get version
    if [ -z "$new_version" ]; then
        if [ -f "VERSION" ]; then
            new_version=$(cat VERSION | tr -d '\n')
            echo "Using version from VERSION file: $new_version"
        else
            print_error "No version provided and no VERSION file found"
            echo "Usage: $0 <version>"
            exit 1
        fi
    else
        echo "Target version: $new_version"
    fi
    
    # Validate version format
    if ! echo "$new_version" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
        print_error "Invalid version format. Use semantic versioning (e.g., 1.0.6)"
        exit 1
    fi
    
    echo ""
    
    # Run pipeline
    check_prerequisites
    update_version "$new_version"
    build_and_test
    create_test_distribution "$new_version"
    create_release "$new_version"
    show_next_steps "$new_version"
}

# Handle arguments
case "${1:-}" in
    -h|--help|help)
        show_help
        exit 0
        ;;
    *)
        main "$1"
        ;;
esac
