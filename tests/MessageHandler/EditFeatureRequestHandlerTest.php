<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\FeatureRequestCanNotBeEdited;
use SpeedPuzzling\Web\Exceptions\FeatureRequestNotFound;
use SpeedPuzzling\Web\Message\EditFeatureRequest;
use SpeedPuzzling\Web\Query\GetFeatureRequestDetail;
use SpeedPuzzling\Web\Tests\DataFixtures\FeatureRequestFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class EditFeatureRequestHandlerTest extends KernelTestCase
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

    public function testAuthorCanEditWhenNoExternalVotes(): void
    {
        // FEATURE_REQUEST_NEW is authored by PLAYER_ADMIN
        // It only has auto-vote from author — no external votes
        $this->messageBus->dispatch(new EditFeatureRequest(
            featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_NEW,
            playerId: PlayerFixture::PLAYER_ADMIN,
            title: 'Updated title',
            description: 'Updated description',
        ));

        $detail = $this->getFeatureRequestDetail->byId(FeatureRequestFixture::FEATURE_REQUEST_NEW);
        self::assertSame('Updated title', $detail->title);
        self::assertSame('Updated description', $detail->description);
    }

    public function testCannotEditWhenHasExternalVotes(): void
    {
        // FEATURE_REQUEST_POPULAR has votes from PLAYER_ADMIN and PLAYER_REGULAR (external)
        try {
            $this->messageBus->dispatch(new EditFeatureRequest(
                featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_POPULAR,
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
                title: 'Should fail',
                description: 'Should fail',
            ));
            self::fail('Expected FeatureRequestCanNotBeEdited exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(FeatureRequestCanNotBeEdited::class, $previous);
        }
    }

    public function testNonAuthorCannotEdit(): void
    {
        try {
            $this->messageBus->dispatch(new EditFeatureRequest(
                featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_NEW,
                playerId: PlayerFixture::PLAYER_REGULAR,
                title: 'Should fail',
                description: 'Should fail',
            ));
            self::fail('Expected FeatureRequestNotFound exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(FeatureRequestNotFound::class, $previous);
        }
    }
}
