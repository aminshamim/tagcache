#!/bin/bash
# Release script for TagCache
set -e

VERSION=${1}
if [ -z "$VERSION" ]; then
    echo "Usage: $0 <version>"
    echo "Example: $0 0.1.0"
    exit 1
fi

# Validate version format
if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Error: Version must be in format X.Y.Z (e.g., 0.1.0)"
    exit 1
fi

echo "🚀 Preparing TagCache release $VERSION"

# Check if git is clean
if [ -n "$(git status --porcelain)" ]; then
    echo "❌ Git working directory is not clean. Please commit or stash changes."
    exit 1
fi

# Update version in Cargo.toml
echo "📝 Updating version in Cargo.toml..."
sed -i.bak "s/^version = \".*\"/version = \"$VERSION\"/" Cargo.toml

# Update version in homebrew formula
echo "📝 Updating version in Homebrew formula..."
sed -i.bak "s/version \".*\"/version \"$VERSION\"/" packaging/homebrew/tagcache.rb

# Test build
echo "🔨 Testing build..."
cargo test
cargo build --release

# Create git tag
echo "🏷️  Creating git tag..."
git add Cargo.toml packaging/homebrew/tagcache.rb
git commit -m "Release v$VERSION" || true
git tag "v$VERSION"

echo "✅ Release prepared successfully!"
echo ""
echo "Next steps:"
echo "1. Push to GitHub: git push origin main && git push origin v$VERSION"
echo "2. GitHub Actions will automatically build and create the release"
echo "3. Update SHA256 hashes in Homebrew formula after release is published"
echo "4. Submit PR to homebrew-core"
echo ""
echo "Monitor the release at: https://github.com/$(git remote get-url origin | sed 's/.*github.com[:/]\([^/]*\/[^.]*\).*/\1/')/releases"
