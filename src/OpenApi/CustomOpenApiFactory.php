<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model;
use ApiPlatform\OpenApi\OpenApi;
use OpenApi\Attributes as OA;
use OpenApi\Generator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

#[OA\Info(title: 'Doshka Backend API', version: '1.0.0')]
#[AsDecorator(decorates: 'lexik_jwt_authentication.api_platform.openapi.factory')]
class CustomOpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(
        #[AutowireDecorated] private readonly OpenApiFactoryInterface $inner,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(array $context = []): OpenApi
    {
        try {
            $openApi = ($this->inner)($context);
        } catch (\Throwable $e) {
            $this->logger->error('CustomOpenApiFactory: inner factory failed', ['exception' => $e->getMessage()]);
            throw $e;
        }

        try {
            $scanned = Generator::scan([
                $this->projectDir . '/src/OpenApi',
                $this->projectDir . '/src/Controller',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('CustomOpenApiFactory: Generator::scan failed', ['exception' => $e->getMessage()]);
            return $openApi;
        }

        if ($scanned === null) {
            $this->logger->warning('CustomOpenApiFactory: Generator::scan returned null');
            return $openApi;
        }

        $data = json_decode($scanned->toJson(), true);
        if (empty($data['paths'])) {
            $this->logger->warning('CustomOpenApiFactory: scanned paths are empty');
            return $openApi;
        }

        $this->logger->info('CustomOpenApiFactory: merging ' . count($data['paths']) . ' paths from swagger-php');

        $paths = $openApi->getPaths();

        foreach ($data['paths'] as $path => $methods) {
            $pathItem = new Model\PathItem();

            foreach (['get', 'post', 'patch', 'put', 'delete'] as $method) {
                if (!isset($methods[$method])) {
                    continue;
                }

                $op = $methods[$method];
                $operation = $this->buildOperation($op);
                $setter = 'with' . ucfirst($method);
                $pathItem = $pathItem->{$setter}($operation);
            }

            $paths->addPath($path, $pathItem);
        }

        return $openApi->withPaths($paths);
    }

    private function buildOperation(array $op): Model\Operation
    {
        $parameters = [];
        foreach ($op['parameters'] ?? [] as $param) {
            $parameters[] = new Model\Parameter(
                name: $param['name'],
                in: $param['in'],
                description: $param['description'] ?? '',
                required: $param['required'] ?? ($param['in'] === 'path'),
                schema: $param['schema'] ?? ['type' => 'string'],
            );
        }

        $requestBody = null;
        if (isset($op['requestBody']['content']['application/json']['schema'])) {
            $schema = $op['requestBody']['content']['application/json']['schema'];
            $requestBody = new Model\RequestBody(
                description: $op['requestBody']['description'] ?? '',
                content: new \ArrayObject([
                    'application/json' => new Model\MediaType(
                        schema: new \ArrayObject($schema),
                    ),
                ]),
                required: $op['requestBody']['required'] ?? false,
            );
        }

        $responses = [];
        foreach ($op['responses'] ?? [] as $code => $response) {
            $responses[(string) $code] = new Model\Response(
                description: $response['description'] ?? '',
            );
        }

        return new Model\Operation(
            operationId: $op['operationId'] ?? null,
            tags: $op['tags'] ?? [],
            responses: $responses,
            summary: $op['summary'] ?? '',
            security: $op['security'] ?? null,
            parameters: $parameters,
            requestBody: $requestBody,
        );
    }
}
