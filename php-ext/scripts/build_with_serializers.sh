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
