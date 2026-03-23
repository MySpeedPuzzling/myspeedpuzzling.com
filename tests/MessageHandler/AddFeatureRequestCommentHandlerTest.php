<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\AddFeatureRequestComment;
use SpeedPuzzling\Web\Query\GetFeatureRequestComments;
use SpeedPuzzling\Web\Tests\DataFixtures\FeatureRequestFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class AddFeatureRequestCommentHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private GetFeatureRequestComments $getFeatureRequestComments;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->getFeatureRequestComments = $container->get(GetFeatureRequestComments::class);
    }

    public function testAddingComment(): void
    {
        $commentsBefore = $this->getFeatureRequestComments->forFeatureRequest(FeatureRequestFixture::FEATURE_REQUEST_POPULAR);
        $countBefore = count($commentsBefore);

        $this->messageBus->dispatch(new AddFeatureRequestComment(
            authorId: PlayerFixture::PLAYER_WITH_FAVORITES,
            featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_POPULAR,
            content: 'This would be awesome!',
        ));

        $commentsAfter = $this->getFeatureRequestComments->forFeatureRequest(FeatureRequestFixture::FEATURE_REQUEST_POPULAR);
        self::assertCount($countBefore + 1, $commentsAfter);

        $found = false;
        foreach ($commentsAfter as $comment) {
            if ($comment->content === 'This would be awesome!') {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Comment should be in the list');
    }
}
