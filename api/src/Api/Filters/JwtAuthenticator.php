<?php

declare(strict_types=1);

namespace InventoryTracker\Api\Filters;

use InventoryTracker\Application\Auth\AuthenticatedUser;
use InventoryTracker\Application\Auth\TokenVerifier;
use Luracast\Restler\Contracts\AuthenticationInterface;
use Luracast\Restler\Contracts\UserIdentificationInterface;
use Luracast\Restler\ResponseHeaders;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Bearer-token authentication filter.
 *
 * Restler runs this before dispatching any route annotated `@access protected`.
 * Returning false produces a 401 carrying the WWW-Authenticate header below.
 */
final class JwtAuthenticator implements AuthenticationInterface
{
    public function __construct(
        private readonly TokenVerifier $tokenVerifier,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    public static function getWWWAuthenticateString(): string
    {
        return 'Bearer realm="inventory-tracker-api"';
    }

    public function _isAllowed(
        ServerRequestInterface $request,
        UserIdentificationInterface $userIdentifier,
        ResponseHeaders $responseHeaders,
    ): bool {
        $token = $this->extractBearerToken($request->getHeaderLine('Authorization'));

        if ($token === null) {
            return false;
        }

        try {
            $userId = $this->tokenVerifier->verify($token);
        } catch (Throwable $e) {
            // Every rejection reason collapses to a plain 401; detail goes to
            // the log, not the response.
            error_log('[auth] token rejected: ' . $e->getMessage());

            return false;
        }

        $this->authenticatedUser->set($userId);

        // Restler uses this to attribute rate limits and logs to a caller.
        $userIdentifier->setUniqueIdentifier((string) $userId);

        return true;
    }

    /**
     * Pull the credential out of an `Authorization: Bearer <token>` header.
     *
     * Scheme matched case-insensitively per RFC 7235.
     */
    private function extractBearerToken(string $headerValue): ?string
    {
        if ($headerValue === '') {
            return null;
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', trim($headerValue), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
