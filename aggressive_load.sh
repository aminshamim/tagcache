#!/bin/bash

SERVER="http://localhost:8080"
AUTH="admin:password"

echo "Creating aggressive CPU load test..."

# First, populate cache with data
echo "Populating cache..."
for i in {1..100}; do
    curl -s -u $AUTH -X POST $SERVER/put \
        -H "Content-Type: application/json" \
        -d "{\"key\":\"key_$i\",\"value\":\"$(openssl rand -base64 50)\",\"tags\":[\"tag_$((i%10))\",\"perf\"],\"ttl_ms\":300000}" > /dev/null &
done
wait

echo "Starting continuous heavy load..."

# Continuous heavy operations
while true; do
    # Burst of operations
    for j in {1..20}; do
        # GET operations
        curl -s -u $AUTH "$SERVER/get/key_$((RANDOM%100 + 1))" > /dev/null &
        
        # Tag searches  
        curl -s -u $AUTH "$SERVER/keys-by-tag?tag=tag_$((RANDOM%10))" > /dev/null &
        
        # Stats requests
        curl -s -u $AUTH "$SERVER/stats" > /dev/null &
        
        # PUT operations
        curl -s -u $AUTH -X POST $SERVER/put \
            -H "Content-Type: application/json" \
            -d "{\"key\":\"load_$j\",\"value\":\"$(openssl rand -base64 30)\",\"tags\":[\"load\",\"tag_$((j%5))\"]}" > /dev/null &
    done
    
    # Brief pause to avoid overwhelming
    sleep 0.1
done
