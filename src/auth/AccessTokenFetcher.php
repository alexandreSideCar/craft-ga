<?php

namespace sidecar\craftga\auth;

use Closure;
use Google\Auth\FetchAuthTokenInterface;

/**
 * Hands the GA4 Data API client an access token resolved by the plugin.
 *
 * The Google client normally refreshes its own access token from the refresh
 * token on every PHP process. Routing the lookup through a callback lets the
 * service share one cached access token between the GA4 client and the Search
 * Console client, so a cold dashboard load costs one token refresh per hour
 * instead of two per request.
 *
 * @author Side-Car
 * @since 2.3.0
 */
class AccessTokenFetcher implements FetchAuthTokenInterface
{
    // Private Properties
    // =========================================================================

    /**
     * @var Closure(): array{access_token: string, expires_at: int} Resolves the current access token.
     */
    private Closure $_resolver;

    /**
     * @var array|null The token returned by the last {@see fetchAuthToken()} call.
     */
    private ?array $_lastToken = null;

    // Public Methods
    // =========================================================================

    /**
     * @param Closure(): array{access_token: string, expires_at: int} $resolver Returns the access token to use.
     *
     * @author Side-Car
     * @since 2.3.0
     */
    public function __construct(Closure $resolver)
    {
        $this->_resolver = $resolver;
    }

    /**
     * Resolves the access token. The GAX credentials wrapper calls this whenever
     * the last token it saw is missing or about to expire.
     *
     * @param callable|null $httpHandler Unused; the resolver performs its own HTTP calls.
     * @return array{access_token: string, expires_at: int}
     * @throws \Throwable Whatever the resolver throws when it cannot obtain a token.
     *
     * @author Side-Car
     * @since 2.3.0
     */
    public function fetchAuthToken(?callable $httpHandler = null): array
    {
        $this->_lastToken = ($this->_resolver)();

        return $this->_lastToken;
    }

    /**
     * No cache key: caching happens in the resolver, not in Google's auth layer.
     *
     * @author Side-Car
     * @since 2.3.0
     */
    public function getCacheKey(): ?string
    {
        return null;
    }

    /**
     * @inheritdoc
     *
     * @author Side-Car
     * @since 2.3.0
     */
    public function getLastReceivedToken(): ?array
    {
        return $this->_lastToken;
    }
}
