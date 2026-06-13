<?php

namespace App\Upsun;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
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
    private const int MAX_RETRIES = 3;
    private const int RETRY_DELAY_MS = 200;

    /**
     * Token minted for this request, cached so we mint exactly once.
     *
     * The container-local credential broker (localhost:8200) reliably serves
     * the FIRST token request in a PHP request but rejects a second one in the
     * same request (it surfaces as a `Syntax error for "…/oauth2/token"`
     * transport error). Caching the token avoids that and a redundant round-trip.
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
     * token via the local credential service (localhost:8200). Used by create()
     * to authenticate the SDK client that spawns the RCA task container.
     */
    public function mintAccessToken(?int $ttl = null): string
    {
        // Reuse the token minted earlier this request (e.g. by create()). The
        // broker rejects a second mint in the same request, so we must not call
        // it again. A custom $ttl bypasses the cache for callers that need it.
        if ($ttl === null && $this->accessToken !== null) {
            return $this->accessToken;
        }

        $maxRetries = $ttl === null ? self::MAX_RETRIES : 0;

        for ($attempt = 0; $attempt <= $maxRetries; ++$attempt) {
            try {
                $response = $this->httpClient->request('POST', $this->tokenEndpoint, [
                    'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                    'body'    => http_build_query([
                        'grant_type'  => 'client_credentials',
                        'x-token-ttl' => (string) ($ttl ?? $this->tokenTtl),
                    ]),
                ]);

                $statusCode = $response->getStatusCode();
                $content    = $response->getContent(false);

                // The credential broker may return HTTP 429 (rate-limited) with
                // an empty body. Retry after a short delay.
                if ($statusCode === 429 && $attempt < $maxRetries) {
                    usleep(self::RETRY_DELAY_MS * 1000 * ($attempt + 1));
                    continue;
                }

                $data = json_decode($content, true);

                if (!\is_array($data)) {
                    throw new \RuntimeException(sprintf(
                        'Could not mint an Upsun access token from %s (HTTP %d): invalid JSON response.',
                        $this->tokenEndpoint,
                        $statusCode,
                    ));
                }

                $token = $data['access_token'] ?? null;

                if (!\is_string($token) || $token === '') {
                    throw new \RuntimeException(sprintf(
                        'Could not mint an Upsun access token from %s (HTTP %d).',
                        $this->tokenEndpoint,
                        $statusCode,
                    ));
                }

                if ($ttl === null) {
                    $this->accessToken = $token;
                }

                return $token;
            } catch (TransportExceptionInterface $e) {
                if ($attempt >= $maxRetries) {
                    throw new \RuntimeException(sprintf(
                        'Could not reach the credential service at %s after %d attempts: %s',
                        $this->tokenEndpoint,
                        $maxRetries + 1,
                        $e->getMessage(),
                    ));
                }

                usleep(self::RETRY_DELAY_MS * 1000 * ($attempt + 1));
            }
        }

        throw new \RuntimeException(sprintf(
            'Could not mint an Upsun access token from %s after %d attempts.',
            $this->tokenEndpoint,
            $maxRetries + 1,
        ));
    }
}
