<?php

declare(strict_types=1);

namespace InventoryTracker\Tests\Unit\Application\Auth;

use DateTimeImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use InventoryTracker\Application\Auth\TokenIssuer;
use InventoryTracker\Domain\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

#[CoversClass(TokenIssuer::class)]
final class TokenIssuerTest extends TestCase
{
    private const SECRET = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const ISSUER = 'inventory-tracker-api';

    public function testIssuedTokenVerifiesWithTheSigningSecret(): void
    {
        $token = $this->issuer()->issueFor($this->persistedUser(42));

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertSame('42', $claims->sub);
        self::assertSame(self::ISSUER, $claims->iss);
    }

    public function testTokenIsRejectedWhenSignedWithADifferentSecret(): void
    {
        $token = $this->issuer()->issueFor($this->persistedUser(42));

        $this->expectException(SignatureInvalidException::class);

        JWT::decode($token, new Key(str_repeat('z', 64), 'HS256'));
    }

    /**
     * Read the claims straight out of the payload rather than through
     * JWT::decode(), which would reject a deliberately fixed past timestamp as
     * expired. The arithmetic under test is independent of the current clock,
     * and the test should be too.
     */
    public function testExpiryIsDerivedFromTheConfiguredTtl(): void
    {
        $now   = new DateTimeImmutable('2026-01-01 12:00:00');
        $token = $this->issuer(ttlSeconds: 900)->issueFor($this->persistedUser(7), $now);

        $claims = $this->decodePayload($token);

        self::assertSame($now->getTimestamp(), $claims['iat']);
        self::assertSame($now->getTimestamp(), $claims['nbf']);
        self::assertSame($now->getTimestamp() + 900, $claims['exp']);
    }

    /**
     * A JWT is signed, not encrypted -- anyone holding it can read the payload.
     */
    public function testPayloadCarriesNoCredentialMaterial(): void
    {
        $user  = $this->persistedUser(42);
        $token = $this->issuer()->issueFor($user);

        [, $payload] = explode('.', $token);
        $decoded     = base64_decode(strtr($payload, '-_', '+/'), true);

        self::assertIsString($decoded);
        self::assertStringNotContainsString('argon2', $decoded);
        self::assertStringNotContainsString('password', $decoded);
        self::assertStringNotContainsString($user->getUsername(), $decoded);
    }

    public function testEachTokenGetsAUniqueIdentifier(): void
    {
        $issuer = $this->issuer();
        $user   = $this->persistedUser(42);

        $first  = JWT::decode($issuer->issueFor($user), new Key(self::SECRET, 'HS256'));
        $second = JWT::decode($issuer->issueFor($user), new Key(self::SECRET, 'HS256'));

        self::assertNotSame($first->jti, $second->jti);
    }

    public function testUnpersistedUserCannotBeIssuedAToken(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unpersisted');

        $this->issuer()->issueFor(User::register('yaumel', 'pw'));
    }

    #[DataProvider('weakSecrets')]
    public function testShortOrEmptySecretsAreRefused(string $secret): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JWT_SECRET');

        new TokenIssuer($secret, self::ISSUER, 3600);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function weakSecrets(): array
    {
        return [
            'empty'      => [''],
            'one char'   => ['x'],
            'just short' => [str_repeat('a', 31)],
        ];
    }

    public function testNonPositiveTtlIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JWT_TTL_SECONDS');

        new TokenIssuer(self::SECRET, self::ISSUER, 0);
    }

    public function testEmptyIssuerIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JWT_ISSUER');

        new TokenIssuer(self::SECRET, '', 3600);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $token): array
    {
        [, $payload] = explode('.', $token);

        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        self::assertIsString($json);

        $claims = json_decode($json, true);
        self::assertIsArray($claims);

        /** @var array<string, mixed> $claims */
        return $claims;
    }

    private function issuer(int $ttlSeconds = 3600): TokenIssuer
    {
        return new TokenIssuer(self::SECRET, self::ISSUER, $ttlSeconds);
    }

    /**
     * Doctrine assigns the identifier on flush; emulate that without a database.
     */
    private function persistedUser(int $id): User
    {
        $user = User::register('yaumel', 'correct horse battery staple');

        (new ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
