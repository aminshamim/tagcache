<?php
declare(strict_types=1);

namespace TagCache\Exceptions;

/**
 * Exception thrown when there are configuration-related errors
 */
class ConfigurationException extends \Exception
{
    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
