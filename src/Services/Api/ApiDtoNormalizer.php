<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Api;

use ApiPlatform\Metadata\ResourceClassResolverInterface;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Snake_case JSON for the nested API DTOs (src/Api/V1 classes that are not resources
 * themselves - statistics groups, cards inside a list, rating entries, ...).
 *
 * The wire format of the public API is snake_case while the PHP side is camelCase.
 * API Platform applies the configured name converter (api_platform.name_converter)
 * to the resource it serializes, but every object *nested* in it is handed to the
 * framework serializer, whose ObjectNormalizer knows no converter - and configuring
 * one there would rename the JSON of everything else in the application. So the
 * nested DTOs are normalized here: public properties in declaration order, names
 * through the same converter, values through the serializer again (lists, deeper
 * DTOs), nulls kept (the API's null-means-not-entitled contract).
 */
final class ApiDtoNormalizer implements NormalizerInterface, NormalizerAwareInterface, ResetInterface
{
    use NormalizerAwareTrait;

    private const string NAMESPACE_PREFIX = 'SpeedPuzzling\\Web\\Api\\';

    /** @var array<class-string, list<ReflectionProperty>> */
    private array $properties = [];

    public function __construct(
        private readonly NameConverterInterface $nameConverter,
        private readonly ResourceClassResolverInterface $resourceClassResolver,
    ) {
    }

    public function reset(): void
    {
        $this->properties = [];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, null|string $format = null, array $context = []): array
    {
        assert(is_object($data));

        $normalized = [];

        foreach ($this->publicProperties($data::class) as $property) {
            $value = $property->getValue($data);

            if (is_object($value) || is_array($value)) {
                $value = $this->normalizer->normalize($value, $format, $context);
            }

            $normalized[$this->nameConverter->normalize($property->getName(), $data::class, $format, $context)] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, null|string $format = null, array $context = []): bool
    {
        return is_object($data)
            && str_starts_with($data::class, self::NAMESPACE_PREFIX)
            && $this->resourceClassResolver->isResourceClass($data::class) === false;
    }

    /**
     * @return array<class-string|'*'|'object'|string, bool|null>
     */
    public function getSupportedTypes(null|string $format): array
    {
        return ['object' => false];
    }

    /**
     * @param class-string $class
     *
     * @return list<ReflectionProperty>
     */
    private function publicProperties(string $class): array
    {
        return $this->properties[$class] ??= array_values(array_filter(
            (new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC),
            static fn (ReflectionProperty $property): bool => $property->isStatic() === false,
        ));
    }
}
