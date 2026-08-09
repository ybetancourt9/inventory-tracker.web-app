<?php

declare(strict_types=1);

namespace InventoryTracker\Tests\Unit\Api;

use InventoryTracker\Api\Auth;
use InventoryTracker\Application\Auth\AuthenticatedUser;
use InventoryTracker\Application\Auth\TokenIssuer;
use InventoryTracker\Application\Auth\TokenVerifier;
use InventoryTracker\Domain\Entity\User;
use InventoryTracker\Tests\Support\InMemoryRateLimiter;
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

    private InMemoryRateLimiter $rateLimiter;

    protected function setUp(): void
    {
        $this->users             = new InMemoryUserRepository();
        $this->authenticatedUser = new AuthenticatedUser();
        $this->rateLimiter       = new InMemoryRateLimiter();

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

    public function testRepeatedGuessesAgainstOneAccountAreEventuallyRefused(): void
    {
        // The limit is per username, so a correct password is irrelevant here:
        // what is being capped is how often the account may be tried at all.
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $failure = $this->captureFailure('yaumel', 'wrong-password');
            self::assertSame(401, $failure['code'], "attempt $attempt should still be answered");
        }

        $blocked = $this->captureFailure('yaumel', 'wrong-password');

        self::assertSame(429, $blocked['code']);
        self::assertSame('Too many attempts. Please try again later.', $blocked['message']);
    }

    public function testTheBlockedResponseTellsTheCallerWhenToRetry(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->captureFailure('yaumel', 'wrong-password');
        }

        try {
            $this->auth()->postLogin('yaumel', 'wrong-password');
        } catch (HttpException $e) {
            self::assertArrayHasKey('Retry-After', $e->getHeaders());
            self::assertGreaterThan(0, (int) $e->getHeaders()['Retry-After']);

            return;
        }

        self::fail('Expected the login to be refused.');
    }

    public function testSpreadingGuessesAcrossAccountsStillHitsTheAddressLimit(): void
    {
        // Twenty attempts, each against a different account, so the per-username
        // counter never fills. Only the address counter catches this.
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $failure = $this->captureFailure('account' . $attempt, 'wrong-password');
            self::assertSame(401, $failure['code'], "attempt $attempt should still be answered");
        }

        self::assertSame(429, $this->captureFailure('yet-another', 'wrong-password')['code']);
    }

    public function testRegistrationIsCappedPerAddress(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $created = $this->auth()->postRegister('person' . $attempt, 'a-sufficiently-long-password');
            self::assertSame('person' . $attempt, $created['username']);
        }

        $blocked = $this->captureRegisterFailure('person6', 'a-sufficiently-long-password');

        self::assertSame(429, $blocked['code']);
    }

    public function testTheLimitIsCountedBeforeTheAccountIsCreated(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->auth()->postRegister('person' . $attempt, 'a-sufficiently-long-password');
        }

        $this->captureRegisterFailure('person6', 'a-sufficiently-long-password');

        // A refused registration must not leave a usable account behind.
        self::assertNull($this->users->findByUsername('person6'));
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
            $this->rateLimiter,
        );
    }
}
