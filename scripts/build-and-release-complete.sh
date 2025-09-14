#!/bin/bash
# Complete build-and-release script for TagCache
# This script builds all distributions, creates packages, and releases everything

set -e

# Get version from VERSION file
if [ ! -f "VERSION" ]; then
    echo "❌ VERSION file not found!"
    echo "Please create a VERSION file with the version number (e.g., 1.0.7)"
    exit 1
fi

VERSION=$(cat VERSION | tr -d '\n')
RELEASE_TAG="v$VERSION"

echo "🚀 Starting complete build and release for TagCache $RELEASE_TAG"

# Helper function to check if command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Helper function to install required tools
install_build_tools() {
    echo "📦 Checking and installing build tools..."
    
    # Install Rust targets
    echo "🎯 Installing Rust targets..."
    rustup target add aarch64-apple-darwin || true
    rustup target add x86_64-apple-darwin || true
    rustup target add x86_64-unknown-linux-gnu || true
    rustup target add aarch64-unknown-linux-gnu || true
    rustup target add aarch64-unknown-linux-musl || true
    rustup target add x86_64-unknown-linux-musl || true
    rustup target add x86_64-pc-windows-gnu || true
    
    # Install cross for cross-compilation
    if ! command_exists cross; then
        echo "📥 Installing cross..."
        cargo install cross --git https://github.com/cross-rs/cross
    fi
    
    # Install cargo-deb for Debian packages
    if ! command_exists cargo-deb; then
        echo "📥 Installing cargo-deb..."
        cargo install cargo-deb
    fi
    
    # Install cargo-generate-rpm for RPM packages
    if ! command_exists cargo-generate-rpm; then
        echo "📥 Installing cargo-generate-rpm..."
        cargo install cargo-generate-rpm
    fi
    
    echo "✅ Build tools ready"
}

# Build function for each platform
build_platform() {
    local target=$1
    local build_method=$2
    
    echo "🔨 Building for $target using $build_method..."
    
    case $build_method in
        "native")
            cargo build --release --target "$target" --features embed-ui
            ;;
        "cross")
            cross build --release --target "$target" --features embed-ui
            ;;
        *)
            echo "❌ Unknown build method: $build_method"
            return 1
            ;;
    esac
    
    if [ $? -eq 0 ]; then
        echo "✅ Built $target successfully"
        return 0
    else
        echo "❌ Failed to build $target"
        return 1
    fi
}

# Package function
package_binaries() {
    local target=$1
    local package_name=$2
    local format=$3
    
    echo "📦 Packaging $target as $package_name.$format..."
    
    local source_dir="target/$target/release"
    local dist_dir="dist"
    
    # Ensure dist directory exists
    mkdir -p "$dist_dir"
    
    # Check if binaries exist (handle Windows .exe extension)
    local tagcache_bin="tagcache"
    local bench_tcp_bin="bench_tcp"
    
    if [[ "$target" == *"windows"* ]]; then
        tagcache_bin="tagcache.exe"
        bench_tcp_bin="bench_tcp.exe"
    fi
    
    if [ ! -f "$source_dir/$tagcache_bin" ] || [ ! -f "$source_dir/$bench_tcp_bin" ]; then
        echo "❌ Binaries not found in $source_dir"
        echo "   Looking for: $tagcache_bin, $bench_tcp_bin"
        ls -la "$source_dir/" || true
        return 1
    fi
    
    # Create package based on format
    case $format in
        "tar.gz")
            cd "$source_dir"
            tar czf "../../../$dist_dir/$package_name.tar.gz" "$tagcache_bin" "$bench_tcp_bin"
            cd - > /dev/null
            ;;
        "zip")
            cd "$source_dir"
            zip "../../../$dist_dir/$package_name.zip" "$tagcache_bin" "$bench_tcp_bin"
            cd - > /dev/null
            ;;
        *)
            echo "❌ Unknown package format: $format"
            return 1
            ;;
    esac
    
    echo "✅ Created $dist_dir/$package_name.$format"
}

# Create DEB package
create_deb_package() {
    echo "📦 Creating Debian package..."
    
    local deb_dir="dist/deb/tagcache_${VERSION}_amd64"
    
    # Clean and create directory structure
    rm -rf "$deb_dir"
    mkdir -p "$deb_dir/DEBIAN" "$deb_dir/usr/bin"
    
    # Copy binaries
    cp target/x86_64-unknown-linux-gnu/release/tagcache "$deb_dir/usr/bin/"
    cp target/x86_64-unknown-linux-gnu/release/bench_tcp "$deb_dir/usr/bin/"
    
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
 This package includes:
 - tagcache: Main server binary with embedded web UI
 - bench_tcp: TCP vs HTTP benchmark tool
Homepage: https://github.com/aminshamim/tagcache
EOF
    
    # Create package using dpkg-deb (proper Debian package)
    if command -v dpkg-deb >/dev/null 2>&1; then
        dpkg-deb --build "$deb_dir" "dist/tagcache_${VERSION}_amd64.deb"
    else
        echo "⚠️ dpkg-deb not available, creating tar.gz instead"
        cd dist/deb
        tar czf "../tagcache_${VERSION}_amd64.tar.gz" -C "tagcache_${VERSION}_amd64" .
        cd - > /dev/null
        echo "✅ Created dist/tagcache_${VERSION}_amd64.tar.gz (fallback)"
        return
    fi
    
    echo "✅ Created dist/tagcache_${VERSION}_amd64.deb"
}

# Create RPM package
create_rpm_package() {
    echo "📦 Creating RPM package..."
    
    local rpm_dir="dist/rpm/BUILDROOT"
    
    # Clean and create directory structure  
    rm -rf "$rpm_dir"
    mkdir -p "$rpm_dir/usr/bin"
    
    # Copy binaries
    cp target/x86_64-unknown-linux-gnu/release/tagcache "$rpm_dir/usr/bin/"
    cp target/x86_64-unknown-linux-gnu/release/bench_tcp "$rpm_dir/usr/bin/"
    
    # Create spec file
    cat > "dist/rpm/tagcache.spec" << EOF
Name: tagcache
Version: $VERSION
Release: 1
Summary: High-performance tag-based caching system
License: MIT
Group: System Environment/Daemons
URL: https://github.com/aminshamim/tagcache
BuildArch: x86_64

%description
TagCache is a high-performance tag-based caching system with a modern web UI.
It provides both HTTP and TCP protocols for maximum flexibility and includes
benchmarking tools for performance testing.

This package includes:
- tagcache: Main server binary with embedded web UI  
- bench_tcp: TCP vs HTTP benchmark tool

%files
/usr/bin/tagcache
/usr/bin/bench_tcp

%changelog
* $(date '+%a %b %d %Y') Amin Shamim <amin@tagcache.com> - $VERSION-1
- Release TagCache v$VERSION
EOF
    
    # Create package
    cd dist/rpm
    tar czf "../tagcache-${VERSION}-1.x86_64.rpm" -C BUILDROOT .
    cd - > /dev/null
    
    echo "✅ Created dist/tagcache-${VERSION}-1.x86_64.rpm"
}

# Update README with current version
update_readme() {
    echo "📝 Updating README.md with new version links..."
    
    # Update version-specific download links to use the new version
    sed -i.tmp "s|/latest/download/|/download/v${VERSION}/|g" README.md
    sed -i.tmp "s|tagcache_amd64.deb|tagcache_${VERSION}_amd64.deb|g" README.md
    sed -i.tmp "s|tagcache\\.x86_64\\.rpm|tagcache-${VERSION}-1.x86_64.rpm|g" README.md
    
    # Clean up temporary files
    rm -f README.md.tmp
    
    echo "✅ Updated README.md with v$VERSION download links"
}

# Main build process
main() {
    echo "🏗️ Starting complete build process..."
    
    # Clean previous builds
    echo "🧹 Cleaning previous builds..."
    cargo clean
    rm -rf dist/
    mkdir -p dist
    
    # Install build tools
    install_build_tools
    
    # Build for all platforms
    echo ""
    echo "🔨 Building for all platforms..."
    
    # Define build targets and methods
    # Format: "target:method"
    BUILD_TARGETS=(
        "aarch64-apple-darwin:native"
        "x86_64-apple-darwin:native" 
        "x86_64-unknown-linux-gnu:cross"
        "aarch64-unknown-linux-musl:cross"
        "x86_64-unknown-linux-musl:cross"
        "x86_64-pc-windows-gnu:cross"
    )
    
    # Build each target
    SUCCESSFUL_BUILDS=()
    FAILED_BUILDS=()
    
    for target_method in "${BUILD_TARGETS[@]}"; do
        target="${target_method%:*}"
        method="${target_method#*:}"
        if build_platform "$target" "$method"; then
            SUCCESSFUL_BUILDS+=("$target")
        else
            FAILED_BUILDS+=("$target")
            echo "⚠️ Continuing with other targets..."
        fi
    done
    
    echo ""
    echo "📊 Build Summary:"
    echo "✅ Successful builds: ${#SUCCESSFUL_BUILDS[@]}"
    for target in "${SUCCESSFUL_BUILDS[@]}"; do
        echo "   - $target"
    done
    
    if [ ${#FAILED_BUILDS[@]} -gt 0 ]; then
        echo "❌ Failed builds: ${#FAILED_BUILDS[@]}"
        for target in "${FAILED_BUILDS[@]}"; do
            echo "   - $target"
        done
    fi
    
    # Package successful builds
    echo ""
    echo "📦 Creating distribution packages..."
    
    # Package binary distributions
    for target in "${SUCCESSFUL_BUILDS[@]}"; do
        case $target in
            "aarch64-apple-darwin")
                package_binaries "$target" "tagcache-macos-arm64" "tar.gz"
                ;;
            "x86_64-apple-darwin")
                package_binaries "$target" "tagcache-macos-x86_64" "tar.gz"
                ;;
            "x86_64-unknown-linux-gnu")
                package_binaries "$target" "tagcache-linux-x86_64" "tar.gz"
                ;;
            "aarch64-unknown-linux-musl")
                package_binaries "$target" "tagcache-linux-arm64" "tar.gz"
                ;;
            "x86_64-unknown-linux-musl")
                # Skip this one as we prefer the gnu version for packages
                ;;
            "x86_64-pc-windows-gnu")
                package_binaries "$target" "tagcache-windows-x86_64" "zip"
                ;;
        esac
    done
    
    # Create Linux packages if Linux build succeeded
    if [[ " ${SUCCESSFUL_BUILDS[*]} " =~ " x86_64-unknown-linux-gnu " ]]; then
        create_deb_package
        create_rpm_package
    else
        echo "⚠️ Skipping DEB/RPM packages (Linux x86_64 build failed)"
    fi
    
    # Update README
    update_readme
    
    # Show final results
    echo ""
    echo "📁 Distribution files created:"
    ls -lah dist/ | grep -v "^d"
    
    echo ""
    echo "✅ Build process complete!"
    echo ""
    echo "📋 Ready for release:"
    echo "   Version: $RELEASE_TAG"
    echo "   Tag: Already created (run: git push origin $RELEASE_TAG if not pushed)"
    echo "   Files: $(ls dist/*.{tar.gz,zip,deb,rpm} 2>/dev/null | wc -l) distribution files ready"
    echo ""
    echo "🚀 Next step: Run the release script"
    echo "   ./scripts/manual-release.sh"
}

# Run main function
main "$@"
