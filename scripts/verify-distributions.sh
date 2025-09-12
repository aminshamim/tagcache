#!/bin/bash
# Verify that both tagcache and bench_tcp are included in distributions
set -e

echo "🔍 TagCache Distribution Verification"
echo "===================================="

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

verify_tarball() {
    local file="$1"
    echo "Checking $file..."
    
    if [ ! -f "$file" ]; then
        echo -e "${RED}❌ File not found: $file${NC}"
        return 1
    fi
    
    # Extract and check contents
    local temp_dir=$(mktemp -d)
    tar -xzf "$file" -C "$temp_dir" 2>/dev/null || {
        echo -e "${RED}❌ Failed to extract $file${NC}"
        rm -rf "$temp_dir"
        return 1
    }
    
    # Check for tagcache binary
    if [ -f "$temp_dir/tagcache" ]; then
        echo -e "${GREEN}✅ tagcache binary found${NC}"
    else
        echo -e "${RED}❌ tagcache binary missing${NC}"
    fi
    
    # Check for bench_tcp binary
    if [ -f "$temp_dir/bench_tcp" ]; then
        echo -e "${GREEN}✅ bench_tcp binary found${NC}"
    else
        echo -e "${RED}❌ bench_tcp binary missing${NC}"
    fi
    
    # Cleanup
    rm -rf "$temp_dir"
    echo ""
}

verify_zip() {
    local file="$1"
    echo "Checking $file..."
    
    if [ ! -f "$file" ]; then
        echo -e "${RED}❌ File not found: $file${NC}"
        return 1
    fi
    
    # Check contents without extracting
    if unzip -l "$file" | grep -q "tagcache.exe"; then
        echo -e "${GREEN}✅ tagcache.exe found${NC}"
    else
        echo -e "${RED}❌ tagcache.exe missing${NC}"
    fi
    
    if unzip -l "$file" | grep -q "bench_tcp.exe"; then
        echo -e "${GREEN}✅ bench_tcp.exe found${NC}"
    else
        echo -e "${RED}❌ bench_tcp.exe missing${NC}"
    fi
    echo ""
}

verify_package() {
    local file="$1"
    local type="$2"
    echo "Checking $file ($type package)..."
    
    if [ ! -f "$file" ]; then
        echo -e "${RED}❌ File not found: $file${NC}"
        return 1
    fi
    
    case "$type" in
        "deb")
            if dpkg -c "$file" | grep -q "/usr/bin/tagcache"; then
                echo -e "${GREEN}✅ tagcache binary found in package${NC}"
            else
                echo -e "${RED}❌ tagcache binary missing from package${NC}"
            fi
            
            if dpkg -c "$file" | grep -q "/usr/bin/bench_tcp"; then
                echo -e "${GREEN}✅ bench_tcp binary found in package${NC}"
            else
                echo -e "${RED}❌ bench_tcp binary missing from package${NC}"
            fi
            ;;
        "rpm")
            if rpm -qlp "$file" 2>/dev/null | grep -q "/usr/bin/tagcache"; then
                echo -e "${GREEN}✅ tagcache binary found in package${NC}"
            else
                echo -e "${RED}❌ tagcache binary missing from package${NC}"
            fi
            
            if rpm -qlp "$file" 2>/dev/null | grep -q "/usr/bin/bench_tcp"; then
                echo -e "${GREEN}✅ bench_tcp binary found in package${NC}"
            else
                echo -e "${RED}❌ bench_tcp binary missing from package${NC}"
            fi
            ;;
    esac
    echo ""
}

# Check if we have a dist directory
if [ -d "dist" ]; then
    echo "Checking local dist/ directory..."
    
    # Check all tar.gz files
    for file in dist/*.tar.gz; do
        [ -f "$file" ] && verify_tarball "$file"
    done
    
    # Check all zip files
    for file in dist/*.zip; do
        [ -f "$file" ] && verify_zip "$file"
    done
    
    # Check all deb files
    for file in dist/*.deb; do
        [ -f "$file" ] && verify_package "$file" "deb"
    done
    
    # Check all rpm files
    for file in dist/*.rpm; do
        [ -f "$file" ] && verify_package "$file" "rpm"
    done
else
    echo -e "${YELLOW}No dist/ directory found. Build distributions first:${NC}"
    echo "./scripts/build-and-release.sh"
fi

# Also check if we can build both binaries
echo "Verifying local build..."
if cargo build --release --bin tagcache --bin bench_tcp &>/dev/null; then
    if [ -f "target/release/tagcache" ] && [ -f "target/release/bench_tcp" ]; then
        echo -e "${GREEN}✅ Both binaries build successfully${NC}"
        
        # Test that they work
        if ./target/release/tagcache --version &>/dev/null; then
            echo -e "${GREEN}✅ tagcache binary works${NC}"
        else
            echo -e "${RED}❌ tagcache binary has issues${NC}"
        fi
        
        if ./target/release/bench_tcp --help &>/dev/null; then
            echo -e "${GREEN}✅ bench_tcp binary works${NC}"
        else
            echo -e "${RED}❌ bench_tcp binary has issues${NC}"
        fi
    else
        echo -e "${RED}❌ Built binaries not found${NC}"
    fi
else
    echo -e "${RED}❌ Build failed${NC}"
fi

echo ""
echo "📋 Summary:"
echo "- All TagCache distributions should include both 'tagcache' and 'bench_tcp' binaries"
echo "- Binary releases: Available in tar.gz (Unix) and zip (Windows) files"
echo "- Package releases: Available in .deb and .rpm packages at /usr/bin/"
echo "- Homebrew: Both binaries installed to \$(brew --prefix)/bin/"
echo "- Docker: Both binaries available in container"
echo ""
echo "If bench_tcp is missing from any distribution, please file an issue at:"
echo "https://github.com/aminshamim/tagcache/issues"
