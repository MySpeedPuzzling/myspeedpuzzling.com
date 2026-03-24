<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\FeatureRequestLimitReached;
use SpeedPuzzling\Web\Message\CreateFeatureRequest;
use SpeedPuzzling\Web\Query\CountPlayerFeatureRequestsThisMonth;
use SpeedPuzzling\Web\Query\GetFeatureRequestDetail;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class CreateFeatureRequestHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private GetFeatureRequestDetail $getFeatureRequestDetail;
    private CountPlayerFeatureRequestsThisMonth $countPlayerFeatureRequestsThisMonth;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->getFeatureRequestDetail = $container->get(GetFeatureRequestDetail::class);
        $this->countPlayerFeatureRequestsThisMonth = $container->get(CountPlayerFeatureRequestsThisMonth::class);
    }

    public function testCreatingFeatureRequest(): void
    {
        $envelope = $this->messageBus->dispatch(new CreateFeatureRequest(
            authorId: PlayerFixture::PLAYER_REGULAR,
            title: 'Test Feature',
            description: 'This is a test feature request.',
        ));

        $handledStamp = $envelope->last(HandledStamp::class);
        self::assertNotNull($handledStamp);

        $featureRequestId = $handledStamp->getResult();
        self::assertIsString($featureRequestId);

        $detail = $this->getFeatureRequestDetail->byId($featureRequestId);
        self::assertSame('Test Feature', $detail->title);
        self::assertSame('This is a test feature request.', $detail->description);
        self::assertSame(0, $detail->voteCount);
    }

    public function testMonthlyLimitOf3Requests(): void
    {
        // Create 3 requests
        for ($i = 1; $i <= 3; $i++) {
            $this->messageBus->dispatch(new CreateFeatureRequest(
                authorId: PlayerFixture::PLAYER_WITH_FAVORITES,
                title: "Test Feature $i",
                description: "Description $i",
            ));
        }

        $count = ($this->countPlayerFeatureRequestsThisMonth)(PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertSame(3, $count);

        // 4th should fail
        try {
            $this->messageBus->dispatch(new CreateFeatureRequest(
                authorId: PlayerFixture::PLAYER_WITH_FAVORITES,
                title: 'Test Feature 4',
                description: 'Description 4',
            ));
            self::fail('Expected FeatureRequestLimitReached exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(FeatureRequestLimitReached::class, $previous);
        }
    }
}
