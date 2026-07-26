<?php

declare(strict_types=1);

namespace InventoryTracker\Tests\Unit\Application\Auth;

use DateTimeImmutable;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use InventoryTracker\Application\Auth\TokenIssuer;
use InventoryTracker\Application\Auth\TokenVerifier;
use InventoryTracker\Domain\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use UnexpectedValueException;

#[CoversClass(TokenVerifier::class)]
final class TokenVerifierTest extends TestCase
{
    private const SECRET = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const ISSUER = 'inventory-tracker-api';

    public function testAcceptsATokenThisServiceIssuedAndReturnsTheUserId(): void
    {
        $token = $this->issuer()->issueFor($this->persistedUser(42));

        self::assertSame(42, $this->verifier()->verify($token));
    }

    public function testRejectsATokenSignedWithADifferentSecret(): void
    {
        $foreign = new TokenIssuer(str_repeat('z', 64), self::ISSUER, 3600);
        $token   = $foreign->issueFor($this->persistedUser(42));

        $this->expectException(SignatureInvalidException::class);

        $this->verifier()->verify($token);
    }

    public function testRejectsAnExpiredToken(): void
    {
        $issuedInThePast = new DateTimeImmutable('-2 hours');
        $token           = $this->issuer(ttlSeconds: 60)->issueFor($this->persistedUser(42), $issuedInThePast);

        $this->expectException(ExpiredException::class);

        $this->verifier()->verify($token);
    }

    /**
     * A token correctly signed with the same secret but minted by some other
     * system must not grant access here.
     */
    public function testRejectsATokenFromAnotherIssuer(): void
    {
        $token = (new TokenIssuer(self::SECRET, 'some-other-service', 3600))
            ->issueFor($this->persistedUser(42));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not issued by this service');

        $this->verifier()->verify($token);
    }

    /**
     * The classic JWT forgery: strip the signature and set `alg` to `none`.
     * Pinning the algorithm at the Key() call is what defeats it.
     */
    public function testRejectsAnUnsignedNoneAlgorithmToken(): void
    {
        $header  = $this->base64Url((string) json_encode(['typ' => 'JWT', 'alg' => 'none']));
        $payload = $this->base64Url((string) json_encode([
            'iss' => self::ISSUER,
            'sub' => '42',
            'exp' => time() + 3600,
        ]));

        $this->expectException(UnexpectedValueException::class);

        $this->verifier()->verify($header . '.' . $payload . '.');
    }

    /**
     * An HS256 token whose payload was edited must fail the signature check.
     */
    public function testRejectsATamperedSubject(): void
    {
        $token                 = $this->issuer()->issueFor($this->persistedUser(42));
        [$header, , $signature] = explode('.', $token);

        $forgedPayload = $this->base64Url((string) json_encode([
            'iss' => self::ISSUER,
            'sub' => '1',
            'exp' => time() + 3600,
        ]));

        $this->expectException(SignatureInvalidException::class);

        $this->verifier()->verify($header . '.' . $forgedPayload . '.' . $signature);
    }

    #[DataProvider('malformedTokens')]
    public function testRejectsMalformedTokens(string $token): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->verifier()->verify($token);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedTokens(): array
    {
        return [
            'empty'          => [''],
            'not a jwt'      => ['just-a-string'],
            'two segments'   => ['aaa.bbb'],
            'four segments'  => ['aaa.bbb.ccc.ddd'],
        ];
    }

    public function testRejectsANonNumericSubject(): void
    {
        $token = JWT::encode(
            ['iss' => self::ISSUER, 'sub' => 'not-an-id', 'exp' => time() + 3600],
            self::SECRET,
            'HS256'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a valid user identifier');

        $this->verifier()->verify($token);
    }

    public function testEmptySecretIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JWT_SECRET');

        new TokenVerifier('', self::ISSUER);
    }

    private function verifier(): TokenVerifier
    {
        return new TokenVerifier(self::SECRET, self::ISSUER);
    }

    private function issuer(int $ttlSeconds = 3600): TokenIssuer
    {
        return new TokenIssuer(self::SECRET, self::ISSUER, $ttlSeconds);
    }

    private function persistedUser(int $id): User
    {
        $user = User::register('yaumel', 'correct horse battery staple');

        (new ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
