<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\FeatureRequestNotFound;
use SpeedPuzzling\Web\Query\GetFeatureRequestComments;
use SpeedPuzzling\Web\Query\GetFeatureRequestDetail;
use SpeedPuzzling\Web\Query\GetFeatureRequests;
use SpeedPuzzling\Web\Results\FeatureRequestCommentView;
use SpeedPuzzling\Web\Results\FeatureRequestOverview;
use SpeedPuzzling\Web\Tests\DataFixtures\FeatureRequestCommentFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\FeatureRequestFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * FEATURE_REQUEST_POPULAR is by PLAYER_WITH_STRIPE, FEATURE_REQUEST_NEW by PLAYER_ADMIN.
 * On POPULAR: COMMENT_1 by PLAYER_ADMIN, COMMENT_2 by PLAYER_REGULAR.
 */
final class FeatureRequestsBlocklistTest extends KernelTestCase
{
    private GetFeatureRequests $getFeatureRequests;
    private GetFeatureRequestDetail $getFeatureRequestDetail;
    private GetFeatureRequestComments $getFeatureRequestComments;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->getFeatureRequests = self::getContainer()->get(GetFeatureRequests::class);
        $this->getFeatureRequestDetail = self::getContainer()->get(GetFeatureRequestDetail::class);
        $this->getFeatureRequestComments = self::getContainer()->get(GetFeatureRequestComments::class);

        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_WITH_FAVORITES, 'blocked' => PlayerFixture::PLAYER_ADMIN],
        );
    }

    public function testRequestsOfABlockedAuthorDisappearFromTheListForTheBlockerOnly(): void
    {
        self::assertContains(FeatureRequestFixture::FEATURE_REQUEST_NEW, $this->requestIds());

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertNotContains(FeatureRequestFixture::FEATURE_REQUEST_NEW, $this->requestIds());
        self::assertContains(FeatureRequestFixture::FEATURE_REQUEST_POPULAR, $this->requestIds());
        self::assertSame([], $this->requestIds(authorId: PlayerFixture::PLAYER_ADMIN));

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);
        self::assertContains(FeatureRequestFixture::FEATURE_REQUEST_NEW, $this->requestIds());
    }

    public function testDetailOfABlockedAuthorsRequestIsNotFound(): void
    {
        self::assertSame(
            FeatureRequestFixture::FEATURE_REQUEST_NEW,
            $this->getFeatureRequestDetail->byId(FeatureRequestFixture::FEATURE_REQUEST_NEW)->id,
        );

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertSame(
            FeatureRequestFixture::FEATURE_REQUEST_POPULAR,
            $this->getFeatureRequestDetail->byId(FeatureRequestFixture::FEATURE_REQUEST_POPULAR)->id,
        );

        $this->expectException(FeatureRequestNotFound::class);
        $this->getFeatureRequestDetail->byId(FeatureRequestFixture::FEATURE_REQUEST_NEW);
    }

    public function testCommentsOfABlockedAuthorDisappearForTheBlockerOnly(): void
    {
        self::assertEqualsCanonicalizing(
            [FeatureRequestCommentFixture::COMMENT_1, FeatureRequestCommentFixture::COMMENT_2],
            $this->commentIds(),
        );

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);
        self::assertCount(2, $this->commentIds());

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertSame([FeatureRequestCommentFixture::COMMENT_2], $this->commentIds());
    }

    /**
     * @return list<string>
     */
    private function requestIds(null|string $authorId = null): array
    {
        return array_values(array_map(
            static fn (FeatureRequestOverview $request): string => $request->id,
            $this->getFeatureRequests->findAll(authorId: $authorId),
        ));
    }

    /**
     * @return list<string>
     */
    private function commentIds(): array
    {
        return array_values(array_map(
            static fn (FeatureRequestCommentView $comment): string => $comment->id,
            $this->getFeatureRequestComments->forFeatureRequest(FeatureRequestFixture::FEATURE_REQUEST_POPULAR),
        ));
    }
}
