<?php

declare(strict_types=1);

namespace InventoryTracker\Infrastructure\Doctrine\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use InventoryTracker\Domain\Entity\User;
use InventoryTracker\Domain\Exception\UsernameAlreadyTaken;
use InventoryTracker\Domain\Repository\UserRepositoryInterface;

/**
 * Doctrine adapter for {@see UserRepositoryInterface}.
 */
final class DoctrineUserRepository implements UserRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function findByUsername(string $username): ?User
    {
        return $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(['username' => User::normaliseUsernameForLookup($username)]);
    }

    public function findById(int $id): ?User
    {
        return $this->entityManager->getRepository(User::class)->find($id);
    }

    public function save(User $user): void
    {
        // No-op when the entity is already managed, so this covers both insert
        // and update.
        $this->entityManager->persist($user);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            throw new UsernameAlreadyTaken($user->getUsername(), $e);
        }
    }
}
