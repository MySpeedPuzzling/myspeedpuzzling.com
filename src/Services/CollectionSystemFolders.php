<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CollectionFolder;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Repository\CollectionFolderRepository;

readonly final class CollectionSystemFolders
{
    public const SYSTEM_FOLDER_SOLVED = 'solved';
    public const SYSTEM_FOLDER_LENDED_OUT = 'lended_out';
    public const SYSTEM_FOLDER_LENDED_FROM = 'lended_from';

    public function __construct(
        private CollectionFolderRepository $collectionFolderRepository,
    ) {
    }

    public function getSolvedFolder(Player $player): CollectionFolder
    {
        return $this->getOrCreateSystemFolder(
            $player,
            self::SYSTEM_FOLDER_SOLVED,
            'Solved Puzzles',
            '#28a745',
            'Puzzles you have successfully completed'
        );
    }

    public function getLendedOutFolder(Player $player): CollectionFolder
    {
        return $this->getOrCreateSystemFolder(
            $player,
            self::SYSTEM_FOLDER_LENDED_OUT,
            'Lended Out',
            '#dc3545',
            'Puzzles you have lent to other players'
        );
    }

    public function getLendedFromFolder(Player $player): CollectionFolder
    {
        return $this->getOrCreateSystemFolder(
            $player,
            self::SYSTEM_FOLDER_LENDED_FROM,
            'Borrowed',
            '#17a2b8',
            'Puzzles you have borrowed from other players'
        );
    }

    private function getOrCreateSystemFolder(
        Player $player,
        string $systemKey,
        string $name,
        string $color,
        string $description
    ): CollectionFolder {
        $folder = $this->collectionFolderRepository->findSystemFolder($player, $systemKey);

        if ($folder === null) {
            $folder = new CollectionFolder(
                Uuid::uuid7(),
                $player,
                $name,
                true, // isSystem
                $color,
                $description
            );

            // Set the system key as part of the name for identification
            $folder->systemKey = $systemKey;

            $this->collectionFolderRepository->save($folder);
        }

        return $folder;
    }

    public function isSystemFolder(CollectionFolder $folder): bool
    {
        return $folder->isSystem;
    }

    /**
     * @return array<string>
     */
    public function getSystemFolderKeys(): array
    {
        return [
            self::SYSTEM_FOLDER_SOLVED,
            self::SYSTEM_FOLDER_LENDED_OUT,
            self::SYSTEM_FOLDER_LENDED_FROM,
        ];
    }
}