<?php

namespace TagCache\Transport;

use TagCache\Config;
use TagCache\Exceptions\ApiException;
use TagCache\Exceptions\NotFoundException;

final class TcpTransport implements TransportInterface
{
    private string $host;
    private int $port;
    private int $timeoutMs;
    private int $poolSize;
    /** @var resource[] */
    private array $pool = [];
    private int $rr = 0;

    public function __construct(Config $config)
    {
        $tcp = $config->tcp + ['host' => '127.0.0.1', 'port' => 1984, 'timeout_ms' => 2000, 'pool_size' => 4];
        $this->host = (string)$tcp['host'];
        $this->port = (int)$tcp['port'];
        $this->timeoutMs = (int)$tcp['timeout_ms'];
        $this->poolSize = max(1, (int)$tcp['pool_size']);
    }

    /**
     * @return resource
     */
    private function conn()
    {
        // Round-robin from pool
        if (count($this->pool) < $this->poolSize) {
            $this->pool[] = $this->dial();
        }
        $this->rr = ($this->rr + 1) % max(1, count($this->pool));
        return $this->pool[$this->rr];
    }

    /**
     * @return resource
     */
    private function dial()
    {
        $addr = sprintf('%s:%d', $this->host, $this->port);
        $ctx = stream_context_create(['socket' => ['tcp_nodelay' => true]]);
        $sock = @stream_socket_client($addr, $errno, $errstr, $this->timeoutMs/1000, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) { throw new ApiException("TCP connect error: $errstr ($errno)"); }
        stream_set_timeout($sock, (int)($this->timeoutMs/1000), (int)(($this->timeoutMs%1000)*1000));
        // No explicit auth in current protocol; token could be embedded in commands or upgraded later.
        return $sock;
    }

    private function cmd(string $line): string
    {
        $sock = $this->conn();
        $line .= "\n";
        $w = @fwrite($sock, $line);
        if ($w === false) { throw new ApiException('TCP write failed'); }
    $resp = fgets($sock);
        if ($resp === false) { throw new ApiException('TCP read failed'); }
        return rtrim($resp, "\r\n");
    }

    /**
     * @param string[] $tags
     */
    public function put(string $key, mixed $value, ?int $ttlMs = null, array $tags = []): bool
    {
        $val = is_string($value) ? $value : json_encode($value);
        $ttl = $ttlMs !== null ? (string)$ttlMs : '-';
        $tagsStr = empty($tags) ? '-' : implode(',', array_map('strval', $tags));
        $resp = $this->cmd("PUT\t{$key}\t{$ttl}\t{$tagsStr}\t{$val}");
        if ($resp !== 'OK') throw new ApiException('PUT failed: '.$resp);
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $key): array
    {
        $resp = $this->cmd("GET\t{$key}");
    if ($resp === 'NF') throw new NotFoundException('Key not found: ' . $key);
    if (!preg_match('/^VALUE\t\s*(.*)$/s', $resp, $m)) throw new ApiException('GET bad resp: '.$resp);
    $raw = $m[1];
        $decoded = json_decode($raw, true);
        return $decoded !== null ? ['value' => $decoded] : ['value' => $raw];
    }

    public function delete(string $key): bool
    {
        $resp = $this->cmd("DEL\t{$key}");
        return str_contains($resp, 'ok');
    }

    /**
     * @param string[] $keys
     */
    public function invalidateKeys(array $keys): int
    {
        // No batch in TCP yet; loop
        $n = 0; foreach ($keys as $k) { if ($this->delete($k)) $n++; }
        return $n;
    }

    public function close(): void
    {
        foreach ($this->pool as $sock) {
            @fclose($sock);
        }
        $this->pool = [];
    }

    /**
     * @param string[] $tags
     */
    public function invalidateTags(array $tags, string $mode = 'any'): int
    {
        // any-mode: loop INV_TAG; all-mode not supported over TCP yet
        $n = 0; foreach ($tags as $t) { $resp = $this->cmd("INV_TAG\t{$t}"); $parts = explode("\t", $resp); if ($parts[0] === 'INV_TAG') { $n += (int)($parts[1] ?? 0); } }
        return $n;
    }
    
    /**
     * @return string[]
     */
    public function getKeysByTag(string $tag): array
    {
        $resp = $this->cmd("KEYS_BY_TAG	{$tag}"); $parts = explode("	", $resp);
        if ($parts[0] !== 'KEYS') return [];
        $keysStr = $parts[1] ?? '';
        return empty($keysStr) ? [] : explode(",", $keysStr);
    }

    /**
     * @param string[] $keys
     * @return array<string, array<string, mixed>>
     */
    public function bulkGet(array $keys): array
    {
        $out = []; 
        foreach ($keys as $k) { 
            try {
                $out[$k] = $this->get($k); 
            } catch (NotFoundException $e) {
                // Skip keys that don't exist - this matches HTTP transport behavior
            }
        } 
        return $out;
    }

    /**
     * @param string[] $keys
     */
    public function bulkDelete(array $keys): int
    {
        return $this->invalidateKeys($keys);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function search(array $params): array
    {
        // Limited search support for TCP - only tag-based queries
        if (isset($params['tag_any']) && is_array($params['tag_any'])) {
            // For tag_any, get keys for first tag (TCP protocol limitation)
            $tag = $params['tag_any'][0] ?? null;
            if ($tag) {
                $keys = $this->getKeysByTag($tag);
                $result = [];
                foreach ($keys as $key) {
                    $result[] = ['key' => $key];
                }
                return $result;
            }
        }
        
        if (isset($params['tag_all']) && is_array($params['tag_all'])) {
            // For tag_all, get keys for first tag (TCP protocol limitation)
            $tag = $params['tag_all'][0] ?? null;
            if ($tag) {
                $keys = $this->getKeysByTag($tag);
                $result = [];
                foreach ($keys as $key) {
                    $result[] = ['key' => $key];
                }
                return $result;
            }
        }
        
        // For other search types, throw exception
        throw new ApiException('search not supported over TCP transport; use HTTP');
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $resp = $this->cmd('STATS');
        $parts = explode("\t", $resp);
        if ($parts[0] !== 'STATS') throw new ApiException('STATS bad resp: '.$resp);
        return [
            'hits' => (int)($parts[1] ?? 0),
            'misses' => (int)($parts[2] ?? 0),
            'puts' => (int)($parts[3] ?? 0),
            'invalidations' => (int)($parts[4] ?? 0),
            'hit_ratio' => (float)($parts[5] ?? 0),
        ];
    }
    
    /**
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return $this->stats();
    }

    /**
     * @return string[]
     */
    public function list(int $limit = 100): array
    {
        // Not supported over TCP; use HTTP list
        throw new ApiException('list not supported over TCP transport; use HTTP');
    }

    public function flush(): int
    {
        $resp = $this->cmd('FLUSH');
        $parts = explode("\t", $resp);
        if ($parts[0] !== 'FLUSH') throw new ApiException('FLUSH bad resp: '.$resp);
        return (int)($parts[1] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        // TCP doesn't have a dedicated health endpoint, so we'll do a simple connectivity test
        try {
            $this->conn();
            return ['status' => 'ok', 'transport' => 'tcp'];
        } catch (\Exception $e) {
            throw new \TagCache\Exceptions\ConnectionException('TCP health check failed: ' . $e->getMessage());
        }
    }

    public function login(string $username, string $password): bool
    {
        throw new ApiException('login not supported over TCP transport; use HTTP');
    }

    /**
     * @return array<string, mixed>
     */
    public function rotateCredentials(): array
    {
        throw new ApiException('rotateCredentials not supported over TCP transport; use HTTP');
    }

    public function setupRequired(): bool
    {
        throw new ApiException('setupRequired not supported over TCP transport; use HTTP');
    }
}
