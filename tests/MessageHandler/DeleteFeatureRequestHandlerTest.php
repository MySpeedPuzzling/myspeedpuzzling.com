<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\FeatureRequestCanNotBeEdited;
use SpeedPuzzling\Web\Exceptions\FeatureRequestNotFound;
use SpeedPuzzling\Web\Message\CreateFeatureRequest;
use SpeedPuzzling\Web\Message\DeleteFeatureRequest;
use SpeedPuzzling\Web\Query\GetFeatureRequestDetail;
use SpeedPuzzling\Web\Tests\DataFixtures\FeatureRequestFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class DeleteFeatureRequestHandlerTest extends KernelTestCase
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

    public function testAuthorCanDeleteWhenNoExternalVotes(): void
    {
        // Create a fresh request to delete
        $envelope = $this->messageBus->dispatch(new CreateFeatureRequest(
            authorId: PlayerFixture::PLAYER_REGULAR,
            title: 'To be deleted',
            description: 'This will be deleted.',
        ));

        $handledStamp = $envelope->last(HandledStamp::class);
        assert($handledStamp !== null);
        $featureRequestId = $handledStamp->getResult();
        assert(is_string($featureRequestId));

        $this->messageBus->dispatch(new DeleteFeatureRequest(
            featureRequestId: $featureRequestId,
            playerId: PlayerFixture::PLAYER_REGULAR,
        ));

        $this->expectException(FeatureRequestNotFound::class);
        $this->getFeatureRequestDetail->byId($featureRequestId);
    }

    public function testCannotDeleteWhenHasExternalVotes(): void
    {
        try {
            $this->messageBus->dispatch(new DeleteFeatureRequest(
                featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_POPULAR,
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
            ));
            self::fail('Expected FeatureRequestCanNotBeEdited exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(FeatureRequestCanNotBeEdited::class, $previous);
        }
    }

    public function testNonAuthorCannotDelete(): void
    {
        try {
            $this->messageBus->dispatch(new DeleteFeatureRequest(
                featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_NEW,
                playerId: PlayerFixture::PLAYER_REGULAR,
            ));
            self::fail('Expected FeatureRequestNotFound exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(FeatureRequestNotFound::class, $previous);
        }
    }
}
