<?php

namespace App\Github;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Mints short-lived GitHub App installation access tokens.
 *
 * The long-lived secret (the App private key) lives only in the application
 * runtime. For each incident we exchange it for an installation token that:
 *   - expires automatically (~1h),
 *   - is scoped to a single repository,
 *   - only carries `contents:write` + `pull_requests:write`, plus
 *     `checks:read` + `actions:read` so the agent can watch the PR's CI.
 *
 * That token is the only GitHub credential ever handed to the task container,
 * which keeps the blast radius of a leak small and time-bounded.
 */
final class GitHubAppTokenMinter
{
    private const API_BASE = 'https://api.github.com';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $appId,
        private readonly string $installationId,
        private readonly string $privateKey,
        private readonly string $repository,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->appId !== ''
            && $this->installationId !== ''
            && $this->privateKey !== '';
    }

    /**
     * @return array{token: string, repository: string, expires_at: ?string}|null
     */
    public function mintInstallationToken(): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                sprintf('%s/app/installations/%s/access_tokens', self::API_BASE, $this->installationId),
                [
                    'headers' => [
                        'Authorization'        => 'Bearer '.$this->buildJwt(),
                        'Accept'               => 'application/vnd.github+json',
                        'X-GitHub-Api-Version' => '2022-11-28',
                    ],
                    'json' => [
                        'repositories' => [$this->shortRepositoryName()],
                        'permissions'  => [
                            'contents'      => 'write',
                            'pull_requests' => 'write',
                            // Read CI status so OpenCode can watch the PR checks
                            // and fix failing GitHub Actions workflows.
                            'checks'        => 'read',
                            'actions'       => 'read',
                        ],
                    ],
                ],
            );

            $data = $response->toArray(false);

            if (!isset($data['token'])) {
                $this->logger->error('GitHubApp: installation token request rejected.', [
                    'status' => $response->getStatusCode(),
                    'body'   => $data,
                ]);

                return null;
            }

            return [
                'token'      => (string) $data['token'],
                'repository' => $this->repository,
                'expires_at' => isset($data['expires_at']) ? (string) $data['expires_at'] : null,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('GitHubApp: failed to mint installation token.', [
                'exception' => $e::class,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function shortRepositoryName(): string
    {
        $parts = explode('/', $this->repository);

        return end($parts) ?: $this->repository;
    }

    private function buildJwt(): string
    {
        $now = time();

        $segments = [
            $this->base64UrlEncode(json_encode(
                ['alg' => 'RS256', 'typ' => 'JWT'],
                \JSON_THROW_ON_ERROR,
            )),
            $this->base64UrlEncode(json_encode(
                [
                    'iat' => $now - 60,  // tolerate minor clock drift
                    'exp' => $now + 540, // GitHub caps App JWT lifetime at 10 min
                    'iss' => $this->appId,
                ],
                \JSON_THROW_ON_ERROR,
            )),
        ];

        $signingInput = implode('.', $segments);

        // The private key is stored base64-encoded to survive the CLI/env layer.
        // Fall back to the raw value in case it was provided as a plain PEM.
        $pem = base64_decode($this->privateKey, true) ?: $this->privateKey;
        $key = openssl_pkey_get_private($pem);

        if ($key === false) {
            throw new \RuntimeException('Invalid GitHub App private key.');
        }

        $signature = '';
        if (!openssl_sign($signingInput, $signature, $key, \OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Unable to sign the GitHub App JWT.');
        }

        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
