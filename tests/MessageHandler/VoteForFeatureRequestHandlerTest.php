<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CanNotVoteForOwnFeatureRequest;
use SpeedPuzzling\Web\Exceptions\VoteLimitReached;
use SpeedPuzzling\Web\Message\VoteForFeatureRequest;
use SpeedPuzzling\Web\Query\GetFeatureRequestDetail;
use SpeedPuzzling\Web\Tests\DataFixtures\FeatureRequestFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class VoteForFeatureRequestHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private GetFeatureRequestDetail $getFeatureRequestDetail;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->getFeatureRequestDetail = $container->get(GetFeatureRequestDetail::class);
    }

    public function testVoting(): void
    {
        $detailBefore = $this->getFeatureRequestDetail->byId(FeatureRequestFixture::FEATURE_REQUEST_NEW);
        $voteCountBefore = $detailBefore->voteCount;

        $this->messageBus->dispatch(new VoteForFeatureRequest(
            voterId: PlayerFixture::PLAYER_REGULAR,
            featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_NEW,
        ));

        $detailAfter = $this->getFeatureRequestDetail->byId(FeatureRequestFixture::FEATURE_REQUEST_NEW);
        self::assertSame($voteCountBefore + 1, $detailAfter->voteCount);
    }

    public function testCanVoteRepeatedly(): void
    {
        $detailBefore = $this->getFeatureRequestDetail->byId(FeatureRequestFixture::FEATURE_REQUEST_NEW);
        $voteCountBefore = $detailBefore->voteCount;

        // Vote twice for the same request
        $this->messageBus->dispatch(new VoteForFeatureRequest(
            voterId: PlayerFixture::PLAYER_REGULAR,
            featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_NEW,
        ));
        $this->messageBus->dispatch(new VoteForFeatureRequest(
            voterId: PlayerFixture::PLAYER_REGULAR,
            featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_NEW,
        ));

        $detailAfter = $this->getFeatureRequestDetail->byId(FeatureRequestFixture::FEATURE_REQUEST_NEW);
        self::assertSame($voteCountBefore + 2, $detailAfter->voteCount);
    }

    public function testCannotVoteForOwnRequest(): void
    {
        // FEATURE_REQUEST_POPULAR is authored by PLAYER_WITH_STRIPE
        try {
            $this->messageBus->dispatch(new VoteForFeatureRequest(
                voterId: PlayerFixture::PLAYER_WITH_STRIPE,
                featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_POPULAR,
            ));
            self::fail('Expected CanNotVoteForOwnFeatureRequest exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(CanNotVoteForOwnFeatureRequest::class, $previous);
        }
    }

    public function testCannotExceedMonthlyLimit(): void
    {
        // Use all 3 votes
        for ($i = 0; $i < 3; $i++) {
            $this->messageBus->dispatch(new VoteForFeatureRequest(
                voterId: PlayerFixture::PLAYER_WITH_FAVORITES,
                featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_POPULAR,
            ));
        }

        // 4th vote should fail
        try {
            $this->messageBus->dispatch(new VoteForFeatureRequest(
                voterId: PlayerFixture::PLAYER_WITH_FAVORITES,
                featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_POPULAR,
            ));
            self::fail('Expected VoteLimitReached exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(VoteLimitReached::class, $previous);
        }
    }
}
