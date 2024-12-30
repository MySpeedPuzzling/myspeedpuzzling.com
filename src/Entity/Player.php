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
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $favoritePlayers = [];

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $country = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $city = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $avatar = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $facebook = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $instagram = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $bio = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::BOOLEAN, options: ['default' => '0'])]
    public bool $wjpcModalDisplayed = false;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $stripeCustomerId = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Immutable]
        #[Column(unique: true)]
        public string $code,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(unique: true, nullable: true)]
        public null|string $userId,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $email,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $name,

        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public \DateTimeImmutable $registeredAt,
    ) {
    }

    public function changeProfile(
        null|string $name,
        null|string $email,
        null|string $city,
        null|string $country,
        null|string $avatar,
        null|string $bio,
        null|string $facebook,
        null|string $instagram,
    ): void
    {
        $this->name = $name;
        $this->email = $email;
        $this->city = $city;
        $this->country = $country;
        $this->avatar = $avatar;
        $this->bio = $bio;
        $this->facebook = $facebook;
        $this->instagram = $instagram;
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

        $this->favoritePlayers = array_values($this->favoritePlayers);
    }

    public function markWjpcModalAsDisplayed(): void
    {
        $this->wjpcModalDisplayed = true;
    }

    public function updateStripeCustomerId(string $stripeCustomerId): void
    {
        $this->stripeCustomerId = $stripeCustomerId;
    }
}
