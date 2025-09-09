<?php declare(strict_types=1);

namespace TagCache\Exceptions;

final class TimeoutException extends ApiException
{
    public function __construct(string $message = 'Operation timed out', int $code = 408, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
    
    public static function afterSeconds(int $seconds): self
    {
        return new self("Operation timed out after {$seconds} seconds", 408);
    }
}
