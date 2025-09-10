class Tagcache < Formula
  desc "Lightweight, sharded, tag-aware in-memory cache server"
  homepage "https://github.com/aminshamim/tagcache"
  version "1.0.2"
  license "MIT"
  
  on_macos do
    if Hardware::CPU.intel?
      url "https://github.com/aminshamim/tagcache/releases/download/v#{version}/tagcache-macos-x86_64.tar.gz"
      sha256 "978de2727a5d80fc2071866539b3ac234800eef0897862d250bbff0f8ef6b767"
    end
    if Hardware::CPU.arm?
      url "https://github.com/aminshamim/tagcache/releases/download/v#{version}/tagcache-macos-arm64.tar.gz"
      sha256 "51b3006febedf92f40b7845ff33c18607f794788e811c354611e37bd38f14de4"
    end
  end

  on_linux do
    # Linux binaries will be available in future releases
    # For now, build from source on Linux
    depends_on "rust" => :build
  end

  def install
    if OS.linux?
      # Build from source on Linux
      system "cargo", "build", "--release"
      bin.install "target/release/tagcache"
    else
      # Use pre-built binary on macOS
      bin.install "tagcache"
    end
    
    # Install example configuration
    (etc/"tagcache").mkpath
    (var/"lib/tagcache").mkpath
    (var/"log/tagcache").mkpath
  end

  service do
    run [opt_bin/"tagcache"]
    environment_variables PORT: "8080", TCP_PORT: "1984", NUM_SHARDS: "16"
    keep_alive true
    log_path var/"log/tagcache/tagcache.log"
    error_log_path var/"log/tagcache/tagcache.log"
    working_dir var/"lib/tagcache"
  end

  test do
    # Test that the binary exists and shows help
    system "#{bin}/tagcache", "--help"
    
    # Test that we can start the server and it responds
    port = free_port
    pid = spawn "#{bin}/tagcache", "PORT=#{port}", "TCP_PORT=#{port + 1}"
    sleep 2
    
    begin
      # Try to connect to the HTTP endpoint
      system "curl", "-f", "http://localhost:#{port}/stats"
    ensure
      Process.kill("TERM", pid)
      Process.wait(pid)
    end
  end
end
