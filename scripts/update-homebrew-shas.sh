#!/bin/bash
# Update Homebrew formula SHA256 hashes after release
set -e

VERSION=${1}
REPO_URL="https://github.com/aminshamim/tagcache"

if [ -z "$VERSION" ]; then
    echo "Usage: $0 <version>"
    echo "Example: $0 0.1.0"
    exit 1
fi

echo "🔍 Updating SHA256 hashes for TagCache v$VERSION"

# Download and calculate SHA256 for each platform
PLATFORMS=(
    "tagcache-macos-x86_64.tar.gz"
    "tagcache-macos-arm64.tar.gz"
    "tagcache-linux-x86_64.tar.gz" 
    "tagcache-linux-arm64.tar.gz"
)

declare -A SHAS

for platform in "${PLATFORMS[@]}"; do
    echo "📥 Downloading $platform..."
    url="$REPO_URL/releases/download/v$VERSION/$platform"
    
    if curl -sLf "$url" -o "/tmp/$platform"; then
        sha=$(shasum -a 256 "/tmp/$platform" | cut -d' ' -f1)
        SHAS["$platform"]=$sha
        echo "✅ $platform: $sha"
        rm "/tmp/$platform"
    else
        echo "❌ Failed to download $platform"
        exit 1
    fi
done

# Update Homebrew formula
echo "📝 Updating Homebrew formula..."
formula_file="packaging/homebrew/tagcache.rb"

sed -i.bak \
    -e "s/REPLACE_WITH_ACTUAL_SHA256_FOR_INTEL_MAC/${SHAS["tagcache-macos-x86_64.tar.gz"]}/g" \
    -e "s/REPLACE_WITH_ACTUAL_SHA256_FOR_ARM_MAC/${SHAS["tagcache-macos-arm64.tar.gz"]}/g" \
    -e "s/REPLACE_WITH_ACTUAL_SHA256_FOR_LINUX_X86_64/${SHAS["tagcache-linux-x86_64.tar.gz"]}/g" \
    -e "s/REPLACE_WITH_ACTUAL_SHA256_FOR_LINUX_ARM64/${SHAS["tagcache-linux-arm64.tar.gz"]}/g" \
    "$formula_file"

echo "✅ Updated Homebrew formula with SHA256 hashes"
echo ""
echo "📋 Summary:"
for platform in "${PLATFORMS[@]}"; do
    echo "  $platform: ${SHAS["$platform"]}"
done

echo ""
echo "Next steps for Homebrew:"
echo "1. Test the formula: brew install --build-from-source packaging/homebrew/tagcache.rb"
echo "2. Fork homebrew-core: https://github.com/Homebrew/homebrew-core"
echo "3. Copy the updated formula to Formula/tagcache.rb"
echo "4. Submit a PR to homebrew-core"
