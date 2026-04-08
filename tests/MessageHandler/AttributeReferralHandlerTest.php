<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\ReferralNotFound;
use SpeedPuzzling\Web\Message\AttributeReferral;
use SpeedPuzzling\Web\Repository\ReferralRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\AffiliateFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\ReferralSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class AttributeReferralHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private ReferralRepository $referralRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->referralRepository = $container->get(ReferralRepository::class);
    }

    public function testSessionCodeTakesPriorityOverCookieCode(): void
    {
        $this->messageBus->dispatch(new AttributeReferral(
            subscriberPlayerId: PlayerFixture::PLAYER_ADMIN,
            sessionReferralCode: AffiliateFixture::AFFILIATE_ACTIVE_CODE,
            cookieReferralCode: 'IGNORED',
        ));

        $referral = $this->referralRepository->getBySubscriberId(PlayerFixture::PLAYER_ADMIN);
        self::assertSame(ReferralSource::Code, $referral->source);
        self::assertSame(AffiliateFixture::AFFILIATE_ACTIVE_ID, $referral->affiliate->id->toString());
    }

    public function testCookieCodeUsedWhenNoSessionCode(): void
    {
        $this->messageBus->dispatch(new AttributeReferral(
            subscriberPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            sessionReferralCode: null,
            cookieReferralCode: AffiliateFixture::AFFILIATE_ACTIVE_CODE,
        ));

        $referral = $this->referralRepository->getBySubscriberId(PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertSame(ReferralSource::Link, $referral->source);
    }

    public function testNoReferralCreatedWhenBothCodesAreNull(): void
    {
        $this->messageBus->dispatch(new AttributeReferral(
            subscriberPlayerId: PlayerFixture::PLAYER_WITH_FAVORITES,
            sessionReferralCode: null,
            cookieReferralCode: null,
        ));

        $this->expectException(ReferralNotFound::class);
        $this->referralRepository->getBySubscriberId(PlayerFixture::PLAYER_WITH_FAVORITES);
    }

    public function testNoReferralCreatedWhenAffiliateCodeIsInvalid(): void
    {
        $this->messageBus->dispatch(new AttributeReferral(
            subscriberPlayerId: PlayerFixture::PLAYER_WITH_FAVORITES,
            sessionReferralCode: 'INVALID_CODE_XYZ',
        ));

        $this->expectException(ReferralNotFound::class);
        $this->referralRepository->getBySubscriberId(PlayerFixture::PLAYER_WITH_FAVORITES);
    }

    public function testNoReferralCreatedWhenAffiliateIsPending(): void
    {
        $this->messageBus->dispatch(new AttributeReferral(
            subscriberPlayerId: PlayerFixture::PLAYER_WITH_FAVORITES,
            sessionReferralCode: AffiliateFixture::AFFILIATE_PENDING_CODE,
        ));

        $this->expectException(ReferralNotFound::class);
        $this->referralRepository->getBySubscriberId(PlayerFixture::PLAYER_WITH_FAVORITES);
    }

    public function testNoReferralCreatedWhenAffiliateIsSuspended(): void
    {
        $this->messageBus->dispatch(new AttributeReferral(
            subscriberPlayerId: PlayerFixture::PLAYER_WITH_FAVORITES,
            sessionReferralCode: AffiliateFixture::AFFILIATE_SUSPENDED_CODE,
        ));

        $this->expectException(ReferralNotFound::class);
        $this->referralRepository->getBySubscriberId(PlayerFixture::PLAYER_WITH_FAVORITES);
    }

    public function testNoDuplicateReferralCreated(): void
    {
        $existingReferral = $this->referralRepository->getBySubscriberId(PlayerFixture::PLAYER_PRIVATE);

        $this->messageBus->dispatch(new AttributeReferral(
            subscriberPlayerId: PlayerFixture::PLAYER_PRIVATE,
            sessionReferralCode: AffiliateFixture::AFFILIATE_ACTIVE_CODE,
        ));

        $referral = $this->referralRepository->getBySubscriberId(PlayerFixture::PLAYER_PRIVATE);
        self::assertSame($existingReferral->id->toString(), $referral->id->toString());
    }

    public function testSelfReferralIsBlocked(): void
    {
        $this->messageBus->dispatch(new AttributeReferral(
            subscriberPlayerId: PlayerFixture::PLAYER_REGULAR,
            sessionReferralCode: AffiliateFixture::AFFILIATE_ACTIVE_CODE,
        ));

        $this->expectException(ReferralNotFound::class);
        $this->referralRepository->getBySubscriberId(PlayerFixture::PLAYER_REGULAR);
    }

    public function testCodeIsCaseInsensitive(): void
    {
        $this->messageBus->dispatch(new AttributeReferral(
            subscriberPlayerId: PlayerFixture::PLAYER_ADMIN,
            sessionReferralCode: strtolower(AffiliateFixture::AFFILIATE_ACTIVE_CODE),
        ));

        $referral = $this->referralRepository->getBySubscriberId(PlayerFixture::PLAYER_ADMIN);
        self::assertSame(AffiliateFixture::AFFILIATE_ACTIVE_ID, $referral->affiliate->id->toString());
    }
}
