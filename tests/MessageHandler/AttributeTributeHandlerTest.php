<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\TributeNotFound;
use SpeedPuzzling\Web\Message\AttributeTribute;
use SpeedPuzzling\Web\Repository\TributeRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\AffiliateFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\TributeSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class AttributeTributeHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private TributeRepository $tributeRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->tributeRepository = $container->get(TributeRepository::class);
    }

    public function testSessionCodeTakesPriorityOverCookieCode(): void
    {
        // PLAYER_ADMIN doesn't have a tribute yet
        $this->messageBus->dispatch(new AttributeTribute(
            subscriberPlayerId: PlayerFixture::PLAYER_ADMIN,
            sessionTributeCode: AffiliateFixture::AFFILIATE_ACTIVE_CODE,
            cookieTributeCode: 'IGNORED',
        ));

        $tribute = $this->tributeRepository->getBySubscriberId(PlayerFixture::PLAYER_ADMIN);
        self::assertSame(TributeSource::Code, $tribute->source);
        self::assertSame(AffiliateFixture::AFFILIATE_ACTIVE_ID, $tribute->affiliate->id->toString());
    }

    public function testCookieCodeUsedWhenNoSessionCode(): void
    {
        $this->messageBus->dispatch(new AttributeTribute(
            subscriberPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            sessionTributeCode: null,
            cookieTributeCode: AffiliateFixture::AFFILIATE_ACTIVE_CODE,
        ));

        $tribute = $this->tributeRepository->getBySubscriberId(PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertSame(TributeSource::Link, $tribute->source);
    }

    public function testNoTributeCreatedWhenBothCodesAreNull(): void
    {
        $this->messageBus->dispatch(new AttributeTribute(
            subscriberPlayerId: PlayerFixture::PLAYER_WITH_FAVORITES,
            sessionTributeCode: null,
            cookieTributeCode: null,
        ));

        $this->expectException(TributeNotFound::class);
        $this->tributeRepository->getBySubscriberId(PlayerFixture::PLAYER_WITH_FAVORITES);
    }

    public function testNoTributeCreatedWhenAffiliateCodeIsInvalid(): void
    {
        $this->messageBus->dispatch(new AttributeTribute(
            subscriberPlayerId: PlayerFixture::PLAYER_WITH_FAVORITES,
            sessionTributeCode: 'INVALID_CODE_XYZ',
        ));

        $this->expectException(TributeNotFound::class);
        $this->tributeRepository->getBySubscriberId(PlayerFixture::PLAYER_WITH_FAVORITES);
    }

    public function testNoTributeCreatedWhenAffiliateIsPending(): void
    {
        $this->messageBus->dispatch(new AttributeTribute(
            subscriberPlayerId: PlayerFixture::PLAYER_WITH_FAVORITES,
            sessionTributeCode: AffiliateFixture::AFFILIATE_PENDING_CODE,
        ));

        $this->expectException(TributeNotFound::class);
        $this->tributeRepository->getBySubscriberId(PlayerFixture::PLAYER_WITH_FAVORITES);
    }

    public function testNoTributeCreatedWhenAffiliateIsSuspended(): void
    {
        $this->messageBus->dispatch(new AttributeTribute(
            subscriberPlayerId: PlayerFixture::PLAYER_WITH_FAVORITES,
            sessionTributeCode: AffiliateFixture::AFFILIATE_SUSPENDED_CODE,
        ));

        $this->expectException(TributeNotFound::class);
        $this->tributeRepository->getBySubscriberId(PlayerFixture::PLAYER_WITH_FAVORITES);
    }

    public function testNoDuplicateTributeCreated(): void
    {
        // PLAYER_PRIVATE already has a tribute (from fixtures)
        $existingTribute = $this->tributeRepository->getBySubscriberId(PlayerFixture::PLAYER_PRIVATE);

        $this->messageBus->dispatch(new AttributeTribute(
            subscriberPlayerId: PlayerFixture::PLAYER_PRIVATE,
            sessionTributeCode: AffiliateFixture::AFFILIATE_ACTIVE_CODE,
        ));

        $tribute = $this->tributeRepository->getBySubscriberId(PlayerFixture::PLAYER_PRIVATE);
        self::assertSame($existingTribute->id->toString(), $tribute->id->toString());
    }

    public function testSelfReferralIsBlocked(): void
    {
        // PLAYER_REGULAR is the active affiliate owner — they can't refer themselves
        $this->messageBus->dispatch(new AttributeTribute(
            subscriberPlayerId: PlayerFixture::PLAYER_REGULAR,
            sessionTributeCode: AffiliateFixture::AFFILIATE_ACTIVE_CODE,
        ));

        $this->expectException(TributeNotFound::class);
        $this->tributeRepository->getBySubscriberId(PlayerFixture::PLAYER_REGULAR);
    }

    public function testCodeIsCaseInsensitive(): void
    {
        $this->messageBus->dispatch(new AttributeTribute(
            subscriberPlayerId: PlayerFixture::PLAYER_ADMIN,
            sessionTributeCode: strtolower(AffiliateFixture::AFFILIATE_ACTIVE_CODE),
        ));

        $tribute = $this->tributeRepository->getBySubscriberId(PlayerFixture::PLAYER_ADMIN);
        self::assertSame(AffiliateFixture::AFFILIATE_ACTIVE_ID, $tribute->affiliate->id->toString());
    }
}
