# Ubuntu-based Dockerfile for TagCache v1.0.8
FROM ubuntu:24.04

# Install necessary packages
RUN apt-get update && apt-get install -y \
    curl \
    wget \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Download and install TagCache v1.0.8 from GitHub
RUN cd /tmp && \
    wget https://github.com/aminshamim/tagcache/releases/download/v1.0.8/tagcache_1.0.8_amd64.deb && \
    tar -xzf tagcache_1.0.8_amd64.deb && \
    cp usr/bin/tagcache /usr/bin/tagcache && \
    cp usr/bin/bench_tcp /usr/bin/bench_tcp && \
    chmod +x /usr/bin/tagcache /usr/bin/bench_tcp && \
    rm -rf tagcache_1.0.8_amd64.deb usr DEBIAN

# Create necessary directories and set permissions
RUN mkdir -p /etc/tagcache /var/lib/tagcache /var/log/tagcache && \
    useradd -r -s /bin/false tagcache && \
    chown -R tagcache:tagcache /etc/tagcache /var/lib/tagcache /var/log/tagcache

# Set environment variables
ENV PORT=8080
ENV TCP_PORT=1984
ENV HOST=0.0.0.0

# Expose ports
EXPOSE 8080 1984

# Create working directory
WORKDIR /var/lib/tagcache

# Switch to non-root user
USER tagcache

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:8080/health || exit 1

# Start TagCache
ENTRYPOINT ["tagcache"]
CMD ["server"]
