<?php

declare(strict_types=1);

namespace InventoryTracker\Application\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use SensitiveParameter;
use stdClass;

/**
 * Validates bearer tokens and returns the user they identify.
 */
final class TokenVerifier
{
    /** Passed to Key() so the token's own `alg` header is never trusted. */
    private const ALGORITHM = 'HS256';

    public function __construct(
        #[SensitiveParameter] private readonly string $secret,
        private readonly string $issuer,
    ) {
        if ($this->secret === '') {
            throw new RuntimeException('JWT_SECRET must be configured to verify tokens.');
        }
    }

    public static function fromEnvironment(): self
    {
        return new self(
            (string) ($_ENV['JWT_SECRET'] ?? ''),
            (string) ($_ENV['JWT_ISSUER'] ?? 'inventory-tracker-api'),
        );
    }

    /**
     * Return the user id carried by a valid token.
     *
     * JWT::decode() enforces the signature and the exp/nbf windows; the issuer
     * and subject checks below are not covered by it.
     *
     * @throws RuntimeException when the token is not acceptable to this service.
     */
    public function verify(#[SensitiveParameter] string $token): int
    {
        /** @var stdClass $claims */
        $claims = JWT::decode($token, new Key($this->secret, self::ALGORITHM));

        $issuer = $claims->iss ?? null;

        if (!is_string($issuer) || !hash_equals($this->issuer, $issuer)) {
            throw new RuntimeException('Token was not issued by this service.');
        }

        $subject = $claims->sub ?? null;

        // RFC 7519 makes `sub` a string; ours always holds an integer id.
        if (!is_string($subject) || !ctype_digit($subject)) {
            throw new RuntimeException('Token subject is not a valid user identifier.');
        }

        return (int) $subject;
    }
}
