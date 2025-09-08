FROM rust:1.79 as builder
WORKDIR /app
COPY Cargo.toml .
RUN mkdir -p src && echo 'fn main() {}' > src/main.rs && cargo build --release || true
COPY src ./src
RUN cargo build --release

FROM gcr.io/distroless/cc-debian12:nonroot
WORKDIR /
COPY --from=builder /app/target/release/tagcache /tagcache
ENV TC_LISTEN_ADDR=0.0.0.0:8080
USER nonroot:nonroot
EXPOSE 8080
ENTRYPOINT ["/tagcache"]
