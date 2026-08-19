<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The OpenAPI document is generated from the attributes: asserting the
 * parameter declarations are in it proves they are declared once and
 * documented (docs/features/api/v1-expansion-plan.md, N4).
 */
trait OpenApiAssertions
{
    /**
     * @return array<string, mixed>
     */
    protected function openApiDocument(KernelBrowser $browser): array
    {
        $browser->request('GET', '/api/docs.jsonopenapi');
        self::assertResponseIsSuccessful();

        $content = $browser->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array<string, mixed> $document */
        $document = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $document;
    }

    /**
     * The GET operation of the path documents exactly the expected query
     * parameters, each with a description; returns them keyed by name for
     * further assertions (enum, schema).
     *
     * @param list<string> $expectedNames
     *
     * @return array<string, array{name: string, in: string, description?: string, schema: array<string, mixed>, style: string, explode: bool}>
     */
    protected function assertOpenApiHasParameters(KernelBrowser $browser, string $path, array $expectedNames): array
    {
        $document = $this->openApiDocument($browser);

        self::assertIsArray($document['paths'] ?? null);
        self::assertArrayHasKey($path, $document['paths'], sprintf('OpenAPI does not document %s', $path));

        /** @var array{get?: array{parameters?: list<array{name: string, in: string, description?: string, schema: array<string, mixed>, style: string, explode: bool}>}} $pathItem */
        $pathItem = $document['paths'][$path];
        self::assertArrayHasKey('get', $pathItem);

        $parameters = [];

        foreach ($pathItem['get']['parameters'] ?? [] as $parameter) {
            if ($parameter['in'] === 'query') {
                $parameters[$parameter['name']] = $parameter;
            }
        }

        self::assertSame($expectedNames, array_keys($parameters), sprintf('Query parameters of %s differ from the expected list.', $path));

        foreach ($parameters as $name => $parameter) {
            self::assertNotSame('', trim($parameter['description'] ?? ''), sprintf('Parameter %s has no description.', $name));
        }

        return $parameters;
    }
}
