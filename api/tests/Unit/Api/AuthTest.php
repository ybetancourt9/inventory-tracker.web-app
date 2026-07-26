<?php

declare(strict_types=1);

namespace InventoryTracker\Tests\Unit\Api;

use InventoryTracker\Api\Auth;
use InventoryTracker\Application\Auth\AuthenticatedUser;
use InventoryTracker\Application\Auth\TokenIssuer;
use InventoryTracker\Application\Auth\TokenVerifier;
use InventoryTracker\Domain\Entity\User;
use InventoryTracker\Tests\Support\InMemoryUserRepository;
use Luracast\Restler\Exceptions\HttpException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Auth::class)]
final class AuthTest extends TestCase
{
    private const SECRET   = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const ISSUER   = 'inventory-tracker-api';
    private const PASSWORD = 'correct horse battery staple';

    private InMemoryUserRepository $users;

    private AuthenticatedUser $authenticatedUser;

    protected function setUp(): void
    {
        $this->users             = new InMemoryUserRepository();
        $this->authenticatedUser = new AuthenticatedUser();

        $this->users->save(User::register('yaumel', self::PASSWORD));
    }

    public function testRegisterCreatesAnAccountThatCanImmediatelyLogIn(): void
    {
        $created = $this->auth()->postRegister('NewPerson', 'a-sufficiently-long-password');

        self::assertSame('newperson', $created['username']);
        self::assertGreaterThan(0, $created['id']);

        // The account is only real if it authenticates.
        $login = $this->auth()->postLogin('newperson', 'a-sufficiently-long-password');
        self::assertSame($created['id'], $login['user']['id']);
    }

    public function testRegisterDoesNotReturnATokenOrAnyCredentialMaterial(): void
    {
        $created = $this->auth()->postRegister('newperson', 'a-sufficiently-long-password');

        self::assertSame(['id', 'username'], array_keys($created));
    }

    public function testRegisterRejectsADuplicateUsernameRegardlessOfCase(): void
    {
        $failure = $this->captureRegisterFailure('YAUMEL', 'a-sufficiently-long-password');

        self::assertSame(409, $failure['code']);
        self::assertSame('That username is already taken.', $failure['message']);
    }

    public function testRegisterRejectsAShortPassword(): void
    {
        $failure = $this->captureRegisterFailure('newperson', 'short');

        self::assertSame(400, $failure['code']);
        self::assertStringContainsString('at least 12 characters', $failure['message']);
    }

    public function testRegisterRejectsAnOversizedPasswordRatherThanHashingIt(): void
    {
        $failure = $this->captureRegisterFailure('newperson', str_repeat('a', 5000));

        self::assertSame(400, $failure['code']);
        self::assertSame('Password is too long.', $failure['message']);
    }

    public function testRegisterRejectsAnEmptyUsername(): void
    {
        $failure = $this->captureRegisterFailure('   ', 'a-sufficiently-long-password');

        self::assertSame(400, $failure['code']);
        self::assertStringContainsString('Username must not be empty', $failure['message']);
    }

    public function testRegisterRejectsAnOverLongUsername(): void
    {
        $failure = $this->captureRegisterFailure(str_repeat('a', 65), 'a-sufficiently-long-password');

        self::assertSame(400, $failure['code']);
        self::assertStringContainsString('must not exceed 64', $failure['message']);
    }

    public function testSuccessfulLoginReturnsAUsableToken(): void
    {
        $response = $this->auth()->postLogin('yaumel', self::PASSWORD);

        self::assertSame('Bearer', $response['tokenType']);
        self::assertSame(3600, $response['expiresIn']);
        self::assertSame('yaumel', $response['user']['username']);

        // The token is only meaningful if the verifier accepts it.
        $verifier = new TokenVerifier(self::SECRET, self::ISSUER);
        self::assertSame($response['user']['id'], $verifier->verify($response['token']));
    }

    public function testLoginIsCaseInsensitiveAndIgnoresSurroundingWhitespace(): void
    {
        $response = $this->auth()->postLogin('  YauMel  ', self::PASSWORD);

        self::assertSame('yaumel', $response['user']['username']);
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Invalid username or password.');

        $this->auth()->postLogin('yaumel', 'not-the-password');
    }

    public function testUnknownUsernameIsRejected(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Invalid username or password.');

        $this->auth()->postLogin('nobody', self::PASSWORD);
    }

    /**
     * The shared message is the whole point -- if these two ever diverge the
     * endpoint becomes a username oracle.
     */
    public function testUnknownUserAndWrongPasswordFailIdentically(): void
    {
        $wrongPassword = $this->captureFailure('yaumel', 'not-the-password');
        $unknownUser   = $this->captureFailure('nobody', self::PASSWORD);

        self::assertSame($wrongPassword, $unknownUser);
    }

    public function testMalformedUsernamesAreRejectedAsCredentialFailuresNotErrors(): void
    {
        $overLong = $this->captureFailure(str_repeat('a', 200), self::PASSWORD);

        self::assertSame('Invalid username or password.', $overLong['message']);
    }

    public function testMeReturnsTheAuthenticatedAccount(): void
    {
        $this->authenticatedUser->set(1);

        self::assertSame(['id' => 1, 'username' => 'yaumel'], $this->auth()->getMe());
    }

    /**
     * A valid token outliving the account it names is a 404, not a 401 -- the
     * caller authenticated successfully; the resource is simply gone.
     */
    public function testMeReturns404WhenTheAccountNoLongerExists(): void
    {
        $this->authenticatedUser->set(999);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Account no longer exists.');

        $this->auth()->getMe();
    }

    /**
     * @return array{code: int, message: string}
     */
    private function captureFailure(string $username, string $password): array
    {
        try {
            $this->auth()->postLogin($username, $password);
        } catch (HttpException $e) {
            return ['code' => $e->getCode(), 'message' => $e->getMessage()];
        }

        self::fail('Expected the login to fail.');
    }

    /**
     * @return array{code: int, message: string}
     */
    private function captureRegisterFailure(string $username, string $password): array
    {
        try {
            $this->auth()->postRegister($username, $password);
        } catch (HttpException $e) {
            return ['code' => $e->getCode(), 'message' => $e->getMessage()];
        }

        self::fail('Expected the registration to fail.');
    }

    private function auth(): Auth
    {
        return new Auth(
            $this->users,
            new TokenIssuer(self::SECRET, self::ISSUER, 3600),
            $this->authenticatedUser,
        );
    }
}
