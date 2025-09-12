#!/bin/bash
# Complete build and release script for TagCache
set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

print_step() {
    echo -e "${BLUE}🔷 $1${NC}"
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

# Get current version from Cargo.toml
get_version() {
    grep '^version = ' Cargo.toml | head -1 | cut -d'"' -f2
}

# Check if git working directory is clean
check_git_clean() {
    if [ -n "$(git status --porcelain)" ]; then
        print_error "Git working directory is not clean. Commit or stash changes first."
        exit 1
    fi
}

# Main function
main() {
    echo "🚀 TagCache Build and Release Pipeline"
    echo "======================================"
    
    # Get version
    VERSION=$(get_version)
    TAG_NAME="v$VERSION"
    
    echo "Current version: $VERSION"
    echo "Release tag: $TAG_NAME"
    echo ""
    
    # Check prerequisites
    print_step "Checking prerequisites..."
    
    if ! command -v cargo > /dev/null 2>&1; then
        print_error "Rust/Cargo not found. Install from https://rustup.rs/"
        exit 1
    fi
    
    if ! git rev-parse --git-dir > /dev/null 2>&1; then
        print_error "Not in a git repository"
        exit 1
    fi
    
    print_success "Prerequisites OK"
    
    # Check git status
    print_step "Checking git status..."
    check_git_clean
    print_success "Git working directory is clean"
    
    # Build frontend first
    print_step "Building frontend..."
    cd app
    if ! command -v pnpm > /dev/null 2>&1; then
        print_warning "pnpm not found, using npm..."
        npm install
        npm run build
    else
        pnpm install
        pnpm build
    fi
    cd ..
    print_success "Frontend built successfully"
    
    # Test local build
    print_step "Testing local build..."
    cargo build --release --features embed-ui
    
    # Verify both binaries exist
    if [ ! -f "target/release/tagcache" ]; then
        print_error "tagcache binary not found"
        exit 1
    fi
    
    if [ ! -f "target/release/bench_tcp" ]; then
        print_error "bench_tcp binary not found"
        exit 1
    fi
    
    print_success "Both tagcache and bench_tcp built successfully"
    
    # Create dist directory
    mkdir -p dist
    rm -rf dist/*
    
    # Build all targets (local only - cross-compilation done by GitHub Actions)
    print_step "Building for current platform..."
    
    # Determine current platform
    OS=$(uname -s)
    ARCH=$(uname -m)
    
    case "$OS" in
        Darwin)
            if [ "$ARCH" = "arm64" ]; then
                TARGET="aarch64-apple-darwin"
                DIST_NAME="tagcache-macos-arm64"
            else
                TARGET="x86_64-apple-darwin"
                DIST_NAME="tagcache-macos-x86_64"
            fi
            ;;
        Linux)
            if [ "$ARCH" = "aarch64" ]; then
                TARGET="aarch64-unknown-linux-gnu"
                DIST_NAME="tagcache-linux-arm64"
            else
                TARGET="x86_64-unknown-linux-gnu"  
                DIST_NAME="tagcache-linux-x86_64"
            fi
            ;;
        MINGW*|MSYS*|CYGWIN*)
            TARGET="x86_64-pc-windows-msvc"
            DIST_NAME="tagcache-windows-x86_64"
            ;;
        *)
            print_error "Unsupported OS: $OS"
            exit 1
            ;;
    esac
    
    print_step "Building for $TARGET..."
    cargo build --release --target "$TARGET" --features embed-ui
    
    # Package for current platform
    print_step "Packaging binaries..."
    cd target/$TARGET/release
    
    if [[ "$OS" == MINGW* ]] || [[ "$OS" == MSYS* ]] || [[ "$OS" == CYGWIN* ]]; then
        # Windows
        zip "../../../dist/${DIST_NAME}.zip" tagcache.exe bench_tcp.exe
    else
        # Unix-like (macOS, Linux)
        strip tagcache bench_tcp || true
        tar czf "../../../dist/${DIST_NAME}.tar.gz" tagcache bench_tcp
    fi
    cd ../../..
    
    print_success "Packaged: dist/${DIST_NAME}.*"
    
    # Build packages (Linux only)
    if [ "$OS" = "Linux" ]; then
        print_step "Building Linux packages..."
        
        # Debian package
        if command -v cargo-deb > /dev/null 2>&1; then
            print_step "Building .deb package..."
            cargo deb --target x86_64-unknown-linux-gnu --features embed-ui
            find target/debian -name "*.deb" -exec cp {} dist/ \;
            print_success "Debian package created"
        else
            print_warning "cargo-deb not found, skipping .deb package"
        fi
        
        # RPM package  
        if command -v cargo-generate-rpm > /dev/null 2>&1; then
            print_step "Building .rpm package..."
            cargo generate-rpm
            find target/generate-rpm -name "*.rpm" -exec cp {} dist/ \;
            print_success "RPM package created"
        else
            print_warning "cargo-generate-rpm not found, skipping .rpm package"
        fi
    fi
    
    # Show what was built
    print_step "Build summary..."
    echo "📁 Distribution files created:"
    ls -la dist/
    
    # Git operations
    print_step "Preparing git release..."
    
    # Check if tag already exists
    if git tag -l | grep -q "^$TAG_NAME$"; then
        print_warning "Tag $TAG_NAME already exists locally"
        read -p "Delete and recreate? (y/N): " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            git tag -d "$TAG_NAME"
            print_success "Deleted existing tag"
        else
            print_error "Aborted"
            exit 1
        fi
    fi
    
    # Create tag
    git tag -a "$TAG_NAME" -m "TagCache $TAG_NAME"
    print_success "Created git tag: $TAG_NAME"
    
    # Show next steps
    echo ""
    echo "🎉 Local build complete!"
    echo "======================="
    echo ""
    echo "📋 Next steps:"
    echo ""
    echo "1. Push the tag to trigger GitHub Actions build:"
    echo "   git push origin $TAG_NAME"
    echo ""
    echo "2. This will automatically:"
    echo "   - Build for all platforms (Linux, macOS, Windows)"
    echo "   - Create packages (.deb, .rpm)"
    echo "   - Create GitHub release"
    echo "   - Upload all distribution files"
    echo ""
    echo "3. Monitor the build at:"
    echo "   https://github.com/aminshamim/tagcache/actions"
    echo ""
    echo "4. After release is published, update Homebrew:"
    echo "   ./scripts/update-homebrew-shas.sh $VERSION"
    echo ""
    echo "📁 Local files (for testing):"
    ls -la dist/
    echo ""
    echo "⚡ Test local binaries:"
    echo "   ./target/$TARGET/release/tagcache --version"
    echo "   ./target/$TARGET/release/bench_tcp --help"
    echo "   ./target/$TARGET/release/tagcache server"
}

# Run main function
main "$@"
