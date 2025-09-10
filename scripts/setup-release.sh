#!/bin/bash
# Setup script for TagCache release infrastructure
set -e

echo "🚀 Setting up TagCache release infrastructure..."

# Check prerequisites
echo "📋 Checking prerequisites..."

# Check if we're in a git repository
if ! git rev-parse --git-dir > /dev/null 2>&1; then
    echo "❌ Not in a git repository"
    exit 1
fi

# Check if Rust is installed
if ! command -v cargo > /dev/null 2>&1; then
    echo "❌ Rust/Cargo not found. Please install Rust: https://rustup.rs/"
    exit 1
fi

echo "✅ Git repository found"
echo "✅ Rust/Cargo found"

# Install required tools
echo "📦 Installing build tools..."

# Install cross for cross-compilation
if ! command -v cross > /dev/null 2>&1; then
    echo "Installing cross..."
    cargo install cross --git https://github.com/cross-rs/cross
else
    echo "✅ cross already installed"
fi

# Install cargo-deb for Debian packages
if ! cargo --list | grep -q "deb"; then
    echo "Installing cargo-deb..."
    cargo install cargo-deb
else
    echo "✅ cargo-deb already installed"
fi

# Install cargo-generate-rpm for RPM packages
if ! cargo --list | grep -q "generate-rpm"; then
    echo "Installing cargo-generate-rpm..."
    cargo install cargo-generate-rpm
else
    echo "✅ cargo-generate-rpm already installed"
fi

# Add required Rust targets
echo "🎯 Adding Rust targets..."
TARGETS=(
    "x86_64-unknown-linux-gnu"
    "aarch64-unknown-linux-gnu"
    "x86_64-unknown-linux-musl"
    "aarch64-unknown-linux-musl"
    "x86_64-apple-darwin"
    "aarch64-apple-darwin"
    "x86_64-pc-windows-msvc"
)

for target in "${TARGETS[@]}"; do
    if rustup target list --installed | grep -q "$target"; then
        echo "✅ $target already installed"
    else
        echo "📥 Installing $target..."
        rustup target add "$target" || echo "⚠️ Failed to install $target (may not be available on this platform)"
    fi
done

# Test local build
echo "🔨 Testing local build..."
if cargo build --release; then
    echo "✅ Local build successful"
else
    echo "❌ Local build failed"
    exit 1
fi

# Check if GitHub remote exists
if git remote get-url origin > /dev/null 2>&1; then
    REPO_URL=$(git remote get-url origin)
    echo "✅ GitHub remote found: $REPO_URL"
    
    # Extract owner/repo from URL
    if [[ "$REPO_URL" =~ github\.com[:/]([^/]+/[^/.]+) ]]; then
        REPO="${BASH_REMATCH[1]}"
        echo "📋 Repository: $REPO"
    fi
else
    echo "⚠️ No GitHub remote found. Add one with: git remote add origin https://github.com/username/tagcache.git"
fi

# Setup complete
echo ""
echo "🎉 Setup complete! Next steps:"
echo ""
echo "1. Update repository information in Cargo.toml:"
echo "   - repository: https://github.com/yourusername/tagcache"
echo "   - authors, license, description, etc."
echo ""
echo "2. Update contact info in packaging files:"
echo "   - packaging/debian/postinst"
echo "   - packaging/homebrew/tagcache.rb"
echo ""  
echo "3. Create your first release:"
echo "   ./scripts/release.sh 0.1.0"
echo ""
echo "4. Push to GitHub to trigger build:"
echo "   git push origin main"
echo "   git push origin v0.1.0"
echo ""
echo "5. After release is published, update Homebrew SHA256 hashes:"
echo "   ./scripts/update-homebrew-shas.sh 0.1.0"
echo ""
echo "📚 See RELEASE.md for complete documentation"

# Summary of what was created/updated
echo ""
echo "📁 Files created/updated:"
echo "  ├── .github/workflows/release.yml   (CI/CD pipeline)"
echo "  ├── packaging/"
echo "  │   ├── debian/                     (Debian package scripts)"
echo "  │   ├── homebrew/tagcache.rb        (Homebrew formula)"
echo "  │   ├── systemd/tagcache.service    (Systemd service)"
echo "  │   └── windows/tagcache.nsi        (Windows installer)"
echo "  ├── scripts/"
echo "  │   ├── build-all.sh               (Local multi-platform builds)"
echo "  │   ├── release.sh                 (Release preparation)"
echo "  │   └── update-homebrew-shas.sh    (Update Homebrew hashes)"
echo "  ├── Cross.toml                     (Cross-compilation config)"
echo "  ├── LICENSE                        (MIT License)"
echo "  ├── RELEASE.md                     (Release documentation)"
echo "  └── README.md                      (Updated with install instructions)"
