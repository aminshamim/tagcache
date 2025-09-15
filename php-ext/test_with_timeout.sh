#!/bin/bash

# Wrapper script to test with timeout
php reproduce_timeout.php &
PHP_PID=$!

# Wait up to 10 seconds
sleep 10

# Check if PHP is still running
if kill -0 $PHP_PID 2>/dev/null; then
    echo "PHP process still running - killing it"
    kill -9 $PHP_PID
    echo "Test timed out or hung"
    exit 1
else
    echo "PHP process completed normally"
    wait $PHP_PID
    exit $?
fi