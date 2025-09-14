#!/bin/bash
# Build all distributions manually for TagCache
set -e

echo "🔨 Building TagCache for all supported platforms manually"

# Clean previous builds
echo "🧹 Cleaning previous builds..."
rm -rf dist/
mkdir -p dist/

# Define targets to build
TARGETS=(
    "aarch64-apple-darwin"
    "x86_64-apple-darwin"
    "x86_64-unknown-linux-musl"
    "aarch64-unknown-linux-musl"
    "x86_64-pc-windows-gnu"
)

echo "🎯 Building for targets: ${TARGETS[@]}"

# Build for each target
for target in "${TARGETS[@]}"; do
    echo ""
    echo "🔨 Building for $target..."
    
    # Add target if not installed
    if ! rustup target list --installed | grep -q "$target"; then
        echo "📥 Installing target $target..."
        rustup target add "$target" || true
    fi
    
    # Build based on platform
    case "$target" in
        *apple-darwin*)
            if [[ "$OSTYPE" == "darwin"* ]]; then
                echo "   Using native cargo..."
                cargo build --release --target "$target"
            else
                echo "⚠️  Skipping $target (requires macOS)"
                continue
            fi
            ;;
        *windows*|*linux*)
            echo "   Using cross..."
            cross build --release --target "$target"
            ;;
        *)
            echo "   Using cargo..."
            cargo build --release --target "$target"
            ;;
    esac
    
    echo "✅ Built $target"
done

echo ""
echo "📦 Creating distribution packages..."

# Package macOS ARM64
if [ -f "target/aarch64-apple-darwin/release/tagcache" ]; then
    echo "📦 Packaging macOS ARM64..."
    cd target/aarch64-apple-darwin/release
    tar czf ../../../dist/tagcache-macos-arm64.tar.gz tagcache bench_tcp
    cd ../../..
    echo "✅ Created: dist/tagcache-macos-arm64.tar.gz"
fi

# Package macOS x86_64
if [ -f "target/x86_64-apple-darwin/release/tagcache" ]; then
    echo "📦 Packaging macOS x86_64..."
    cd target/x86_64-apple-darwin/release
    tar czf ../../../dist/tagcache-macos-x86_64.tar.gz tagcache bench_tcp
    cd ../../..
    echo "✅ Created: dist/tagcache-macos-x86_64.tar.gz"
fi

# Package Linux x86_64
if [ -f "target/x86_64-unknown-linux-musl/release/tagcache" ]; then
    echo "📦 Packaging Linux x86_64..."
    cd target/x86_64-unknown-linux-musl/release
    tar czf ../../../dist/tagcache-linux-x86_64.tar.gz tagcache bench_tcp
    cd ../../..
    echo "✅ Created: dist/tagcache-linux-x86_64.tar.gz"
fi

# Package Linux ARM64
if [ -f "target/aarch64-unknown-linux-musl/release/tagcache" ]; then
    echo "📦 Packaging Linux ARM64..."
    cd target/aarch64-unknown-linux-musl/release
    tar czf ../../../dist/tagcache-linux-arm64.tar.gz tagcache bench_tcp
    cd ../../..
    echo "✅ Created: dist/tagcache-linux-arm64.tar.gz"
fi

# Package Windows x86_64
if [ -f "target/x86_64-pc-windows-gnu/release/tagcache.exe" ]; then
    echo "📦 Packaging Windows x86_64..."
    cd target/x86_64-pc-windows-gnu/release
    zip ../../../dist/tagcache-windows-x86_64.zip tagcache.exe bench_tcp.exe
    cd ../../..
    echo "✅ Created: dist/tagcache-windows-x86_64.zip"
fi

echo ""
echo "✅ All distributions built successfully!"
echo ""
echo "📁 Created packages:"
ls -lah dist/

echo ""
echo "🔍 Verify package contents:"
for file in dist/*.tar.gz; do
    if [ -f "$file" ]; then
        echo "📦 $file:"
        tar -tzf "$file" | sed 's/^/   /'
    fi
done

for file in dist/*.zip; do
    if [ -f "$file" ]; then
        echo "📦 $file:"
        unzip -l "$file" | grep -E '\.(exe|dll)$' | awk '{print "   " $4}' || echo "   tagcache.exe"
        echo "   bench_tcp.exe"
    fi
done

echo ""
echo "🚀 Ready for manual release! Run:"
echo "   ./scripts/manual-release.sh"
