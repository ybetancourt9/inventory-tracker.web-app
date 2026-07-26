<?php

declare(strict_types=1);

namespace InventoryTracker\Domain\Exception;

use RuntimeException;
use Throwable;

/**
 * Raised when a username collides with an existing account.
 */
final class UsernameAlreadyTaken extends RuntimeException
{
    public function __construct(private readonly string $username, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('Username "%s" is already taken.', $username), 0, $previous);
    }

    public function username(): string
    {
        return $this->username;
    }
}
