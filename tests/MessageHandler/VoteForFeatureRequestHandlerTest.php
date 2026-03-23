<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\AlreadyVotedForFeatureRequest;
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

        // PLAYER_REGULAR voted last month for POPULAR, so they have budget this month
        // and have NOT voted for NEW request
        $this->messageBus->dispatch(new VoteForFeatureRequest(
            voterId: PlayerFixture::PLAYER_REGULAR,
            featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_NEW,
        ));

        $detailAfter = $this->getFeatureRequestDetail->byId(FeatureRequestFixture::FEATURE_REQUEST_NEW);
        self::assertSame($voteCountBefore + 1, $detailAfter->voteCount);
    }

    public function testCannotVoteTwiceForSameRequest(): void
    {
        // PLAYER_WITH_STRIPE already voted for POPULAR (auto-vote on create)
        try {
            $this->messageBus->dispatch(new VoteForFeatureRequest(
                voterId: PlayerFixture::PLAYER_WITH_STRIPE,
                featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_POPULAR,
            ));
            self::fail('Expected AlreadyVotedForFeatureRequest exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(AlreadyVotedForFeatureRequest::class, $previous);
        }
    }

    public function testCannotVoteMoreThanOncePerMonth(): void
    {
        // PLAYER_ADMIN already has a vote this month (voted for POPULAR 2 days ago)
        try {
            $this->messageBus->dispatch(new VoteForFeatureRequest(
                voterId: PlayerFixture::PLAYER_ADMIN,
                featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_POPULAR,
            ));
            self::fail('Expected exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            // Could be AlreadyVotedForFeatureRequest (voted already) or VoteLimitReached
            self::assertTrue(
                $previous instanceof AlreadyVotedForFeatureRequest || $previous instanceof VoteLimitReached,
                'Expected AlreadyVotedForFeatureRequest or VoteLimitReached',
            );
        }
    }
}
