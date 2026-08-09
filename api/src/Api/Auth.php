<?php

declare(strict_types=1);

namespace InventoryTracker\Api;

use InvalidArgumentException;
use InventoryTracker\Application\Auth\AuthenticatedUser;
use InventoryTracker\Application\Auth\TokenIssuer;
use InventoryTracker\Application\RateLimit\RateLimiter;
use InventoryTracker\Domain\Entity\User;
use InventoryTracker\Domain\Exception\UsernameAlreadyTaken;
use InventoryTracker\Domain\Repository\UserRepositoryInterface;
use InventoryTracker\Infrastructure\Doctrine\EntityManagerProvider;
use InventoryTracker\Infrastructure\Doctrine\Repository\DoctrineUserRepository;
use InventoryTracker\Infrastructure\RateLimit\ApcuRateLimiter;
use InventoryTracker\Infrastructure\RateLimit\NullRateLimiter;
use Luracast\Restler\Exceptions\HttpException;
use SensitiveParameter;

/**
 * Authentication endpoints.
 */
final class Auth
{
    /** Unknown user and wrong password must be indistinguishable. */
    private const INVALID_CREDENTIALS = 'Invalid username or password.';

    private const PASSWORD_MIN_LENGTH = 12;

    private const PASSWORD_MAX_BYTES = 4096;

    /** Argon2id hash of 32 random bytes; see {@see self::equaliseTiming()}. */
    private const DUMMY_HASH =
        '$argon2id$v=19$m=65536,t=4,p=1$UjZMVE1IVzA5TFNkVWEzag$DAx7zduJF4TEEQJ3Uv/Ov5ePEfeZqsNfJmzhY5WtM2Q';

    private const RATE_WINDOW_SECONDS = 900;

    /**
     * Two keys per login: the address stops one host working through a list of
     * accounts, the username stops a distributed attempt on a single account.
     */
    private const LOGIN_ATTEMPTS_PER_ADDRESS = 20;

    private const LOGIN_ATTEMPTS_PER_USERNAME = 10;

    private const REGISTRATIONS_PER_ADDRESS = 5;

    private const REGISTRATION_WINDOW_SECONDS = 3600;

    private const TOO_MANY_ATTEMPTS = 'Too many attempts. Please try again later.';

    private UserRepositoryInterface $users;

    private TokenIssuer $tokenIssuer;

    private AuthenticatedUser $authenticatedUser;

    private RateLimiter $rateLimiter;

    public function __construct(
        ?UserRepositoryInterface $users = null,
        ?TokenIssuer $tokenIssuer = null,
        ?AuthenticatedUser $authenticatedUser = null,
        ?RateLimiter $rateLimiter = null,
    ) {
        // Restler resolves these from the container (wired in public/index.php);
        // the optional parameters exist so tests can inject doubles.
        $this->users             = $users ?? new DoctrineUserRepository(EntityManagerProvider::get());
        $this->tokenIssuer       = $tokenIssuer ?? TokenIssuer::fromEnvironment();
        $this->authenticatedUser = $authenticatedUser ?? new AuthenticatedUser();
        $this->rateLimiter       = $rateLimiter ?? (ApcuRateLimiter::isSupported()
            ? new ApcuRateLimiter()
            : new NullRateLimiter());
    }

    /**
     * Create an account.
     *
     * Returns the created account rather than a token. Registration and
     * authentication stay separate concerns, so tokens are minted in exactly
     * one place; the client follows up with POST /auth/login.
     *
     * @param string $username {@from body}
     * @param string $password {@from body}
     *
     * @status 201
     *
     * @return array{id: int, username: string}
     *
     * @throws HttpException 400 when the username or password is unacceptable,
     *                       409 when the username is already taken,
     *                       429 when the caller has registered too often.
     */
    public function postRegister(
        string $username,
        #[SensitiveParameter] string $password,
    ): array {
        $this->assertWithinLimit(
            'register:' . $this->clientAddress(),
            self::REGISTRATIONS_PER_ADDRESS,
            self::REGISTRATION_WINDOW_SECONDS,
        );

        $this->assertPasswordIsAcceptable($password);

        try {
            // Validates and normalises the username, and hashes the password.
            $user = User::register($username, $password);
        } catch (InvalidArgumentException $e) {
            throw new HttpException(400, $e->getMessage());
        }

        try {
            $this->users->save($user);
        } catch (UsernameAlreadyTaken) {
            throw new HttpException(409, 'That username is already taken.');
        }

        return [
            'id'       => (int) $user->getId(),
            'username' => $user->getUsername(),
        ];
    }

    /**
     * Exchange a username and password for a bearer token.
     *
     * {@from body} keeps credentials out of the query string, and so out of
     * access logs and browser history.
     *
     * @param string $username {@from body}
     * @param string $password {@from body}
     *
     * @return array{tokenType: string, token: string, expiresIn: int, user: array{id: int, username: string}}
     *
     * @throws HttpException 401 when the credentials do not match,
     *                       429 when the caller has tried too often.
     */
    public function postLogin(
        string $username,
        #[SensitiveParameter] string $password,
    ): array {
        $this->assertWithinLimit(
            'login:addr:' . $this->clientAddress(),
            self::LOGIN_ATTEMPTS_PER_ADDRESS,
            self::RATE_WINDOW_SECONDS,
        );

        // Lowercased so the limit cannot be sidestepped by varying the case,
        // which the lookup ignores anyway.
        $this->assertWithinLimit(
            'login:user:' . mb_strtolower(trim($username)),
            self::LOGIN_ATTEMPTS_PER_USERNAME,
            self::RATE_WINDOW_SECONDS,
        );

        $user = $this->findUser($username);

        if (!$user instanceof User || !$user->verifyPassword($password)) {
            throw new HttpException(401, self::INVALID_CREDENTIALS);
        }

        $this->upgradeHashIfStale($user, $password);

        $id = $user->getId();

        return [
            'tokenType' => 'Bearer',
            'token'     => $this->tokenIssuer->issueFor($user),
            'expiresIn' => $this->tokenIssuer->ttlSeconds(),
            'user'      => [
                'id'       => (int) $id,
                'username' => $user->getUsername(),
            ],
        ];
    }

    /**
     * Return the account belonging to the bearer token on this request.
     *
     * Doubles as the endpoint an Angular client calls on start-up to decide
     * whether a stored token is still good.
     *
     * `@access protected` is what makes Restler run JwtAuthenticator before
     * this method; without that annotation the route would be public no matter
     * what the filter does.
     *
     * @access protected
     *
     * @return array{id: int, username: string}
     *
     * @throws HttpException 404 if the account was deleted after the token was
     *                       issued but before it expired.
     */
    public function getMe(): array
    {
        $user = $this->users->findById($this->authenticatedUser->requireId());

        if (!$user instanceof User) {
            // The signature was valid, so this is a real token for an account
            // that no longer exists -- not an authentication failure.
            throw new HttpException(404, 'Account no longer exists.');
        }

        return [
            'id'       => (int) $user->getId(),
            'username' => $user->getUsername(),
        ];
    }

    /**
     * Look the user up, spending the same time on every failure path.
     *
     * Deliberately no early return for malformed input: bailing out before the
     * query would make an invalid username measurably faster than a wrong one.
     */
    private function findUser(string $username): ?User
    {
        $user = $this->users->findByUsername($username);

        if (!$user instanceof User) {
            $this->equaliseTiming();

            return null;
        }

        return $user;
    }

    /**
     * Transparently re-hash on successful login when the cost parameters have
     * been raised since the password was set.
     *
     * A successful login is the only moment the plaintext is available, so it
     * is the only opportunity to upgrade the stored hash without forcing a
     * password reset on every account.
     */
    private function upgradeHashIfStale(User $user, #[SensitiveParameter] string $password): void
    {
        if (!$user->passwordNeedsRehash()) {
            return;
        }

        $user->changePassword($password);
        $this->users->save($user);
    }

    /**
     * Enforce password strength at the API boundary rather than in the entity.
     *
     * @throws HttpException 400
     */
    private function assertPasswordIsAcceptable(#[SensitiveParameter] string $password): void
    {
        if (mb_strlen($password) < self::PASSWORD_MIN_LENGTH) {
            throw new HttpException(
                400,
                sprintf('Password must be at least %d characters.', self::PASSWORD_MIN_LENGTH)
            );
        }

        // Byte length, not character count: the cap exists to stop a caller
        // making the server run argon2id over a multi-megabyte payload.
        if (strlen($password) > self::PASSWORD_MAX_BYTES) {
            throw new HttpException(400, 'Password is too long.');
        }
    }

    /**
     * Burn the same argon2id work a real verification would, so a missing
     * account does not return faster than a wrong password.
     */
    private function equaliseTiming(): void
    {
        password_verify('timing-equalisation', self::DUMMY_HASH);
    }

    /**
     * Record the attempt and refuse it once the caller is over the limit.
     *
     * @throws HttpException 429
     */
    private function assertWithinLimit(string $key, int $limit, int $windowSeconds): void
    {
        $decision = $this->rateLimiter->hit($key, $limit, $windowSeconds);

        if ($decision->allowed) {
            return;
        }

        $exception = new HttpException(429, self::TOO_MANY_ATTEMPTS);
        $exception->setHeader('Retry-After', (string) $decision->retryAfterSeconds);

        throw $exception;
    }

    /**
     * The peer address nginx saw. Behind a CDN or load balancer this becomes
     * that hop's address, so the forwarded header has to be trusted explicitly
     * before it can be used here.
     */
    private function clientAddress(): string
    {
        $address = $_SERVER['REMOTE_ADDR'] ?? '';

        return is_string($address) && $address !== '' ? $address : 'unknown';
    }
}
