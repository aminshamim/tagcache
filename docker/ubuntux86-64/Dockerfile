# Ubuntu x86-64 Dockerfile for TagCache with dpkg installation
FROM ubuntu:24.04

# Set environment variables to prevent interactive prompts
ENV DEBIAN_FRONTEND=noninteractive

# Install necessary packages
RUN apt-get update && apt-get install -y \
    curl \
    wget \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Copy the DEB package into the container
COPY tagcache_1.0.8_amd64.deb /tmp/tagcache_1.0.8_amd64.deb

# Install TagCache using dpkg properly
RUN dpkg -i /tmp/tagcache_1.0.8_amd64.deb && \
    rm /tmp/tagcache_1.0.8_amd64.deb

# Verify installation
RUN which tagcache && tagcache --version

# Set environment variables
ENV PORT=8080
ENV TCP_PORT=1984
ENV HOST=0.0.0.0

# Expose ports
EXPOSE 8080 1984

# Create working directory
WORKDIR /var/lib/tagcache

# Switch to non-root user (created by dpkg installation)
USER tagcache

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:8080/health || exit 1

# Start TagCache directly
ENTRYPOINT ["/usr/bin/tagcache"]
CMD ["server"]
