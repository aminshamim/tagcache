#!/bin/bash
# Complete manual release script for TagCache
# This script can either use existing builds or build everything from scratch

set -e

# Get version from VERSION file
if [ ! -f "VERSION" ]; then
    echo "❌ VERSION file not found!"
    echo "Please create a VERSION file with the version number (e.g., 1.0.7)"
    exit 1
fi

VERSION=$(cat VERSION | tr -d '\n')
RELEASE_TAG="v$VERSION"

echo "🚀 Creating complete release for TagCache $RELEASE_TAG"

# Check if we should build first
BUILD_FIRST=false
if [ "$1" = "--build" ] || [ "$1" = "-b" ]; then
    BUILD_FIRST=true
    echo "🏗️ Will build all distributions first"
elif [ ! -d "dist" ] || [ -z "$(ls -A dist/ 2>/dev/null)" ]; then
    echo "❓ No distribution files found. Would you like to build them first? (y/N)"
    read -r response
    if [[ "$response" =~ ^[Yy]$ ]]; then
        BUILD_FIRST=true
    fi
fi

# Build if requested or needed
if [ "$BUILD_FIRST" = true ]; then
    echo "🔨 Building all distributions..."
    ./scripts/build-and-release-complete.sh
    if [ $? -ne 0 ]; then
        echo "❌ Build failed! Aborting release."
        exit 1
    fi
    echo "✅ Build completed successfully"
fi

# Check if we have the required files
REQUIRED_FILES=(
    "dist/tagcache-macos-arm64.tar.gz"
    "dist/tagcache-macos-x86_64.tar.gz"
    "dist/tagcache-linux-x86_64.tar.gz"
    "dist/tagcache-linux-arm64.tar.gz"
    "dist/tagcache-windows-x86_64.zip"
    "dist/tagcache_${VERSION}_amd64.deb"
    "dist/tagcache-${VERSION}-1.x86_64.rpm"
)

MISSING_FILES=()

for file in "${REQUIRED_FILES[@]}"; do
    if [ ! -f "$file" ]; then
        MISSING_FILES+=("$file")
    fi
done

if [ ${#MISSING_FILES[@]} -gt 0 ]; then
    echo "❌ Missing distribution files:"
    for file in "${MISSING_FILES[@]}"; do
        echo "   - $file"
    done
    echo ""
    echo "📋 To build missing files, run:"
    echo "   ./scripts/build-all-manual.sh"
    exit 1
fi

echo "✅ Found required distribution files"

# Check if GitHub CLI is available
if ! command -v gh &> /dev/null; then
    echo "❌ GitHub CLI (gh) not found. Please use the web interface method."
    echo "📋 Manual upload instructions:"
    echo "1. Go to: https://github.com/aminshamim/tagcache/releases"
    echo "2. Find or create release for $RELEASE_TAG"
    echo "3. Upload these files:"
    echo "   - dist/tagcache-macos-arm64.tar.gz"
    echo "   - dist/tagcache-macos-x86_64.tar.gz"
    exit 1
fi

# Check if authenticated
if ! gh auth status &> /dev/null; then
    echo "❌ Not authenticated with GitHub CLI"
    echo "Run: gh auth login"
    exit 1
fi

echo "✅ GitHub CLI authenticated"

# Check if we should update README
UPDATE_README=true
if [ -f "README.md.backup" ]; then
    echo "📝 README backup found. README was likely already updated during build."
    echo "❓ Update README again with new version links? (y/N)"
    read -r response
    if [[ ! "$response" =~ ^[Yy]$ ]]; then
        UPDATE_README=false
    fi
fi

# Update README if requested
if [ "$UPDATE_README" = true ]; then
    echo "📝 Updating README.md with version $VERSION links..."
    
    # Create backup if it doesn't exist
    if [ ! -f "README.md.backup" ]; then
        cp README.md README.md.backup
    fi
    
    # Update version-specific download links
    sed -i.tmp "s|/latest/download/|/download/v${VERSION}/|g" README.md
    sed -i.tmp "s|tagcache_amd64\.deb|tagcache_${VERSION}_amd64.deb|g" README.md
    sed -i.tmp "s|tagcache\.x86_64\.rpm|tagcache-${VERSION}-1.x86_64.rpm|g" README.md
    
    # Clean up temporary files
    rm -f README.md.tmp
    
    echo "✅ Updated README.md with v$VERSION download links"
fi

# Create release notes
RELEASE_NOTES="# TagCache v$VERSION

## 📱 Available Downloads

### macOS
- **Apple Silicon (M1/M2/M3)**: [tagcache-macos-arm64.tar.gz](https://github.com/aminshamim/tagcache/releases/download/$RELEASE_TAG/tagcache-macos-arm64.tar.gz)
- **Intel x86_64**: [tagcache-macos-x86_64.tar.gz](https://github.com/aminshamim/tagcache/releases/download/$RELEASE_TAG/tagcache-macos-x86_64.tar.gz)

### Linux (Ubuntu/Debian/CentOS/RHEL/etc.)
- **x86_64**: [tagcache-linux-x86_64.tar.gz](https://github.com/aminshamim/tagcache/releases/download/$RELEASE_TAG/tagcache-linux-x86_64.tar.gz)
- **ARM64**: [tagcache-linux-arm64.tar.gz](https://github.com/aminshamim/tagcache/releases/download/$RELEASE_TAG/tagcache-linux-arm64.tar.gz)

### Package Managers
- **Ubuntu/Debian**: [tagcache_${VERSION}_amd64.deb](https://github.com/aminshamim/tagcache/releases/download/$RELEASE_TAG/tagcache_${VERSION}_amd64.deb)
- **RHEL/CentOS/Fedora**: [tagcache-${VERSION}-1.x86_64.rpm](https://github.com/aminshamim/tagcache/releases/download/$RELEASE_TAG/tagcache-${VERSION}-1.x86_64.rpm)

### Windows
- **x86_64**: [tagcache-windows-x86_64.zip](https://github.com/aminshamim/tagcache/releases/download/$RELEASE_TAG/tagcache-windows-x86_64.zip)

## 📋 Installation

### macOS
\`\`\`bash
# Apple Silicon Macs
wget https://github.com/aminshamim/tagcache/releases/download/$RELEASE_TAG/tagcache-macos-arm64.tar.gz
tar -xzf tagcache-macos-arm64.tar.gz
sudo mv tagcache bench_tcp /usr/local/bin/

# Intel Macs  
wget https://github.com/aminshamim/tagcache/releases/download/$RELEASE_TAG/tagcache-macos-x86_64.tar.gz
tar -xzf tagcache-macos-x86_64.tar.gz
sudo mv tagcache bench_tcp /usr/local/bin/
\`\`\`

### Linux (Ubuntu/Debian/CentOS/RHEL/etc.)
\`\`\`bash
# x86_64 (Intel/AMD)
wget https://github.com/aminshamim/tagcache/releases/download/$RELEASE_TAG/tagcache-linux-x86_64.tar.gz
tar -xzf tagcache-linux-x86_64.tar.gz
sudo mv tagcache bench_tcp /usr/local/bin/

# ARM64 (Raspberry Pi 4, ARM servers)
wget https://github.com/aminshamim/tagcache/releases/download/$RELEASE_TAG/tagcache-linux-arm64.tar.gz
tar -xzf tagcache-linux-arm64.tar.gz
sudo mv tagcache bench_tcp /usr/local/bin/
\`\`\`

### Package Manager Installation
\`\`\`bash
# Ubuntu/Debian
wget https://github.com/aminshamim/tagcache/releases/download/$RELEASE_TAG/tagcache_1.0.6_amd64.deb
sudo dpkg -i tagcache_1.0.6_amd64.deb

# RHEL/CentOS/Fedora
wget https://github.com/aminshamim/tagcache/releases/download/$RELEASE_TAG/tagcache-1.0.6-1.x86_64.rpm
sudo rpm -i tagcache-1.0.6-1.x86_64.rpm
# or with dnf/yum
sudo dnf install tagcache-1.0.6-1.x86_64.rpm
\`\`\`

### Windows
\`\`\`powershell
# Download and extract
Invoke-WebRequest -Uri \"https://github.com/aminshamim/tagcache/releases/download/$RELEASE_TAG/tagcache-windows-x86_64.zip\" -OutFile \"tagcache.zip\"
Expand-Archive -Path \"tagcache.zip\" -DestinationPath \"C:\\Program Files\\TagCache\"
# Add C:\\Program Files\\TagCache to your PATH
\`\`\`

## 🔧 What's Included
- \`tagcache\` - Main server binary with web UI embedded
- \`bench_tcp\` - TCP vs HTTP benchmark tool

## 📊 Verification
\`\`\`bash
# Test the installation
tagcache --version
bench_tcp --help
\`\`\`

## 🆕 What's New in v$VERSION
- Enhanced CLI functionality
- Improved error handling
- Performance optimizations
- Bug fixes and stability improvements"

# Check if release already exists
if gh release view $RELEASE_TAG &> /dev/null; then
    echo "📦 Release $RELEASE_TAG already exists. Adding assets..."
    
    # Upload assets to existing release
    gh release upload $RELEASE_TAG \
        dist/tagcache-macos-arm64.tar.gz \
        dist/tagcache-macos-x86_64.tar.gz \
        dist/tagcache-linux-x86_64.tar.gz \
        dist/tagcache-linux-arm64.tar.gz \
        dist/tagcache-windows-x86_64.zip \
        dist/tagcache_${VERSION}_amd64.deb \
        dist/tagcache-${VERSION}-1.x86_64.rpm \
        --clobber
        
    echo "✅ Assets uploaded to existing release"
else
    echo "📦 Creating new release $RELEASE_TAG..."
    
    # Create new release with assets
    gh release create $RELEASE_TAG \
        dist/tagcache-macos-arm64.tar.gz \
        dist/tagcache-macos-x86_64.tar.gz \
        dist/tagcache-linux-x86_64.tar.gz \
        dist/tagcache-linux-arm64.tar.gz \
        dist/tagcache-windows-x86_64.zip \
        dist/tagcache_${VERSION}_amd64.deb \
        dist/tagcache-${VERSION}-1.x86_64.rpm \
        --title "TagCache v$VERSION" \
        --notes "$RELEASE_NOTES"
        
    echo "✅ New release created"
fi

# Commit README changes if updated
if [ "$UPDATE_README" = true ]; then
    echo "📤 Committing README changes..."
    git add README.md
    if git diff --staged --quiet; then
        echo "ℹ️ No README changes to commit"
    else
        git commit -m "Update README.md download links for v$VERSION"
        echo "✅ Committed README changes"
        
        echo "❓ Push README changes to main branch? (Y/n)"
        read -r response
        if [[ ! "$response" =~ ^[Nn]$ ]]; then
            git push origin main
            echo "✅ Pushed README changes to main"
        fi
    fi
fi

# Check if tag exists and create/push if needed
if ! git tag -l | grep -q "^$RELEASE_TAG$"; then
    echo "🏷️ Creating tag $RELEASE_TAG..."
    git tag -a "$RELEASE_TAG" -m "TagCache $RELEASE_TAG"
    
    echo "❓ Push tag $RELEASE_TAG to GitHub? (Y/n)"
    read -r response
    if [[ ! "$response" =~ ^[Nn]$ ]]; then
        git push origin "$RELEASE_TAG"
        echo "✅ Pushed tag $RELEASE_TAG"
    fi
else
    echo "ℹ️ Tag $RELEASE_TAG already exists"
fi

echo ""
echo "🎉 Release $RELEASE_TAG is now available!"
echo "📋 Release page: https://github.com/aminshamim/tagcache/releases/tag/$RELEASE_TAG"
echo ""
echo "📥 Download URLs:"
echo "   macOS ARM64: https://github.com/aminshamim/tagcache/releases/download/$RELEASE_TAG/tagcache-macos-arm64.tar.gz"
echo "   macOS x86_64: https://github.com/aminshamim/tagcache/releases/download/$RELEASE_TAG/tagcache-macos-x86_64.tar.gz"
echo "   Linux x86_64: https://github.com/aminshamim/tagcache/releases/download/$RELEASE_TAG/tagcache-linux-x86_64.tar.gz"
echo "   Linux ARM64: https://github.com/aminshamim/tagcache/releases/download/$RELEASE_TAG/tagcache-linux-arm64.tar.gz"
echo "   Windows x86_64: https://github.com/aminshamim/tagcache/releases/download/$RELEASE_TAG/tagcache-windows-x86_64.zip"
echo "   Ubuntu/Debian: https://github.com/aminshamim/tagcache/releases/download/$RELEASE_TAG/tagcache_${VERSION}_amd64.deb"
echo "   RHEL/CentOS/Fedora: https://github.com/aminshamim/tagcache/releases/download/$RELEASE_TAG/tagcache-${VERSION}-1.x86_64.rpm"
echo ""
echo "🔍 Verify the release:"
echo "   curl -I https://github.com/aminshamim/tagcache/releases/download/$RELEASE_TAG/tagcache-macos-arm64.tar.gz"

# Show file info
echo ""
echo "📁 Uploaded files:"
ls -lah dist/
