#!/bin/bash
# Quick release checker and guide for TagCache
set -e

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo "🚀 TagCache Release Guide"
echo "========================="

# Get current version
VERSION=$(grep '^version = ' Cargo.toml | head -1 | cut -d'"' -f2)
echo -e "${BLUE}Current version: v$VERSION${NC}"

# Check git status
if [ -n "$(git status --porcelain)" ]; then
    echo -e "${YELLOW}⚠️  Git working directory is not clean${NC}"
    echo "Please commit or stash changes first:"
    git status --short
    exit 1
else
    echo -e "${GREEN}✅ Git working directory is clean${NC}"
fi

# Check if frontend is built
if [ ! -d "app/dist" ]; then
    echo -e "${YELLOW}⚠️  Frontend not built${NC}"
    echo "Building frontend..."
    cd app
    if command -v pnpm > /dev/null 2>&1; then
        pnpm install && pnpm build
    else
        npm install && npm run build
    fi
    cd ..
    echo -e "${GREEN}✅ Frontend built${NC}"
else
    echo -e "${GREEN}✅ Frontend already built${NC}"
fi

# Check if tag exists
TAG_NAME="v$VERSION"
if git tag -l | grep -q "^$TAG_NAME$"; then
    echo -e "${YELLOW}⚠️  Tag $TAG_NAME already exists${NC}"
    echo "You may need to:"
    echo "1. Update version in Cargo.toml"
    echo "2. Or delete existing tag: git tag -d $TAG_NAME"
    echo "3. Then run this script again"
    exit 1
else
    echo -e "${GREEN}✅ Tag $TAG_NAME is available${NC}"
fi

# Test build
echo "🔨 Testing local build..."
if cargo build --release --features embed-ui > /dev/null 2>&1; then
    echo -e "${GREEN}✅ Local build successful${NC}"
else
    echo -e "${YELLOW}❌ Local build failed${NC}"
    echo "Please fix build errors before releasing"
    exit 1
fi

echo ""
echo "🎯 Ready to release TagCache v$VERSION!"
echo ""
echo "Choose your release method:"
echo ""
echo "1. 🚀 AUTOMATIC (Recommended)"
echo "   ./scripts/build-and-release.sh"
echo "   - Builds locally for testing"
echo "   - Creates git tag"
echo "   - Provides instructions for GitHub push"
echo ""
echo "2. 🏷️  MANUAL TAG ONLY"
echo "   git tag -a v$VERSION -m 'TagCache v$VERSION'"
echo "   git push origin v$VERSION"
echo "   - Creates tag and triggers GitHub Actions"
echo "   - GitHub builds all platforms automatically"
echo ""
echo "3. 📋 STEP BY STEP"
echo "   a) Update version in Cargo.toml if needed"
echo "   b) Commit any final changes"
echo "   c) Run: git tag -a v$VERSION -m 'TagCache v$VERSION'"
echo "   d) Run: git push origin v$VERSION"
echo "   e) Monitor: https://github.com/aminshamim/tagcache/actions"
echo ""
echo "After release is published:"
echo "✅ Update Homebrew formula: ./scripts/update-homebrew-shas.sh $VERSION"
echo "✅ Test downloads and verify installation"
echo "✅ Update documentation if needed"
echo ""
echo "Current version will create:"
echo "- 📦 Binary releases for all platforms"
echo "- 🐧 Debian package: tagcache_${VERSION}_amd64.deb"
echo "- 🔴 RPM package: tagcache-${VERSION}-1.x86_64.rpm"
echo "- 🍺 Homebrew formula update"
echo "- 🐳 Docker image (if configured)"
