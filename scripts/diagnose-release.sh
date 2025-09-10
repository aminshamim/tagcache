#!/bin/bash
# Diagnose GitHub Actions release issues

echo "🔍 Diagnosing TagCache Release Issues..."

# Check repository info
REPO_URL=$(git remote get-url origin)
if [[ "$REPO_URL" =~ github\.com[:/]([^/]+)/([^/.]+) ]]; then
    OWNER="${BASH_REMATCH[1]}"
    REPO="${BASH_REMATCH[2]}"
    echo "📋 Repository: $OWNER/$REPO"
else
    echo "❌ Could not parse repository URL"
    exit 1
fi

# Check tags
echo ""
echo "🏷️  Available tags:"
git tag -l | sort -V

# Check current status
echo ""
echo "📊 Current status:"
echo "  - Current branch: $(git branch --show-current)"
echo "  - Latest commit: $(git rev-parse --short HEAD)"
echo "  - Working directory: $(pwd)"

# Check if releases exist
echo ""
echo "🔗 Check your releases at:"
echo "  https://github.com/$OWNER/$REPO/releases"

echo ""
echo "🔧 Check workflow runs at:"
echo "  https://github.com/$OWNER/$REPO/actions"

echo ""
echo "🚀 **Next Steps:**"
echo ""
echo "**If Test Release worked but full Release failed:**"
echo "1. The permissions are fixed ✅"
echo "2. The issue is likely with cross-compilation or package building"
echo ""
echo "**Quick fixes:**"
echo "1. **Use the simplified workflow:**"
echo "   - I created 'release-simple.yml' which is more reliable"
echo "   - Disable the old 'release.yml' temporarily"
echo ""
echo "2. **Test locally first:**"
echo "   cargo build --release  # Test basic build"
echo "   ./scripts/build-all.sh  # Test cross-compilation"
echo ""
echo "3. **Create a new test tag:**"
echo "   git tag v1.0.2"
echo "   git push origin v1.0.2"
echo ""
echo "**Common workflow failures:**"
echo "- Cross-compilation tools not found (cross, musl-tools)"
echo "- Missing Rust targets"
echo "- Package build tools not installed (cargo-deb, cargo-generate-rpm)"
echo "- File path issues (case sensitivity, missing files)"
echo "- Runner timeout (complex builds take time)"
echo ""
echo "**Check the failed workflow logs to see exact error:**"
echo "https://github.com/$OWNER/$REPO/actions"
