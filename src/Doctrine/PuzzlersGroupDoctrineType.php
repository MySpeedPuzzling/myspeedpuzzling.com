<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\JsonType;
use SpeedPuzzling\Web\Value\Lap;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlersGroup;

final class PuzzlersGroupDoctrineType extends JsonType
{
    public const NAME = 'puzzlers_group';

    public function getName(): string
    {
        return self::NAME;
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }

    public function canRequireSQLConversion(): bool
    {
        return true;
    }

    /**
     * @throws ConversionException
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): null|PuzzlersGroup
    {
        if ($value === null) {
            return null;
        }

        /** @var array{team_id: null|string, puzzlers: non-empty-array<array{player_id: null|string, player_name: null|string}>} $jsonData */
        $jsonData = parent::convertToPHPValue($value, $platform);

        $puzzlers = [];

        foreach ($jsonData['puzzlers'] as $puzzler) {
            $puzzlers[] = new Puzzler(
                playerId: $puzzler['player_id'],
                playerName: $puzzler['player_name'],
                playerCode: null, // Not supported in domain
            );
        }

        return new PuzzlersGroup(
            teamId: $jsonData['team_id'],
            puzzlers: $puzzlers,
        );
    }

    /**
     * @param null|PuzzlersGroup $value
     * @throws ConversionException
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        $data = [
            'team_id' => $value->teamId,
            'puzzlers' => [],
        ];

        foreach ($value->puzzlers as $puzzler) {
            $data['puzzlers'][] = [
                'player_id' => $puzzler->playerId,
                'player_name' => $puzzler->playerName,
            ];
        }

        return parent::convertToDatabaseValue($data, $platform);
    }
}
