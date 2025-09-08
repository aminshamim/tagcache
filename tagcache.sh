#!/bin/bash

# TagCache Server Startup Script
# This script builds and runs all TagCache servers

set -e  # Exit on any error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration (can be overridden by environment variables)
HTTP_PORT=${PORT:-8080}
TCP_PORT=${TCP_PORT:-1984}
NUM_SHARDS=${NUM_SHARDS:-16}
CLEANUP_INTERVAL_MS=${CLEANUP_INTERVAL_MS:-60000}

echo -e "${BLUE}=====================================${NC}"
echo -e "${BLUE}    TagCache Server Startup${NC}"
echo -e "${BLUE}=====================================${NC}"
echo ""

# Check if Rust/Cargo is installed
if ! command -v cargo &> /dev/null; then
    echo -e "${RED}Error: cargo not found. Please install Rust and Cargo.${NC}"
    exit 1
fi

echo -e "${YELLOW}Configuration:${NC}"
echo -e "  HTTP Port: ${GREEN}${HTTP_PORT}${NC}"
echo -e "  TCP Port:  ${GREEN}${TCP_PORT}${NC}"
echo -e "  Shards:    ${GREEN}${NUM_SHARDS}${NC}"
echo -e "  Cleanup:   ${GREEN}${CLEANUP_INTERVAL_MS}ms${NC}"
echo ""

# Build the project
echo -e "${YELLOW}Building TagCache...${NC}"
if cargo build --release; then
    echo -e "${GREEN}✓ Build successful${NC}"
else
    echo -e "${RED}✗ Build failed${NC}"
    exit 1
fi

echo ""
echo -e "${YELLOW}Starting TagCache servers...${NC}"
echo -e "${BLUE}  - HTTP API server on port ${HTTP_PORT}${NC}"
echo -e "${BLUE}  - TCP protocol server on port ${TCP_PORT}${NC}"
echo ""
echo -e "${YELLOW}Press Ctrl+C to stop all servers${NC}"
echo ""

# Export environment variables for the server
export PORT=$HTTP_PORT
export TCP_PORT=$TCP_PORT
export NUM_SHARDS=$NUM_SHARDS
export CLEANUP_INTERVAL_MS=$CLEANUP_INTERVAL_MS

# Set logging level if not already set
export RUST_LOG=${RUST_LOG:-info}

# Function to handle cleanup on exit
cleanup() {
    echo ""
    echo -e "${YELLOW}Shutting down TagCache servers...${NC}"
    # Kill any remaining background processes
    jobs -p | xargs -r kill 2>/dev/null || true
    echo -e "${GREEN}✓ Servers stopped${NC}"
    exit 0
}

# Trap SIGINT (Ctrl+C) and SIGTERM
trap cleanup SIGINT SIGTERM

# Start the main TagCache server (runs both HTTP and TCP servers)
echo -e "${GREEN}Starting TagCache main server...${NC}"
./target/release/tagcache &
MAIN_PID=$!

# Wait for the main process
wait $MAIN_PID

# If we reach here, the main process exited
echo -e "${YELLOW}TagCache server has stopped${NC}"
cleanup
