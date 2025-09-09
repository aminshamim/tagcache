<?php declare(strict_types=1);

namespace TagCache\Exceptions;

final class AuthenticationException extends ApiException
{
    public function __construct(string $message = 'Authentication failed', int $code = 401, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
    
    public static function invalidToken(): self
    {
        return new self('Invalid authentication token', 401);
    }
    
    public static function invalidCredentials(): self
    {
        return new self('Invalid username or password', 401);
    }
}
