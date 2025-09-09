<?php declare(strict_types=1);

namespace TagCache\Exceptions;

final class NotFoundException extends ApiException
{
    public function __construct(string $message = 'Not found', int $code = 404, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
    
    public static function forKey(string $key): self
    {
        return new self("Key '{$key}' not found", 404);
    }
}
