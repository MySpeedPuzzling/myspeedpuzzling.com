<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Sentry;

use DateTimeInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use UnitEnum;

final class GenericObjectSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(object $object): array
    {
        $data = [];
        $reflection = new \ReflectionClass($object);

        foreach ($reflection->getProperties() as $property) {
            if (!$property->isInitialized($object)) {
                continue;
            }

            $value = $property->getValue($object);
            $data[$property->getName()] = $this->formatValue($value);
        }

        return $data;
    }

    private function formatValue(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value) => $value,
            is_string($value) => $value,
            $value instanceof UuidInterface => $value->toString(),
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof UnitEnum => $value->name,
            $value instanceof UploadedFile => $value->getClientOriginalName(),
            is_array($value) => array_map(
                static fn(mixed $v): mixed => is_scalar($v) ? $v : (is_object($v) ? $v::class : '...'),
                $value,
            ),
            is_object($value) => $value::class,
            default => '...',
        };
    }
}
