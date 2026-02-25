<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Sentry;

use DateTimeInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Envelope;
use UnitEnum;

final class GenericObjectSerializer
{
    private const int MAX_DEPTH = 3;

    private const array SENSITIVE_PROPERTIES = [
        'password',
    ];

    /**
     * @return array<string, mixed>
     */
    public function __invoke(object $object): array
    {
        return $this->serializeObject($object, 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeObject(object $object, int $depth): array
    {
        $data = [];
        $reflection = new \ReflectionClass($object);

        foreach ($reflection->getProperties() as $property) {
            if (!$property->isInitialized($object)) {
                continue;
            }

            if (in_array($property->getName(), self::SENSITIVE_PROPERTIES, true)) {
                $data[$property->getName()] = '[REDACTED]';
                continue;
            }

            $value = $property->getValue($object);
            $data[$property->getName()] = $this->formatValue($value, $depth);
        }

        return $data;
    }

    private function formatValue(mixed $value, int $depth): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value) => $value,
            is_string($value) => $value,
            $value instanceof UuidInterface => $value->toString(),
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof UnitEnum => $value->name,
            $value instanceof UploadedFile => $value->getClientOriginalName(),
            $value instanceof Envelope => $this->formatEnvelope($value, $depth),
            is_array($value) => $this->formatArray($value, $depth),
            is_object($value) && $depth < self::MAX_DEPTH => $this->serializeObject($value, $depth + 1),
            is_object($value) => $this->formatObjectSummary($value),
            default => '...',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function formatEnvelope(Envelope $envelope, int $depth): array
    {
        return [
            'message' => $this->formatValue($envelope->getMessage(), $depth),
        ];
    }

    private function formatObjectSummary(object $object): string
    {
        $className = $object::class;

        try {
            $reflection = new \ReflectionClass($object);
            if ($reflection->hasProperty('id')) {
                $property = $reflection->getProperty('id');
                if ($property->isInitialized($object)) {
                    $id = $property->getValue($object);
                    if ($id instanceof UuidInterface) {
                        return $className . '(' . $id->toString() . ')';
                    }
                }
            }
        } catch (\ReflectionException) {
            // Fallback to class name only
        }

        return $className;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function formatArray(array $value, int $depth): array
    {
        return array_map(
            fn(mixed $v): mixed => $this->formatValue($v, $depth),
            $value,
        );
    }
}
