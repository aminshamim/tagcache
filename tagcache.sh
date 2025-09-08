#!/usr/bin/env bash

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
# Frontend dev server port (Vite default 5173)
VITE_PORT=${VITE_PORT:-5173}
# Set FRONTEND=0 to skip launching React app
LAUNCH_FRONTEND=${FRONTEND:-1}
# Frontend directory
FRONTEND_DIR=${FRONTEND_DIR:-app}

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
if [[ "${LAUNCH_FRONTEND}" == "1" ]]; then
    echo -e "  UI Port:   ${GREEN}${VITE_PORT}${NC}"
fi
echo ""

kill_port() {
    local port=$1
    local pids
    if command -v lsof >/dev/null 2>&1; then
        pids=$(lsof -ti tcp:"$port" || true)
    else
        return 0
    fi
    if [[ -n "$pids" ]]; then
        echo -e "${YELLOW}Killing processes on port $port: $pids${NC}"
        kill -9 $pids 2>/dev/null || true
    fi
}

# Build the project
echo -e "${YELLOW}Building TagCache...${NC}"
if cargo build --release; then
    echo -e "${GREEN}✓ Build successful${NC}"
else
    echo -e "${RED}✗ Build failed${NC}"
    exit 1
fi

echo ""
echo -e "${YELLOW}Ensuring target ports are free...${NC}"
kill_port "$HTTP_PORT"
kill_port "$TCP_PORT"
if [[ "${LAUNCH_FRONTEND}" == "1" ]]; then
    kill_port "$VITE_PORT"
fi

echo -e "${YELLOW}Starting TagCache services...${NC}"
echo -e "${BLUE}  - HTTP API server on port ${HTTP_PORT}${NC}"
echo -e "${BLUE}  - TCP protocol server on port ${TCP_PORT}${NC}"
if [[ "${LAUNCH_FRONTEND}" == "1" ]]; then
    echo -e "${BLUE}  - React/Vite frontend on port ${VITE_PORT}${NC}"
fi
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
FRONT_PID=""
cleanup() {
    echo ""
    echo -e "${YELLOW}Shutting down TagCache servers...${NC}"
    # Kill any remaining background processes
    if [[ -n "$MAIN_PID" ]]; then kill "$MAIN_PID" 2>/dev/null || true; fi
    if [[ -n "$FRONT_PID" ]]; then kill "$FRONT_PID" 2>/dev/null || true; fi
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
sleep 0.5
echo -e "${GREEN}✓ Backend PID $MAIN_PID${NC}"

if [[ "${LAUNCH_FRONTEND}" == "1" ]]; then
    if [[ -d "$FRONTEND_DIR" ]]; then
        echo -e "${GREEN}Starting frontend (Vite) dev server...${NC}"
        # Detect package manager
        PM=""
        if command -v pnpm >/dev/null 2>&1 && [[ -f "$FRONTEND_DIR/pnpm-lock.yaml" ]]; then PM="pnpm"; fi
        if [[ -z "$PM" && -f "$FRONTEND_DIR/yarn.lock" ]]; then PM="yarn"; fi
        if [[ -z "$PM" && -f "$FRONTEND_DIR/package.json" ]]; then PM="npm"; fi
        if [[ -z "$PM" ]]; then echo -e "${RED}No package manager detected in $FRONTEND_DIR (need package.json). Skipping UI.${NC}"; else
            (
                cd "$FRONTEND_DIR"
                case "$PM" in
                    pnpm) PORT="$VITE_PORT" pnpm dev & ;;
                    yarn) PORT="$VITE_PORT" yarn dev & ;;
                    npm)  PORT="$VITE_PORT" npm run dev & ;;
                esac
                FRONT_PID=$!
            )
            echo -e "${GREEN}✓ Frontend PID $FRONT_PID${NC}"
        fi
    else
        echo -e "${RED}Frontend directory $FRONTEND_DIR not found. Skipping UI.${NC}"
    fi
fi

echo -e "${BLUE}Streaming logs. First exit will terminate all processes.${NC}"

# Wait for either process to exit
if [[ -n "$FRONT_PID" ]]; then
    wait -n $MAIN_PID $FRONT_PID
else
    wait $MAIN_PID
fi

echo -e "${YELLOW}A service has exited. Initiating cleanup...${NC}"
cleanup
