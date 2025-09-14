#!/bin/bash
# Repository cleanup script - Remove development files and stale version references
set -e

echo "🧹 TagCache Repository Cleanup"
echo "=============================="

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

print_step() {
    echo -e "${BLUE}🔷 $1${NC}"
}

print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

# Get current version
CURRENT_VERSION=$(cat VERSION 2>/dev/null || grep '^version = ' Cargo.toml | head -1 | cut -d'"' -f2)
print_step "Current version: $CURRENT_VERSION"

# Remove development/build artifacts
print_step "Removing build artifacts and temporary files..."
rm -rf target/debug target/doc
rm -rf dist/
rm -f *.log
rm -f *.tar.gz *.zip *.deb *.rpm
rm -f tagcache.conf
print_success "Build artifacts cleaned"

# Remove debug and stale files
print_step "Removing debug and stale files..."
# Remove PHP SDK debug files
find sdk/php -name "debug_*.php" -delete 2>/dev/null || true
find sdk/php -name "test_*.php" -delete 2>/dev/null || true
rm -f sdk/php/tcp_test_results.txt 2>/dev/null || true
print_success "Debug files cleaned"

# Remove any backup files
print_step "Removing backup files..."
find . -name "*.bak" -delete 2>/dev/null || true
find . -name "*.backup" -delete 2>/dev/null || true
find . -name "*.old" -delete 2>/dev/null || true
find . -name "*.tmp" -delete 2>/dev/null || true
print_success "Backup files cleaned"

# Clean frontend build artifacts
if [ -d "app" ]; then
    print_step "Cleaning frontend build artifacts..."
    cd app
    rm -rf node_modules/.cache
    rm -rf dist
    rm -rf .vite
    rm -rf test-results
    rm -rf playwright-report
    cd ..
    print_success "Frontend artifacts cleaned"
fi

# Check for any remaining hardcoded version references
print_step "Checking for stale version references..."
STALE_REFS=$(grep -r "1\.0\.[0-5]" --exclude-dir=.git --exclude-dir=target --exclude-dir=node_modules --exclude-dir=vendor --exclude="*.lock" --exclude="*lock.yaml" --exclude="pnpm-lock.yaml" --exclude="Cargo.lock" . | wc -l | tr -d ' ')

if [ "$STALE_REFS" -gt 0 ]; then
    print_warning "Found $STALE_REFS potential stale version references:"
    grep -r "1\.0\.[0-5]" --exclude-dir=.git --exclude-dir=target --exclude-dir=node_modules --exclude-dir=vendor --exclude="*.lock" --exclude="*lock.yaml" --exclude="pnpm-lock.yaml" --exclude="Cargo.lock" . | head -10
    echo ""
    echo "Consider updating these to use dynamic version resolution or 'latest' links."
else
    print_success "No stale version references found"
fi

# Summary
echo ""
echo "📋 Repository Status:"
echo "- Current version: $CURRENT_VERSION"
echo "- Build artifacts: Cleaned"
echo "- Temporary files: Cleaned"  
echo "- Backup files: Cleaned"
echo ""
echo "🚀 Ready for release!"
echo ""
echo "Next steps:"
echo "1. Review changes: git status"
echo "2. Commit cleanup: git add -A && git commit -m 'Clean up repository for v$CURRENT_VERSION release'"
echo "3. Create release: ./scripts/release.sh"
