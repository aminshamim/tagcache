#!/bin/bash

SERVER="http://localhost:8080"
AUTH="admin:password"

echo "Starting aggressive load test for CPU profiling..."

# Function to generate random data
generate_data() {
    openssl rand -base64 $((RANDOM % 1000 + 100))
}

# Heavy PUT operations - 1000 concurrent
echo "Starting PUT operations..."
for i in {1..1000}; do
    (
        curl -s -u $AUTH -X POST $SERVER/put \
            -H "Content-Type: application/json" \
            -d "{\"key\":\"load_test_$i\",\"value\":\"$(generate_data)\",\"tags\":[\"perf\",\"test_$((i%50))\",\"heavy_$((i%20))\"],\"ttl_ms\":60000}"
    ) &
done

# Heavy GET operations - 500 concurrent
echo "Starting GET operations..."
for i in {1..500}; do
    (
        curl -s -u $AUTH "$SERVER/get/load_test_$((RANDOM%1000 + 1))"
    ) &
done

# Tag-based searches - 200 concurrent
echo "Starting tag search operations..."
for i in {1..200}; do
    (
        curl -s -u $AUTH "$SERVER/keys-by-tag?tag=test_$((i%50))&limit=100"
    ) &
done

# Stats requests - 100 concurrent
echo "Starting stats operations..."
for i in {1..100}; do
    (
        curl -s -u $AUTH "$SERVER/stats"
    ) &
done

# Bulk operations - 50 concurrent
echo "Starting bulk operations..."
for i in {1..50}; do
    (
        keys_json=$(printf '["load_test_%d","load_test_%d","load_test_%d","load_test_%d","load_test_%d"]' $((RANDOM%1000+1)) $((RANDOM%1000+1)) $((RANDOM%1000+1)) $((RANDOM%1000+1)) $((RANDOM%1000+1)))
        curl -s -u $AUTH -X POST "$SERVER/bulk-get" \
            -H "Content-Type: application/json" \
            -d "{\"keys\":$keys_json}"
    ) &
done

# Search operations - 50 concurrent  
echo "Starting search operations..."
for i in {1..50}; do
    (
        curl -s -u $AUTH -X POST "$SERVER/search" \
            -H "Content-Type: application/json" \
            -d "{\"tag_filter\":[\"perf\"],\"limit\":50}"
    ) &
done

echo "Load test launched - all operations running concurrently!"
echo "Wait a few seconds then run flamegraph profiling..."
echo "To stop load test: pkill -f curl"

# Keep generating continuous load
while true; do
    sleep 0.1
    # Continuous light load
    curl -s -u $AUTH "$SERVER/get/load_test_$((RANDOM%1000 + 1))" > /dev/null &
    curl -s -u $AUTH "$SERVER/stats" > /dev/null &
done
