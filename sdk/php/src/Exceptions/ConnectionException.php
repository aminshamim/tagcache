<?php declare(strict_types=1);

namespace TagCache\Exceptions;

final class ConnectionException extends ApiException
{
    public function __construct(string $message = 'Connection error', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
    
    public static function forHost(string $host, int $port): self
    {
        return new self("Failed to connect to {$host}:{$port}", 0);
    }
}
