<?php

namespace App\Upsun;

use Http\Discovery\Psr18ClientDiscovery;
use Upsun\UpsunClient;
use Upsun\UpsunConfig;

/**
 * Builds a UpsunClient, wiring env-vars and PSR-18 HTTP client discovery.
 * Kept as a Symfony service factory because Psr18ClientDiscovery::find()
 * is a static call that cannot be expressed in services.yaml.
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

        $httpClient = Psr18ClientDiscovery::find();

        return new UpsunClient($config, $httpClient);
    }
}
