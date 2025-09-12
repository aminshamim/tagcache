#!/bin/bash
# Central version management script for TagCache
set -e

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

print_step() {
    echo -e "${BLUE}🔄 $1${NC}"
}

print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

# Get version from VERSION file or parameter
get_version() {
    if [ -n "$1" ]; then
        echo "$1"
    elif [ -f "VERSION" ]; then
        cat VERSION | tr -d '\n'
    else
        print_error "No VERSION file found and no version provided"
        exit 1
    fi
}

# Update version in Cargo.toml
update_cargo_toml() {
    local version="$1"
    print_step "Updating Cargo.toml to version $version"
    
    # Update main version
    sed -i.bak "s/^version = \".*\"/version = \"$version\"/" Cargo.toml
    
    print_success "Updated Cargo.toml"
}

# Update version in Homebrew formula
update_homebrew_formula() {
    local version="$1"
    print_step "Updating Homebrew formula to version $version"
    
    local formula_file="packaging/homebrew/tagcache.rb"
    if [ -f "$formula_file" ]; then
        sed -i.bak "s/version \".*\"/version \"$version\"/" "$formula_file"
        print_success "Updated Homebrew formula"
    else
        print_warning "Homebrew formula not found: $formula_file"
    fi
}

# Update version in README.md examples
update_readme() {
    local version="$1"
    print_step "Updating README.md version references"
    
    # Update specific version references in installation commands
    sed -i.bak "s/tagcache_[0-9]\+\.[0-9]\+\.[0-9]\+_amd64\.deb/tagcache_${version}_amd64.deb/g" README.md
    sed -i.bak "s/tagcache-[0-9]\+\.[0-9]\+\.[0-9]\+-1\.x86_64\.rpm/tagcache-${version}-1.x86_64.rpm/g" README.md
    
    print_success "Updated README.md"
}

# Update version in BUILD_GUIDE.md
update_build_guide() {
    local version="$1"
    print_step "Updating BUILD_GUIDE.md version references"
    
    sed -i.bak "s/v[0-9]\+\.[0-9]\+\.[0-9]\+/v$version/g" BUILD_GUIDE.md
    sed -i.bak "s/version [0-9]\+\.[0-9]\+\.[0-9]\+/version $version/g" BUILD_GUIDE.md
    
    print_success "Updated BUILD_GUIDE.md"
}

# Update version in GitHub workflow if needed
update_github_workflow() {
    local version="$1"
    print_step "Checking GitHub workflow for version references"
    
    # The workflow uses dynamic versioning, so no changes needed
    print_success "GitHub workflow uses dynamic versioning"
}

# Clean up backup files
cleanup_backups() {
    print_step "Cleaning up backup files"
    find . -name "*.bak" -delete 2>/dev/null || true
    print_success "Cleaned up backup files"
}

# Main function
main() {
    local new_version="$1"
    
    echo "🏷️  TagCache Version Manager"
    echo "=========================="
    
    # Get version
    if [ -z "$new_version" ]; then
        new_version=$(get_version)
        echo "Using version from VERSION file: $new_version"
    else
        echo "Using provided version: $new_version"
        # Update VERSION file
        echo "$new_version" > VERSION
        print_success "Updated VERSION file"
    fi
    
    # Validate version format (semantic versioning)
    if ! echo "$new_version" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
        print_error "Invalid version format. Use semantic versioning (e.g., 1.0.6)"
        exit 1
    fi
    
    echo "Updating all files to version: $new_version"
    echo ""
    
    # Update all files
    update_cargo_toml "$new_version"
    update_homebrew_formula "$new_version"
    update_readme "$new_version"
    update_build_guide "$new_version"
    update_github_workflow "$new_version"
    
    # Clean up
    cleanup_backups
    
    echo ""
    echo "🎉 Version update complete!"
    echo "========================="
    echo ""
    echo "Updated files:"
    echo "  ✅ VERSION"
    echo "  ✅ Cargo.toml"
    echo "  ✅ packaging/homebrew/tagcache.rb"
    echo "  ✅ README.md"
    echo "  ✅ BUILD_GUIDE.md"
    echo ""
    echo "Next steps:"
    echo "1. Review changes: git diff"
    echo "2. Test build: cargo build --release"
    echo "3. Commit changes: git add . && git commit -m 'Bump version to v$new_version'"
    echo "4. Create release: ./scripts/build-and-release.sh"
    echo "5. Push tag: git push origin v$new_version"
    echo ""
    echo "🚀 Ready for release v$new_version!"
}

# Help function
show_help() {
    cat << EOF
TagCache Version Manager

USAGE:
    $0 [VERSION]

DESCRIPTION:
    Updates version across all TagCache files from a central point.
    If no VERSION is provided, reads from VERSION file.

EXAMPLES:
    $0 1.0.6           # Set version to 1.0.6
    $0                 # Use version from VERSION file

FILES UPDATED:
    - VERSION           (central version file)
    - Cargo.toml        (Rust package version)
    - packaging/homebrew/tagcache.rb    (Homebrew formula)
    - README.md         (installation examples)
    - BUILD_GUIDE.md    (build documentation)

WORKFLOW:
    1. Update VERSION file (or provide version as argument)
    2. Run this script to update all files
    3. Review and commit changes
    4. Run build-and-release.sh
    5. Push tag to trigger GitHub Actions

EOF
}

# Handle arguments
case "${1:-}" in
    -h|--help|help)
        show_help
        exit 0
        ;;
    "")
        main
        ;;
    *)
        main "$1"
        ;;
esac
