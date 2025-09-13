#!/bin/bash

echo "Optimized TagCache Performance Test"
echo "=================================="

# Correct auth credentials
AUTH="YWRtaW46cGFzc3dvcmQ="  # admin:password in base64

# Function to run PUT requests with timing
run_puts() {
    echo "Running 500 PUT operations..."
    start_time=$(date +%s%N)
    for i in {1..500}; do
        curl -s -H "Authorization: Basic $AUTH" \
             -d "optimized_value_$i" \
             "http://localhost:8080/put/perfkey_$i?tags=perf,test,optimized" > /dev/null &
        if (( i % 50 == 0 )); then
            wait  # Batch processing
        fi
    done
    wait
    end_time=$(date +%s%N)
    put_duration=$(( (end_time - start_time) / 1000000 ))  # Convert to milliseconds
    echo "PUT operations completed in ${put_duration}ms"
}

# Function to run GET requests with timing
run_gets() {
    echo "Running 500 GET operations..."
    start_time=$(date +%s%N)
    for i in {1..500}; do
        curl -s -H "Authorization: Basic $AUTH" \
             "http://localhost:8080/get/perfkey_$i" > /dev/null &
        if (( i % 50 == 0 )); then
            wait  # Batch processing
        fi
    done
    wait
    end_time=$(date +%s%N)
    get_duration=$(( (end_time - start_time) / 1000000 ))  # Convert to milliseconds
    echo "GET operations completed in ${get_duration}ms"
}

# Function to get stats
get_stats() {
    echo ""
    echo "Performance Stats:"
    echo "=================="
    stats=$(curl -s -H "Authorization: Basic $AUTH" "http://localhost:8080/stats")
    echo "$stats" | grep -o '"hits":[0-9]*' | cut -d: -f2 | head -1 | xargs echo "Hits:"
    echo "$stats" | grep -o '"misses":[0-9]*' | cut -d: -f2 | head -1 | xargs echo "Misses:"
    echo "$stats" | grep -o '"puts":[0-9]*' | cut -d: -f2 | head -1 | xargs echo "Puts:"
    echo "$stats" | grep -o '"hit_ratio":[0-9.]*' | cut -d: -f2 | head -1 | xargs echo "Hit Ratio:"
}

echo "Starting optimized performance test..."
total_start=$(date +%s%N)

# Run operations
run_puts
run_gets
get_stats

total_end=$(date +%s%N)
total_duration=$(( (total_end - total_start) / 1000000 ))

echo ""
echo "Total test duration: ${total_duration}ms"
echo "Throughput: ~$((1000000 / total_duration)) operations/second"
