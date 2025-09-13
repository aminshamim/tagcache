#!/bin/bash

SERVER="http://localhost:8080"
AUTH="admin:password"

echo "Starting VERY high intensity load test..."

# Kill any background curl processes first
pkill -f "curl.*localhost:8080" 2>/dev/null

# Continuous very intensive operations
echo "Launching massive concurrent load..."

# Function to run continuous operations
run_continuous_load() {
    local operation=$1
    while true; do
        case $operation in
            "put")
                for i in {1..50}; do
                    curl -s -u $AUTH -X POST $SERVER/put \
                        -H "Content-Type: application/json" \
                        -d "{\"key\":\"stress_${RANDOM}_$i\",\"value\":\"$(openssl rand -base64 100)\",\"tags\":[\"stress\",\"tag_$((RANDOM%20))\",\"load_$((RANDOM%5))\"]}" > /dev/null &
                done
                ;;
            "get")
                for i in {1..30}; do
                    curl -s -u $AUTH "$SERVER/get/stress_${RANDOM}_$((RANDOM%1000))" > /dev/null &
                done
                ;;
            "tag_search")
                for i in {1..20}; do
                    curl -s -u $AUTH "$SERVER/keys-by-tag?tag=tag_$((RANDOM%20))&limit=100" > /dev/null &
                done
                ;;
            "stats")
                for i in {1..10}; do
                    curl -s -u $AUTH "$SERVER/stats" > /dev/null &
                done
                ;;
            "search")
                for i in {1..10}; do
                    curl -s -u $AUTH -X POST "$SERVER/search" \
                        -H "Content-Type: application/json" \
                        -d "{\"tag_filter\":[\"stress\"],\"limit\":50}" > /dev/null &
                done
                ;;
        esac
        sleep 0.01  # Very short delay
    done
}

# Launch background processes for different operation types
run_continuous_load "put" &
PUT_PID=$!

run_continuous_load "get" &
GET_PID=$!

run_continuous_load "tag_search" &
TAG_PID=$!

run_continuous_load "stats" &
STATS_PID=$!

run_continuous_load "search" &
SEARCH_PID=$!

echo "High intensity load test running with PIDs: PUT=$PUT_PID GET=$GET_PID TAG=$TAG_PID STATS=$STATS_PID SEARCH=$SEARCH_PID"
echo "Press Ctrl+C to stop..."

# Wait for interrupt
trap "echo 'Stopping load test...'; kill $PUT_PID $GET_PID $TAG_PID $STATS_PID $SEARCH_PID 2>/dev/null; pkill -f 'curl.*localhost:8080' 2>/dev/null; echo 'Load test stopped'; exit" INT

# Keep script running
wait
