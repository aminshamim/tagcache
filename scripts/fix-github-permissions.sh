#!/bin/bash
# Fix GitHub Actions permissions for TagCache

echo "🔧 Fixing GitHub Actions permissions..."

# Check if we're in a git repository
if ! git rev-parse --git-dir > /dev/null 2>&1; then
    echo "❌ Not in a git repository"
    exit 1
fi

# Get repository URL
REPO_URL=$(git remote get-url origin)
if [[ "$REPO_URL" =~ github\.com[:/]([^/]+)/([^/.]+) ]]; then
    OWNER="${BASH_REMATCH[1]}"
    REPO="${BASH_REMATCH[2]}"
    echo "📋 Repository: $OWNER/$REPO"
else
    echo "❌ Could not parse GitHub repository from: $REPO_URL"
    exit 1
fi

echo ""
echo "The 'Resource not accessible by integration' error usually occurs due to:"
echo ""
echo "1. **Repository Permissions** (most common)"
echo "   - Go to: https://github.com/$OWNER/$REPO/settings/actions"
echo "   - Under 'Actions permissions', select 'Allow all actions and reusable workflows'"
echo "   - Under 'Workflow permissions', select 'Read and write permissions'"
echo "   - Check 'Allow GitHub Actions to create and approve pull requests'"
echo ""
echo "2. **Token Permissions** (if using organization)"
echo "   - Go to: https://github.com/organizations/$OWNER/settings/actions"
echo "   - Ensure Actions are allowed for this organization"
echo ""
echo "3. **Branch Protection** (if applicable)"
echo "   - Go to: https://github.com/$OWNER/$REPO/settings/branches"
echo "   - Ensure branch protection rules don't block Actions"
echo ""
echo "4. **Repository is Private and on Free Plan**"
echo "   - Private repos have limited Actions minutes on free plans"
echo "   - Consider making the repo public or upgrading plan"
echo ""
echo "✅ **Quick Fix Steps:**"
echo "1. Visit: https://github.com/$OWNER/$REPO/settings/actions"
echo "2. Set Actions permissions to 'Allow all actions'"
echo "3. Set Workflow permissions to 'Read and write permissions'"
echo "4. Enable 'Allow GitHub Actions to create and approve pull requests'"
echo "5. Save settings"
echo ""
echo "After fixing permissions, you can test with:"
echo "  - Manual workflow: Go to Actions tab → 'Test Release' → 'Run workflow'"
echo "  - Or push a new tag: git tag v1.0.1 && git push origin v1.0.1"
echo ""
echo "🔗 Direct links:"
echo "  - Actions settings: https://github.com/$OWNER/$REPO/settings/actions"
echo "  - Actions tab: https://github.com/$OWNER/$REPO/actions"
