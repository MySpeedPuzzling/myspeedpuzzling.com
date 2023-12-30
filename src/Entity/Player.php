<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Exceptions\CanNotFavoriteYourself;
use SpeedPuzzling\Web\Exceptions\PlayerIsAlreadyInFavorites;
use SpeedPuzzling\Web\Exceptions\PlayerIsNotInFavorites;

#[Entity]
class Player
{
    /**
     * @var array<string>
     */
    #[Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $favoritePlayers = [];

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Immutable]
        #[Column(unique: true)]
        public string $code,

        #[Column(unique: true, nullable: true)]
        public null|string $userId,

        #[Column(nullable: true)]
        public null|string $email,

        #[Column(nullable: true)]
        public null|string $name,

        #[Column(nullable: true)]
        public null|string $country,

        #[Column(nullable: true)]
        public null|string $city,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public \DateTimeImmutable $registeredAt,
    ) {
    }

    public function changeProfile(
        null|string $name,
        null|string $email,
        null|string $city,
        null|string $country,
    ): void
    {
        $this->name = $name;
        $this->email = $email;
        $this->city = $city;
        $this->country = $country;
    }

    /**
     * @throws CanNotFavoriteYourself
     * @throws PlayerIsAlreadyInFavorites
     */
    public function addFavoritePlayer(Player $favoritePlayer): void
    {
        if ($favoritePlayer->id->equals($this->id)) {
            throw new CanNotFavoriteYourself();
        }

        if (in_array($favoritePlayer->id->toString(), $this->favoritePlayers, true)) {
            throw new PlayerIsAlreadyInFavorites();
        }

        $this->favoritePlayers[] = $favoritePlayer->id->toString();
    }

    /**
     * @throws PlayerIsNotInFavorites
     */
    public function removeFavoritePlayer(Player $favoritePlayer): void
    {
        $key = array_search($favoritePlayer->id->toString(), $this->favoritePlayers, true);

        if ($key === false) {
            throw new PlayerIsNotInFavorites();
        }

        unset($this->favoritePlayers[$key]);
    }
}
