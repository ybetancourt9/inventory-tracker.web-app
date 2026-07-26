<?php

declare(strict_types=1);

namespace InventoryTracker\Application\Auth;

use DateTimeImmutable;
use Firebase\JWT\JWT;
use InventoryTracker\Domain\Entity\User;
use RuntimeException;
use SensitiveParameter;

/**
 * Mints signed bearer tokens for authenticated users.
 */
final class TokenIssuer
{
    private const ALGORITHM = 'HS256';

    private const MIN_SECRET_LENGTH = 32;

    public function __construct(
        #[SensitiveParameter] private readonly string $secret,
        private readonly string $issuer,
        private readonly int $ttlSeconds,
    ) {
        if (strlen($this->secret) < self::MIN_SECRET_LENGTH) {
            throw new RuntimeException(
                sprintf('JWT_SECRET must be at least %d characters.', self::MIN_SECRET_LENGTH)
            );
        }

        if ($this->issuer === '') {
            throw new RuntimeException('JWT_ISSUER must not be empty.');
        }

        if ($this->ttlSeconds < 1) {
            throw new RuntimeException('JWT_TTL_SECONDS must be a positive integer.');
        }
    }

    public static function fromEnvironment(): self
    {
        return new self(
            (string) ($_ENV['JWT_SECRET'] ?? ''),
            (string) ($_ENV['JWT_ISSUER'] ?? 'inventory-tracker-api'),
            (int) ($_ENV['JWT_TTL_SECONDS'] ?? 3600),
        );
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    /**
     * Issue a token identifying the given user.
     *
     * A JWT is signed, not encrypted, so the payload carries an id and nothing
     * else.
     */
    public function issueFor(User $user, ?DateTimeImmutable $now = null): string
    {
        $id = $user->getId();

        if ($id === null) {
            throw new RuntimeException('Cannot issue a token for an unpersisted user.');
        }

        $now       = $now ?? new DateTimeImmutable();
        $issuedAt  = $now->getTimestamp();
        $expiresAt = $issuedAt + $this->ttlSeconds;

        $claims = [
            'iss' => $this->issuer,
            'sub' => (string) $id,
            'iat' => $issuedAt,
            'nbf' => $issuedAt,
            'exp' => $expiresAt,
            // Unique per token, so tokens can be revoked individually later.
            'jti' => bin2hex(random_bytes(16)),
        ];

        return JWT::encode($claims, $this->secret, self::ALGORITHM);
    }
}
