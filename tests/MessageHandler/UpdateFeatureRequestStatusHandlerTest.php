<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\UpdateFeatureRequestStatus;
use SpeedPuzzling\Web\Query\GetFeatureRequestDetail;
use SpeedPuzzling\Web\Tests\DataFixtures\FeatureRequestFixture;
use SpeedPuzzling\Web\Value\FeatureRequestStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class UpdateFeatureRequestStatusHandlerTest extends KernelTestCase
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

    public function testStatusIsUpdated(): void
    {
        $this->messageBus->dispatch(new UpdateFeatureRequestStatus(
            featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_NEW,
            status: FeatureRequestStatus::InProgress,
        ));

        $detail = $this->getFeatureRequestDetail->byId(FeatureRequestFixture::FEATURE_REQUEST_NEW);
        self::assertSame(FeatureRequestStatus::InProgress, $detail->status);
    }

    public function testStatusIsUpdatedWithGithubUrlAndAdminComment(): void
    {
        $this->messageBus->dispatch(new UpdateFeatureRequestStatus(
            featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_NEW,
            status: FeatureRequestStatus::Completed,
            githubUrl: 'https://github.com/example/repo/issues/1',
            adminComment: 'This has been implemented!',
        ));

        $detail = $this->getFeatureRequestDetail->byId(FeatureRequestFixture::FEATURE_REQUEST_NEW);
        self::assertSame(FeatureRequestStatus::Completed, $detail->status);
        self::assertSame('https://github.com/example/repo/issues/1', $detail->githubUrl);
        self::assertSame('This has been implemented!', $detail->adminComment);
    }

    public function testStatusCanBeDeclined(): void
    {
        $this->messageBus->dispatch(new UpdateFeatureRequestStatus(
            featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_POPULAR,
            status: FeatureRequestStatus::Declined,
            adminComment: 'Not feasible at this time.',
        ));

        $detail = $this->getFeatureRequestDetail->byId(FeatureRequestFixture::FEATURE_REQUEST_POPULAR);
        self::assertSame(FeatureRequestStatus::Declined, $detail->status);
        self::assertSame('Not feasible at this time.', $detail->adminComment);
    }
}
