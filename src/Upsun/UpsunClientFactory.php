<?php

namespace App\Upsun;

use Upsun\UpsunClient;
use Upsun\UpsunConfig;

/**
 * Builds a UpsunClient from env-vars.
 *
 * Kept as a Symfony service factory so the API token (and base/auth URLs) are
 * injected from configuration in one place. The SDK discovers a PSR-18 HTTP
 * client on its own (symfony/http-client is installed), so we don't pass one.
 */
final class UpsunClientFactory
{
    public function __construct(
        private readonly string $apiToken,
        private readonly string $baseUrl = 'https://api.upsun.com',
        private readonly string $authUrl = 'https://auth.upsun.com',
    ) {
    }

    public function create(): UpsunClient
    {
        $config = new UpsunConfig(
            base_url: $this->baseUrl,
            auth_url: $this->authUrl,
            apiToken: $this->apiToken,
        );

        return new UpsunClient($config);
    }
}
