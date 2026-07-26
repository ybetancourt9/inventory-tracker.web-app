<?php

declare(strict_types=1);

namespace InventoryTracker\Application\Auth;

use RuntimeException;

/**
 * Request-scoped holder for the identity established by authentication.
 */
final class AuthenticatedUser
{
    private ?int $id = null;

    public function set(int $id): void
    {
        $this->id = $id;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function isAuthenticated(): bool
    {
        return $this->id !== null;
    }

    /**
     * For controllers on routes marked `@access protected`, where reaching the
     * method at all means the filter has already run and succeeded. A failure
     * here is a wiring bug, not a client error.
     *
     * @throws RuntimeException when called on an unauthenticated request.
     */
    public function requireId(): int
    {
        if ($this->id === null) {
            throw new RuntimeException('No authenticated user on this request.');
        }

        return $this->id;
    }
}
