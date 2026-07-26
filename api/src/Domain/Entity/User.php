<?php

declare(strict_types=1);

namespace InventoryTracker\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * An account that can authenticate against the API.
 *
 * No code path accepts a plaintext password, so an instance can only ever hold
 * a hash.
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'users',
    options: [
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'engine'    => 'InnoDB',
    ],
)]
#[ORM\UniqueConstraint(name: 'uniq_users_username', columns: ['username'])]
#[ORM\HasLifecycleCallbacks]
class User
{
    /** Argon2id rather than PASSWORD_DEFAULT, which is still bcrypt in PHP 8.4. */
    private const PASSWORD_ALGO = PASSWORD_ARGON2ID;

    private const USERNAME_MAX_LENGTH = 64;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: self::USERNAME_MAX_LENGTH)]
    private string $username;

    #[ORM\Column(name: 'password_hash', type: Types::STRING, length: 255)]
    private string $passwordHash;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    private function __construct(string $username, #[SensitiveParameter] string $plainPassword)
    {
        if (!in_array(self::PASSWORD_ALGO, password_algos(), true)) {
            throw new \RuntimeException(
                'Argon2id is not available in this PHP build; refusing to hash with a weaker algorithm.'
            );
        }

        $now = new DateTimeImmutable();

        $this->username     = self::normaliseUsername($username);
        $this->passwordHash = self::hash($plainPassword);
        $this->createdAt    = $now;
        $this->updatedAt    = $now;
    }

    public static function register(string $username, #[SensitiveParameter] string $plainPassword): self
    {
        return new self($username, $plainPassword);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Constant-time comparison against the stored hash. */
    public function verifyPassword(#[SensitiveParameter] string $plainPassword): bool
    {
        return password_verify($plainPassword, $this->passwordHash);
    }

    /**
     * True when the stored hash uses weaker parameters than the current ones.
     * Only actionable right after a successful login, while the plaintext is
     * still available to re-hash with.
     */
    public function passwordNeedsRehash(): bool
    {
        return password_needs_rehash($this->passwordHash, self::PASSWORD_ALGO);
    }

    public function changePassword(#[SensitiveParameter] string $plainPassword): void
    {
        $this->passwordHash = self::hash($plainPassword);
        $this->updatedAt    = new DateTimeImmutable();
    }

    public function changeUsername(string $username): void
    {
        $this->username  = self::normaliseUsername($username);
        $this->updatedAt = new DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    private static function hash(#[SensitiveParameter] string $plainPassword): string
    {
        if ($plainPassword === '') {
            throw new InvalidArgumentException('Password must not be empty.');
        }

        return password_hash($plainPassword, self::PASSWORD_ALGO);
    }

    /**
     * Usernames are stored lower-cased and trimmed.
     *
     * @throws InvalidArgumentException when the username is empty or too long.
     */
    public static function normaliseUsername(string $username): string
    {
        $normalised = self::normaliseUsernameForLookup($username);

        if ($normalised === '') {
            throw new InvalidArgumentException('Username must not be empty.');
        }

        if (mb_strlen($normalised) > self::USERNAME_MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Username must not exceed %d characters.', self::USERNAME_MAX_LENGTH)
            );
        }

        return $normalised;
    }

    /**
     * Normalise without validating, for looking an account up.
     *
     * Login uses this so malformed input misses the index rather than being
     * rejected early, which would return faster and leak validity. Shares the
     * lowercasing rule with {@see self::normaliseUsername()} so the two cannot
     * drift apart.
     */
    public static function normaliseUsernameForLookup(string $username): string
    {
        return mb_strtolower(trim($username));
    }
}
