#!/bin/bash
# Setup Homebrew tap for TagCache

set -e

echo "🍺 Setting up Homebrew tap for TagCache..."

# Variables
GITHUB_USER="aminshamim"
TAP_REPO="homebrew-tap"
FORMULA_NAME="tagcache"

echo "📋 This script will help you set up a Homebrew tap"
echo "   User: $GITHUB_USER"
echo "   Tap repo: $TAP_REPO"
echo "   Formula: $FORMULA_NAME"
echo ""

# Check if tap repo exists locally
if [ -d "../$TAP_REPO" ]; then
    echo "📁 Found existing tap repo at ../$TAP_REPO"
    cd "../$TAP_REPO"
    git pull origin main
else
    echo "📥 Cloning or creating tap repository..."
    cd ..
    
    # Try to clone existing repo
    if git clone "https://github.com/$GITHUB_USER/$TAP_REPO.git" 2>/dev/null; then
        echo "✅ Cloned existing tap repo"
    else
        echo "📝 Creating new tap repository..."
        mkdir -p "$TAP_REPO"
        cd "$TAP_REPO"
        git init
        git remote add origin "https://github.com/$GITHUB_USER/$TAP_REPO.git"
        
        # Create README
        cat > README.md << EOF
# Homebrew Tap for $GITHUB_USER

## Installation

\`\`\`bash
brew tap $GITHUB_USER/tap
brew install $FORMULA_NAME
\`\`\`

## Available Formulas

- **TagCache** - Lightweight, sharded, tag-aware in-memory cache server
EOF
        
        git add README.md
        git commit -m "Initial tap setup"
    fi
    
    cd "$TAP_REPO"
fi

# Create Formula directory if it doesn't exist
mkdir -p Formula

# Copy the formula
cp "../tagcache/packaging/homebrew/tagcache.rb" "Formula/$FORMULA_NAME.rb"

echo "✅ Copied formula to Formula/$FORMULA_NAME.rb"

# Commit and push
git add .
git commit -m "Add/update TagCache formula $(cat VERSION || grep '^version = ' Cargo.toml | head -1 | cut -d'"' -f2)" || echo "No changes to commit"

echo ""
echo "🚀 Next steps:"
echo ""
echo "1. **Create the tap repository on GitHub:**"
echo "   https://github.com/new"
echo "   Repository name: $TAP_REPO"
echo "   Description: Homebrew formulas for $GITHUB_USER"
echo "   Public repository"
echo ""
echo "2. **Push the formula:**"
echo "   cd ../$TAP_REPO"
echo "   git push -u origin main"
echo ""
echo "3. **Users can then install with:**"
echo "   brew tap $GITHUB_USER/tap"
echo "   brew install $FORMULA_NAME"
echo ""
echo "4. **Test the formula locally:**"
echo "   brew install --build-from-source ./Formula/$FORMULA_NAME.rb"
echo ""
echo "📁 Tap repository location: $(pwd)"
echo "📝 Formula location: $(pwd)/Formula/$FORMULA_NAME.rb"
