<?php

declare(strict_types=1);

namespace InventoryTracker\Domain\Repository;

use InventoryTracker\Domain\Entity\User;
use InventoryTracker\Domain\Exception\UsernameAlreadyTaken;

/**
 * Persistence port for user accounts.
 *
 * No existsByUsername(): callers attempt the write and handle
 * {@see UsernameAlreadyTaken}, since a pre-check races concurrent writers.
 */
interface UserRepositoryInterface
{
    /** Implementations normalise the argument before looking it up. */
    public function findByUsername(string $username): ?User;

    public function findById(int $id): ?User;

    /**
     * Persist a new or modified account.
     *
     * @throws UsernameAlreadyTaken
     */
    public function save(User $user): void;
}
