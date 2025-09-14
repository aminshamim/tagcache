#!/bin/bash
# 
# Build Configuration Script for TagCache Extension with Serialization Support
# This script helps configure the build environment for optional serializers
#

echo "🔧 TagCache Extension Build Configuration"
echo "========================================"

# Check PHP version and environment
PHP_VERSION=$(php -r "echo PHP_VERSION;")
echo "PHP Version: $PHP_VERSION"

PHP_CONFIG_PATH=$(which php-config)
echo "PHP Config: $PHP_CONFIG_PATH"

PHPIZE_PATH=$(which phpize)
echo "phpize: $PHPIZE_PATH"

echo ""
echo "📦 Checking for Optional Serialization Libraries"
echo "================================================"

# Function to check if an extension is available
check_extension() {
    local ext_name=$1
    if php -m | grep -q "^$ext_name$"; then
        echo "✅ $ext_name: Available"
        return 0
    else
        echo "❌ $ext_name: Not available"
        return 1
    fi
}

# Check for igbinary
check_extension "igbinary"
HAVE_IGBINARY=$?

# Check for msgpack  
check_extension "msgpack"
HAVE_MSGPACK=$?

echo ""
echo "🛠️  Build Configuration Options"
echo "================================"

# Create config.m4 additions for optional serializers
CONFIG_ADDITIONS=""

if [ $HAVE_IGBINARY -eq 0 ]; then
    echo "Including igbinary support..."
    CONFIG_ADDITIONS="$CONFIG_ADDITIONS --enable-igbinary-support"
fi

if [ $HAVE_MSGPACK -eq 0 ]; then
    echo "Including msgpack support..."
    CONFIG_ADDITIONS="$CONFIG_ADDITIONS --enable-msgpack-support"
fi

# Create a build script
cat > build_with_serializers.sh << 'EOF'
#!/bin/bash
# Generated build script with serialization support

set -e

echo "🚀 Building TagCache extension with serialization support..."

# Clean previous build
if [ -f Makefile ]; then
    make clean
fi

# Run phpize
phpize

# Configure with available options
./configure $CONFIG_ADDITIONS

# Build
make

# Install (optional - requires sudo)
# sudo make install

echo "✅ Build complete!"
echo ""
echo "To install system-wide: sudo make install"
echo "To test: make test"
echo "To load in PHP: php -d extension=./modules/tagcache.so -m | grep tagcache"
EOF

chmod +x build_with_serializers.sh

echo ""
echo "📋 Build Summary"
echo "================"
echo "igbinary support: $([ $HAVE_IGBINARY -eq 0 ] && echo 'YES' || echo 'NO')"
echo "msgpack support:  $([ $HAVE_MSGPACK -eq 0 ] && echo 'YES' || echo 'NO')"
echo ""
echo "Generated: build_with_serializers.sh"
echo ""
echo "🔨 To build:"
echo "   ./build_with_serializers.sh"
echo ""
echo "📖 To install optional serializers:"
echo "   # For igbinary:"
echo "   pecl install igbinary"
echo ""
echo "   # For msgpack:"  
echo "   pecl install msgpack"
echo ""
echo "   # Then rebuild TagCache extension"