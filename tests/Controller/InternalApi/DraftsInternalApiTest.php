<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/internal-api.md "Organizations, series and drafts": drafts of competitions through the API - create,
 * publish, unpublish (refused while somebody joined), the `draft` list filter, and `status` staying the approval state.
 */
final class DraftsInternalApiTest extends WebTestCase
{
    use InternalApiRequests;

    public function testPublishesAndUnpublishesACompetition(): void
    {
        $browser = self::createClient();
        $uri = '/internal-api/competitions/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT;

        $before = self::callInternalApi($browser, 'GET', $uri);
        self::assertTrue($before['draft']);
        self::assertTrue($before['hiddenAsDraft']);
        // An approved draft is approved - just not public
        self::assertSame('approved', $before['status']);
        self::assertFalse($before['publiclyVisible']);

        self::callInternalApi($browser, 'POST', $uri . '/publish');
        self::assertResponseStatusCodeSame(204);
        $published = self::callInternalApi($browser, 'GET', $uri);
        self::assertFalse($published['draft']);
        self::assertTrue($published['publiclyVisible']);

        self::callInternalApi($browser, 'POST', $uri . '/unpublish');
        self::assertResponseStatusCodeSame(204);
        self::assertTrue(self::callInternalApi($browser, 'GET', $uri)['draft']);

        $patched = self::callInternalApi($browser, 'PATCH', $uri, ['draft' => false]);
        self::assertResponseIsSuccessful();
        self::assertFalse($patched['draft']);
    }

    public function testAnEventSomebodyJoinedStaysPublished(): void
    {
        $browser = self::createClient();
        $uri = '/internal-api/competitions/' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN;
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new JoinCompetition(OrganizationFixture::COMPETITION_RIVERBEND_OPEN, PlayerFixture::PLAYER_REGULAR));

        $refused = self::callInternalApi($browser, 'POST', $uri . '/unpublish');
        self::assertResponseStatusCodeSame(409);
        self::assertIsString($refused['error']);

        // Through PATCH it is checked before anything is written - the name stays too
        self::callInternalApi($browser, 'PATCH', $uri, ['draft' => true, 'name' => 'Renamed Open']);
        self::assertResponseStatusCodeSame(409);

        $after = self::callInternalApi($browser, 'GET', $uri);
        self::assertFalse($after['draft']);
        self::assertSame(OrganizationFixture::COMPETITION_RIVERBEND_OPEN_NAME, $after['name']);
        self::assertSame(1, $after['participantsCount']);
    }

    public function testCreatesADraftAndListsDrafts(): void
    {
        $browser = self::createClient();

        $created = self::callInternalApi($browser, 'POST', '/internal-api/competitions', [
            'name' => 'Pinecrest Puzzle Preview',
            'isOnline' => true,
            'approve' => true,
            'draft' => true,
            'eligibility' => 'Members only',
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertTrue($created['draft']);
        self::assertSame('approved', $created['status']);
        self::assertSame('Members only', $created['eligibility']);
        self::assertFalse($created['publiclyVisible']);

        $drafts = self::callInternalApi($browser, 'GET', '/internal-api/competitions?status=draft&limit=100');
        $draftIds = array_column(self::list($drafts['competitions']), 'competitionId');
        foreach ([$created['competitionId'], OrganizationFixture::COMPETITION_DRAFT_NIGHT, OrganizationFixture::COMPETITION_WILLOW_PENDING_DRAFT, OrganizationFixture::EDITION_LANTERN_DRAFT, OrganizationFixture::EDITION_QUIET_PINES_1] as $id) {
            self::assertContains($id, $draftIds);
        }
        self::assertNotContains(OrganizationFixture::EDITION_LANTERN_1, $draftIds);

        // "pending" is the approval state: an approved draft is not pending, a pending draft is
        $pending = self::callInternalApi($browser, 'GET', '/internal-api/competitions?status=pending&limit=100');
        $pendingIds = array_column(self::list($pending['competitions']), 'competitionId');
        self::assertNotContains(OrganizationFixture::COMPETITION_DRAFT_NIGHT, $pendingIds);
        self::assertContains(OrganizationFixture::COMPETITION_WILLOW_PENDING_DRAFT, $pendingIds);

        $approved = self::callInternalApi($browser, 'GET', '/internal-api/competitions?status=approved&limit=100');
        self::assertContains(OrganizationFixture::COMPETITION_DRAFT_NIGHT, array_column(self::list($approved['competitions']), 'competitionId'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var list<array<string, mixed>> $value */
        return $value;
    }
}
