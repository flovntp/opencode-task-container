<?php

namespace App\Upsun;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Upsun\Api\ApiConfiguration;
use Upsun\Api\TaskApi;
use Upsun\Core\OAuthProvider;
use Upsun\UpsunConfig;

/**
 * Builds an AppUpsunClient and injects the TasksContainerTask high-level wrapper.
 *
 * Wire in services.yaml:
 *   App\Upsun\UpsunClientFactory:
 *       arguments:
 *           $apiToken: '%env(UPSUN_API_TOKEN)%'
 *
 * Consume via:
 *   $client = $factory->create();
 *   $client->tasksContainer->run($projectId, $environmentId, $taskId);
 */
final class UpsunClientFactory
{
    public function __construct(
        private readonly string $apiToken,
        private readonly string $baseUrl = 'https://api.upsun.com',
        private readonly string $authUrl = 'https://auth.upsun.com',
    ) {
    }

    public function create(): AppUpsunClient
    {
        $config = new UpsunConfig(
            base_url: $this->baseUrl,
            auth_url: $this->authUrl,
            apiToken: $this->apiToken,
        );

        $httpClient = Psr18ClientDiscovery::find();
        $requestFactory = Psr17FactoryDiscovery::findRequestFactory();

        $client = new AppUpsunClient($config, $httpClient);

        // Inject the TasksContainerTask that the SDK does not yet expose natively.
        $apiConfig = ApiConfiguration::getDefaultConfiguration()->setHost($this->baseUrl);
        $auth = new OAuthProvider(
            httpClient: $httpClient,
            requestFactory: $requestFactory,
            tokenEndpoint: $this->authUrl . '/oauth2/token',
            clientId: 'sdk-php-client-id',
            clientSecret: $this->apiToken,
        );

        $taskApi = new TaskApi($auth, $httpClient, $requestFactory, $apiConfig);

        $client->tasksContainer = new TasksContainerTask($client, $taskApi);

        return $client;
    }
}
