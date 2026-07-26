<?php

declare(strict_types=1);

namespace InventoryTracker\Tests\Support;

use InventoryTracker\Domain\Entity\User;
use InventoryTracker\Domain\Exception\UsernameAlreadyTaken;
use InventoryTracker\Domain\Repository\UserRepositoryInterface;
use ReflectionProperty;

/**
 * In-memory adapter used by unit tests.
 */
final class InMemoryUserRepository implements UserRepositoryInterface
{
    /** @var array<int, User> */
    private array $users = [];

    private int $nextId = 1;

    public function findByUsername(string $username): ?User
    {
        $normalised = User::normaliseUsernameForLookup($username);

        foreach ($this->users as $user) {
            if ($user->getUsername() === $normalised) {
                return $user;
            }
        }

        return null;
    }

    public function findById(int $id): ?User
    {
        return $this->users[$id] ?? null;
    }

    public function save(User $user): void
    {
        $id = $user->getId();

        // Stands in for the UNIQUE index.
        $existing = $this->findByUsername($user->getUsername());

        if ($existing instanceof User && $existing !== $user) {
            throw new UsernameAlreadyTaken($user->getUsername());
        }

        if ($id === null) {
            $id = $this->nextId++;
            // Stands in for the identifier Doctrine assigns on flush.
            (new ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        }

        $this->users[$id] = $user;
    }
}
