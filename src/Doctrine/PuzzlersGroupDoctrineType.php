<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\JsonType;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlersGroup;

final class PuzzlersGroupDoctrineType extends JsonType
{
    public const string NAME = 'puzzlers_group';

    /**
     * @throws InvalidType
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): null|PuzzlersGroup
    {
        if ($value === null) {
            return null;
        }

        /** @var array{team_id: null|string, puzzlers: non-empty-array<array{player_id: null|string, player_name: null|string}>} $jsonData */
        $jsonData = parent::convertToPHPValue($value, $platform);

        $puzzlers = [];

        // TODO: because of not supported properties, we should have different object than Puzzler for domain
        foreach ($jsonData['puzzlers'] as $puzzler) {
            $puzzlers[] = new Puzzler(
                playerId: $puzzler['player_id'],
                playerName: $puzzler['player_name'],
                playerCode: null, // Not supported in domain
                playerCountry: null,  // Not supported in domain
                isPrivate: false, // Not supported in domain
            );
        }

        return new PuzzlersGroup(
            teamId: $jsonData['team_id'],
            puzzlers: $puzzlers,
        );
    }

    /**
     * @param null|PuzzlersGroup $value
     * @throws InvalidType
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): null|string
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
