#!/bin/bash
# Build script for all supported targets
set -e

echo "🔨 Building TagCache for all supported platforms"

# Install required tools
echo "📦 Installing build tools..."
which cross >/dev/null 2>&1 || cargo install cross --git https://github.com/cross-rs/cross
which cargo-deb >/dev/null 2>&1 || cargo install cargo-deb
which cargo-generate-rpm >/dev/null 2>&1 || cargo install cargo-generate-rpm

# Define targets
TARGETS=(
    "x86_64-unknown-linux-gnu"
    "aarch64-unknown-linux-gnu" 
    "x86_64-apple-darwin"
    "aarch64-apple-darwin"
    "x86_64-pc-windows-msvc"
    "x86_64-unknown-linux-musl"
    "aarch64-unknown-linux-musl"
)

echo "🎯 Building for targets: ${TARGETS[@]}"

# Clean previous builds
cargo clean

for target in "${TARGETS[@]}"; do
    echo "🔨 Building for $target..."
    
    # Check if target is installed
    if ! rustup target list --installed | grep -q "$target"; then
        echo "📥 Installing target $target..."
        rustup target add "$target" || true
    fi
    
    # Build based on platform
    case "$target" in
        *apple-darwin*)
            if [[ "$OSTYPE" == "darwin"* ]]; then
                cargo build --release --target "$target"
            else
                echo "⚠️  Skipping $target (requires macOS)"
                continue
            fi
            ;;
        *windows*)
            if command -v cross >/dev/null 2>&1; then
                cross build --release --target "$target"
            else
                echo "⚠️  Skipping $target (cross not available)"
                continue
            fi
            ;;
        *musl*|*aarch64*)
            cross build --release --target "$target"
            ;;
        *)
            cargo build --release --target "$target"
            ;;
    esac
    
    echo "✅ Built $target"
done

echo ""
echo "📦 Building packages..."

# Build Debian package
if [[ "$OSTYPE" == "linux-gnu"* ]]; then
    echo "📦 Building .deb package..."
    cargo deb --target x86_64-unknown-linux-gnu
    echo "✅ Built .deb package"
    
    echo "📦 Building .rpm package..."
    cargo generate-rpm
    echo "✅ Built .rpm package"
fi

echo ""
echo "✅ All builds complete!"
echo "📁 Binaries located in target/*/release/"
echo "📁 Packages located in target/debian/ and target/generate-rpm/"
