<?php

declare(strict_types=1);

namespace InventoryTracker\Tests\Integration\Infrastructure;

use InventoryTracker\Domain\Entity\User;
use InventoryTracker\Domain\Exception\UsernameAlreadyTaken;
use InventoryTracker\Infrastructure\Doctrine\Repository\DoctrineUserRepository;
use InventoryTracker\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DoctrineUserRepository::class)]
final class DoctrineUserRepositoryTest extends IntegrationTestCase
{
    private DoctrineUserRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new DoctrineUserRepository($this->entityManager);
    }

    public function testAnAccountSurvivesARoundTripThroughTheDatabase(): void
    {
        $username = $this->username();
        $this->repository->save(User::register($username, 'a-sufficiently-long-password'));

        $this->entityManager->clear();

        $loaded = $this->repository->findByUsername($username);

        self::assertInstanceOf(User::class, $loaded);
        self::assertTrue($loaded->verifyPassword('a-sufficiently-long-password'));
        self::assertFalse($loaded->verifyPassword('wrong'));
    }

    /**
     * Argon2id hashes are about 97 characters. This fails loudly if the column
     * is ever narrowed to the point of truncating them.
     */
    public function testThePasswordHashIsStoredWithoutTruncation(): void
    {
        $username = $this->username();
        $this->repository->save(User::register($username, 'a-sufficiently-long-password'));

        $stored = (string) $this->entityManager->getConnection()
            ->executeQuery('SELECT password_hash FROM users WHERE username = ?', [$username])
            ->fetchOne();

        self::assertStringStartsWith('$argon2id$', $stored);
        self::assertGreaterThan(90, strlen($stored));
    }

    public function testLookupIsCaseInsensitive(): void
    {
        $username = $this->username();
        $this->repository->save(User::register($username, 'a-sufficiently-long-password'));

        $this->entityManager->clear();

        self::assertInstanceOf(User::class, $this->repository->findByUsername(strtoupper($username)));
        self::assertInstanceOf(User::class, $this->repository->findByUsername("  $username  "));
    }

    public function testDuplicateUsernameRaisesTheDomainException(): void
    {
        $username = $this->username();
        $this->repository->save(User::register($username, 'a-sufficiently-long-password'));

        $this->expectException(UsernameAlreadyTaken::class);

        $this->repository->save(User::register(strtoupper($username), 'another-long-password'));
    }

    public function testUnknownUsernameReturnsNull(): void
    {
        self::assertNull($this->repository->findByUsername($this->username()));
    }

    private function username(): string
    {
        return strtolower($this->prefix) . 'user';
    }
}
