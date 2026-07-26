<?php

declare(strict_types=1);

namespace InventoryTracker\Tests\Unit\Domain\Entity;

use InvalidArgumentException;
use InventoryTracker\Domain\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
final class UserTest extends TestCase
{
    public function testRegisterStoresAnArgon2idHashRatherThanThePlaintext(): void
    {
        $user = User::register('yaumel', 'correct horse battery staple');

        $hash = $this->readPasswordHash($user);

        self::assertStringStartsWith('$argon2id$', $hash, 'Expected the configured memory-hard algorithm.');
        self::assertStringNotContainsString('correct horse battery staple', $hash);
    }

    public function testVerifyPasswordAcceptsTheCorrectPasswordAndRejectsOthers(): void
    {
        $user = User::register('yaumel', 's3cret-password');

        self::assertTrue($user->verifyPassword('s3cret-password'));
        self::assertFalse($user->verifyPassword('s3cret-passwore'));
        self::assertFalse($user->verifyPassword(''));
    }

    public function testHashIsSaltedSoIdenticalPasswordsProduceDifferentHashes(): void
    {
        $a = $this->readPasswordHash(User::register('alice', 'same-password'));
        $b = $this->readPasswordHash(User::register('bob', 'same-password'));

        self::assertNotSame($a, $b, 'Identical passwords must not share a hash; that is what the salt prevents.');
    }

    public function testFreshlyRegisteredPasswordDoesNotNeedRehashing(): void
    {
        $user = User::register('yaumel', 's3cret-password');

        self::assertFalse($user->passwordNeedsRehash());
    }

    public function testChangePasswordInvalidatesTheOldCredential(): void
    {
        $user = User::register('yaumel', 'old-password');
        $user->changePassword('new-password');

        self::assertFalse($user->verifyPassword('old-password'));
        self::assertTrue($user->verifyPassword('new-password'));
    }

    /**
     * The absence of a getter is a deliberate security control, not an
     * oversight -- this test fails loudly if someone "helpfully" adds one.
     */
    public function testPasswordHashIsNotReachableThroughSerialisation(): void
    {
        $user = User::register('yaumel', 's3cret-password');

        $encoded = json_encode($user);
        self::assertIsString($encoded);

        self::assertSame('{}', $encoded, 'No entity state should be exposed by default serialisation.');
        self::assertStringNotContainsString('argon2', $encoded);
    }

    #[DataProvider('usernameNormalisationCases')]
    public function testUsernamesAreTrimmedAndLowercased(string $input, string $expected): void
    {
        self::assertSame($expected, User::register($input, 'pw')->getUsername());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function usernameNormalisationCases(): array
    {
        return [
            'already normalised' => ['yaumel', 'yaumel'],
            'mixed case'         => ['Yaumel', 'yaumel'],
            'shouting'           => ['YAUMEL', 'yaumel'],
            'surrounding space'  => ['  yaumel  ', 'yaumel'],
            'both'               => ["\tYauMel \n", 'yaumel'],
        ];
    }

    public function testEmptyUsernameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Username must not be empty.');

        User::register('   ', 'pw');
    }

    public function testOverlongUsernameIsRejectedRatherThanTruncatedByTheDatabase(): void
    {
        $this->expectException(InvalidArgumentException::class);

        User::register(str_repeat('a', 65), 'pw');
    }

    public function testEmptyPasswordIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Password must not be empty.');

        User::register('yaumel', '');
    }

    public function testIdIsNullUntilTheEntityIsPersisted(): void
    {
        self::assertNull(User::register('yaumel', 'pw')->getId());
    }

    public function testTimestampsAreSetOnRegistration(): void
    {
        $user = User::register('yaumel', 'pw');

        self::assertSame(
            $user->getCreatedAt()->getTimestamp(),
            $user->getUpdatedAt()->getTimestamp()
        );
    }

    /**
     * The hash has no accessor by design, so reach it via reflection -- the
     * same mechanism Doctrine uses to hydrate it.
     */
    private function readPasswordHash(User $user): string
    {
        $property = new \ReflectionProperty(User::class, 'passwordHash');

        $value = $property->getValue($user);
        self::assertIsString($value);

        return $value;
    }
}
