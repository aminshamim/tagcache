#!/bin/bash
# Create a production release for TagCache

set -e

echo "🚀 Creating production release for TagCache..."

# Build locally to ensure it works
echo "🔨 Testing local build..."
cargo build --release

if [ ! -f "target/release/tagcache" ]; then
    echo "❌ Build failed - no binary found"
    exit 1
fi

echo "✅ Local build successful"

# Create release assets
echo "📦 Creating release assets..."
mkdir -p dist

# Linux x86_64 (from local build)
cd target/release
tar czf ../../dist/tagcache-linux-x86_64.tar.gz tagcache
cd ../..

echo "✅ Created: dist/tagcache-linux-x86_64.tar.gz"

# Create a new tag for production release
VERSION_FILE="VERSION"
if [ -f "$VERSION_FILE" ]; then
    NEW_VERSION="v$(cat $VERSION_FILE)"
else
    # Fallback: get version from Cargo.toml
    NEW_VERSION="v$(grep '^version = ' Cargo.toml | head -1 | cut -d'"' -f2)"
fi
echo "🏷️  Creating production tag: $NEW_VERSION"

if git tag -l | grep -q "^$NEW_VERSION$"; then
    echo "⚠️  Tag $NEW_VERSION already exists. Deleting..."
    git tag -d $NEW_VERSION
    git push origin :refs/tags/$NEW_VERSION || true
fi

git tag $NEW_VERSION
echo "✅ Created tag: $NEW_VERSION"

echo ""
echo "📋 Next steps:"
echo "1. Push the tag: git push origin $NEW_VERSION"
echo "2. This will trigger the release workflow"
echo "3. Check: https://github.com/aminshamim/tagcache/actions"
echo ""
echo "Or create a manual release:"
echo "1. Go to: https://github.com/aminshamim/tagcache/releases/new"
echo "2. Tag: $NEW_VERSION"
echo "3. Upload: dist/tagcache-linux-x86_64.tar.gz"

# Show file info
echo ""
echo "📁 Release assets created:"
ls -la dist/
