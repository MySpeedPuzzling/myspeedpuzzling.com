<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\ReferralNotFound;
use SpeedPuzzling\Web\Message\AttributeReferral;
use SpeedPuzzling\Web\Repository\ReferralRepository;
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
        // PLAYER_REGULAR is in referral program (from fixture), use their code
        $this->messageBus->dispatch(new AttributeReferral(
            subscriberPlayerId: PlayerFixture::PLAYER_ADMIN,
            sessionReferralCode: 'player1', // PLAYER_REGULAR's code
            cookieReferralCode: 'IGNORED',
        ));

        $referral = $this->referralRepository->getBySubscriberId(PlayerFixture::PLAYER_ADMIN);
        self::assertSame(ReferralSource::Code, $referral->source);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $referral->affiliatePlayer->id->toString());
    }

    public function testCookieCodeUsedWhenNoSessionCode(): void
    {
        $this->messageBus->dispatch(new AttributeReferral(
            subscriberPlayerId: PlayerFixture::PLAYER_WITH_FAVORITES,
            sessionReferralCode: null,
            cookieReferralCode: 'player1',
        ));

        $referral = $this->referralRepository->getBySubscriberId(PlayerFixture::PLAYER_WITH_FAVORITES);
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

    public function testNoReferralCreatedWhenCodeIsInvalid(): void
    {
        $this->messageBus->dispatch(new AttributeReferral(
            subscriberPlayerId: PlayerFixture::PLAYER_WITH_FAVORITES,
            sessionReferralCode: 'INVALID_CODE_XYZ',
        ));

        $this->expectException(ReferralNotFound::class);
        $this->referralRepository->getBySubscriberId(PlayerFixture::PLAYER_WITH_FAVORITES);
    }

    public function testNoReferralCreatedWhenPlayerNotInProgram(): void
    {
        // PLAYER_WITH_FAVORITES is not in referral program
        $this->messageBus->dispatch(new AttributeReferral(
            subscriberPlayerId: PlayerFixture::PLAYER_ADMIN,
            sessionReferralCode: PlayerFixture::PLAYER_WITH_FAVORITES_NAME, // not a code
        ));

        $this->expectException(ReferralNotFound::class);
        $this->referralRepository->getBySubscriberId(PlayerFixture::PLAYER_ADMIN);
    }

    public function testNoReferralCreatedWhenPlayerIsSuspended(): void
    {
        // PLAYER_WITH_STRIPE is suspended from referral program
        $this->messageBus->dispatch(new AttributeReferral(
            subscriberPlayerId: PlayerFixture::PLAYER_WITH_FAVORITES,
            sessionReferralCode: 'player4', // PLAYER_WITH_STRIPE's code
        ));

        $this->expectException(ReferralNotFound::class);
        $this->referralRepository->getBySubscriberId(PlayerFixture::PLAYER_WITH_FAVORITES);
    }

    public function testNoDuplicateReferralCreated(): void
    {
        // PLAYER_PRIVATE already has a referral (from fixtures)
        $existingReferral = $this->referralRepository->getBySubscriberId(PlayerFixture::PLAYER_PRIVATE);

        $this->messageBus->dispatch(new AttributeReferral(
            subscriberPlayerId: PlayerFixture::PLAYER_PRIVATE,
            sessionReferralCode: 'player1',
        ));

        $referral = $this->referralRepository->getBySubscriberId(PlayerFixture::PLAYER_PRIVATE);
        self::assertSame($existingReferral->id->toString(), $referral->id->toString());
    }

    public function testSelfReferralIsBlocked(): void
    {
        $this->messageBus->dispatch(new AttributeReferral(
            subscriberPlayerId: PlayerFixture::PLAYER_REGULAR,
            sessionReferralCode: 'player1', // PLAYER_REGULAR's own code
        ));

        $this->expectException(ReferralNotFound::class);
        $this->referralRepository->getBySubscriberId(PlayerFixture::PLAYER_REGULAR);
    }
}
