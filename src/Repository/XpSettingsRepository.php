<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use SpeedPuzzling\Web\Entity\XpSettings;

readonly class XpSettingsRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function get(): XpSettings
    {
        $settings = $this->entityManager->find(XpSettings::class, XpSettings::ID);

        if ($settings === null) {
            throw new LogicException('xp_settings has no row - the migration that creates the table inserts it.');
        }

        return $settings;
    }
}
