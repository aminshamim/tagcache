#!/bin/bash
set -e

if [ -x /usr/bin/tagcache ]; then
    exec /usr/bin/tagcache "$@"
elif [ -x /usr/local/bin/tagcache ]; then
    exec /usr/local/bin/tagcache "$@"
else
    echo "tagcache binary not found!"
    find / -name "tagcache" -type f 2>/dev/null
    exit 1
fi
