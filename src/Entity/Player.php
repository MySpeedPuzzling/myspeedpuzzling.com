<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Exceptions\CanNotFavoriteYourself;
use SpeedPuzzling\Web\Exceptions\PlayerIsAlreadyInFavorites;
use SpeedPuzzling\Web\Exceptions\PlayerIsNotInFavorites;
use SpeedPuzzling\Web\Doctrine\SellSwapListSettingsDoctrineType;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use SpeedPuzzling\Web\Value\SellSwapListSettings;

#[Entity]
class Player
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[ManyToOne]
    #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public null|Voucher $claimedDiscountVoucher = null;
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
    public bool $modalDisplayed = false;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::BOOLEAN, options: ['default' => '0'])]
    public bool $isAdmin = false;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::BOOLEAN, options: ['default' => '0'])]
    public bool $isPrivate = false;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $stripeCustomerId = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $locale = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, enumType: CollectionVisibility::class, options: ['default' => 'private'])]
    public CollectionVisibility $puzzleCollectionVisibility = CollectionVisibility::Private;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, enumType: CollectionVisibility::class, options: ['default' => 'private'])]
    public CollectionVisibility $unsolvedPuzzlesVisibility = CollectionVisibility::Private;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, enumType: CollectionVisibility::class, options: ['default' => 'private'])]
    public CollectionVisibility $wishListVisibility = CollectionVisibility::Private;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: SellSwapListSettingsDoctrineType::NAME, nullable: true)]
    public null|SellSwapListSettings $sellSwapListSettings = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, enumType: CollectionVisibility::class, options: ['default' => 'private'])]
    public CollectionVisibility $lendBorrowListVisibility = CollectionVisibility::Private;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, enumType: CollectionVisibility::class, options: ['default' => 'private'])]
    public CollectionVisibility $solvedPuzzlesVisibility = CollectionVisibility::Private;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::BOOLEAN, options: ['default' => true])]
    public bool $allowDirectMessages = true;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::INTEGER, options: ['default' => 0])]
    public int $ratingCount = 0;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DECIMAL, precision: 3, scale: 2, nullable: true)]
    public null|string $averageRating = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
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
    ): void {
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

    public function markModalAsDisplayed(): void
    {
        $this->modalDisplayed = true;
    }

    public function updateStripeCustomerId(string $stripeCustomerId): void
    {
        $this->stripeCustomerId = $stripeCustomerId;
    }

    public function changeLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function changeCode(string $code): void
    {
        $this->code = strtolower($code);
    }

    public function changeProfileVisibility(bool $isPrivate): void
    {
        $this->isPrivate = $isPrivate;
    }

    public function changePuzzleCollectionVisibility(CollectionVisibility $visibility): void
    {
        $this->puzzleCollectionVisibility = $visibility;
    }

    public function changeUnsolvedPuzzlesVisibility(CollectionVisibility $visibility): void
    {
        $this->unsolvedPuzzlesVisibility = $visibility;
    }

    public function changeWishListVisibility(CollectionVisibility $visibility): void
    {
        $this->wishListVisibility = $visibility;
    }

    public function changeSellSwapListSettings(SellSwapListSettings $settings): void
    {
        $this->sellSwapListSettings = $settings;
    }

    public function changeLendBorrowListVisibility(CollectionVisibility $visibility): void
    {
        $this->lendBorrowListVisibility = $visibility;
    }

    public function changeSolvedPuzzlesVisibility(CollectionVisibility $visibility): void
    {
        $this->solvedPuzzlesVisibility = $visibility;
    }

    public function claimDiscountVoucher(Voucher $voucher): void
    {
        $this->claimedDiscountVoucher = $voucher;
    }

    public function clearClaimedDiscountVoucher(): void
    {
        $this->claimedDiscountVoucher = null;
    }

    public function changeAllowDirectMessages(bool $allow): void
    {
        $this->allowDirectMessages = $allow;
    }

    public function updateRatingStats(int $count, null|string $average): void
    {
        $this->ratingCount = $count;
        $this->averageRating = $average;
    }
}
