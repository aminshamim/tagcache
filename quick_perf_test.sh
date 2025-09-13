#!/bin/bash

echo "Quick Performance Test with Optimizations"
echo "========================================="

# Basic auth credentials
AUTH="YWRtaW46YWRtaW4="  # admin:admin in base64

# Function to run PUT requests
run_puts() {
    echo "Running PUT operations..."
    for i in {1..100}; do
        curl -s -H "Authorization: Basic $AUTH" \
             -d "value_$i" \
             "http://localhost:8080/put/testkey_$i?tags=tag1,tag2" > /dev/null &
    done
    wait
    echo "PUT operations completed"
}

# Function to run GET requests
run_gets() {
    echo "Running GET operations..."
    for i in {1..100}; do
        curl -s -H "Authorization: Basic $AUTH" \
             "http://localhost:8080/get/testkey_$i" > /dev/null &
    done
    wait
    echo "GET operations completed"
}

# Function to get stats
get_stats() {
    echo "Current stats:"
    curl -s -H "Authorization: Basic $AUTH" "http://localhost:8080/stats" | jq '.hits, .misses, .puts, .hit_ratio'
}

echo "Starting performance test..."
start_time=$(date +%s)

# Run operations
run_puts
run_gets
get_stats

end_time=$(date +%s)
duration=$((end_time - start_time))

echo "Performance test completed in $duration seconds"
