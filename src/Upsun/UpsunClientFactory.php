<?php

namespace App\Upsun;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Upsun\UpsunClient;
use Upsun\UpsunConfig;

/**
 * Builds an authenticated UpsunClient.
 *
 * The static UPSUN_API_TOKEN was removed from the project. Instead we mint a
 * short-lived OAuth2 access token from the container-local credential service
 * (localhost:8200, exposed inside every Upsun container) and inject it directly
 * as a bearer token.
 *
 * Passing an empty apiToken disables the SDK's api_token OAuth exchange, so the
 * minted access token is used as-is: UpsunClient::getToken() returns the bearer
 * token when no OAuthProvider is configured (apiToken === '').
 */
final class UpsunClientFactory
{
    /**
     * Token minted for this request, cached so we mint exactly once.
     *
     * The container-local credential broker (localhost:8200) reliably serves
     * the FIRST token request in a PHP request but rejects a second one in the
     * same request (it surfaces as a `Syntax error for "…/oauth2/token"`
     * transport error). create() mints the SDK client's bearer token first;
     * callers forwarding the token to a task (AutoRcaSubscriber / controller)
     * must reuse that same token rather than mint again. Caching also avoids a
     * redundant round-trip.
     */
    private ?string $accessToken = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $tokenEndpoint = 'http://localhost:8200/oauth2/token',
        private readonly string $baseUrl = 'https://api.upsun.com',
        private readonly string $authUrl = 'https://auth.upsun.com',
        private readonly int $tokenTtl = 3600,
    ) {
    }

    public function create(): UpsunClient
    {
        $config = new UpsunConfig(
            base_url: $this->baseUrl,
            auth_url: $this->authUrl,
            // Empty token: skip the SDK's api_token OAuth exchange and use the
            // bearer token set below (a short-lived access token minted from the
            // container-local credential service).
            apiToken: '',
        );

        $client = new UpsunClient($config);
        $client->setBearerToken($this->mintAccessToken());

        return $client;
    }

    /**
     * Exchange the container's ambient credentials for a short-lived access
     * token via the local credential service (localhost:8200).
     *
     * Public so callers (e.g. the Auto-RCA subscriber) can mint a token to
     * forward to a task container, which has NO localhost:8200 broker of its own
     * and therefore cannot mint one itself.
     */
    public function mintAccessToken(?int $ttl = null): string
    {
        // Reuse the token minted earlier this request (e.g. by create()). The
        // broker rejects a second mint in the same request, so we must not call
        // it again. A custom $ttl bypasses the cache for callers that need it.
        if ($ttl === null && $this->accessToken !== null) {
            return $this->accessToken;
        }

        $response = $this->httpClient->request('POST', $this->tokenEndpoint, [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body'    => http_build_query([
                'grant_type'  => 'client_credentials',
                'x-token-ttl' => (string) ($ttl ?? $this->tokenTtl),
            ]),
        ]);

        // Do not let toArray() throw on a non-2xx: surface a clear error below.
        $data  = $response->toArray(false);
        $token = $data['access_token'] ?? null;

        if (!\is_string($token) || $token === '') {
            throw new \RuntimeException(sprintf(
                'Could not mint an Upsun access token from %s (HTTP %d).',
                $this->tokenEndpoint,
                $response->getStatusCode(),
            ));
        }

        if ($ttl === null) {
            $this->accessToken = $token;
        }

        return $token;
    }
}
