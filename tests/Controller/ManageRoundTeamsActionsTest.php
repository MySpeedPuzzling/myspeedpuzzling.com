<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Delete (WEB-D5), rename and assign on the round teams page - every form carries the page's CSRF token.
 */
final class ManageRoundTeamsActionsTest extends WebTestCase
{
    private const string PAGE = '/en/manage-round-teams/' . CompetitionSeriesFixture::ROUND_OFFLINE_TEAM;

    private KernelBrowser $browser;
    private EntityManagerInterface $entityManager;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testDeletingATeamWithMembersSendsThemBackToUnassigned(): void
    {
        $teamId = $this->team('Edge Lords', ['Alex Example', 'Blake Example']);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', self::PAGE);
        $form = $crawler->filter('#team-' . $teamId . ' form[action$="/delete-team/' . $teamId . '"]');
        // The text is escaped for JavaScript (\u0020 for a space)
        $question = json_decode('"' . str_replace(['return confirm(\'', '\')'], '', (string) $form->attr('onsubmit')) . '"');
        self::assertSame('Delete this team? Its 2 members go back to the unassigned participants of this round.', $question);

        $this->browser->submit($form->form());

        $this->assertResponseStatusCodeSame(303);
        $this->assertResponseRedirects(self::PAGE);
        self::assertFalse($this->database->fetchOne('SELECT id FROM competition_team WHERE id = :id', ['id' => $teamId]));

        $crawler = $this->browser->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'Team deleted.');
        self::assertCount(1, $crawler->filter('h2:contains("Unassigned participants (2)")'));
    }

    public function testEveryFormCarriesTheToken(): void
    {
        $teamId = $this->team('Edge Lords', ['Alex Example']);
        $this->team('Corner Crew', []);
        $this->unassigned('Casey Example');
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', self::PAGE);

        $forms = $crawler->filter('form[action*="/delete-team/"], form[action*="/rename-team/"], form[action*="/assign-participant-to-team/"]');
        self::assertCount(6, $forms, 'delete ×2, rename ×2, remove from team, assign from the unassigned list');

        $forms->each(static function (Crawler $form): void {
            $token = $form->filter('input[name="_token"]');
            self::assertCount(1, $token, 'Form without a token: ' . $form->attr('action'));
            self::assertNotSame('', $token->attr('value'));
        });

        // The team cards' assign form is put together by the script, which copies the page's token
        self::assertCount(1, $crawler->filter('#team-' . $teamId . ' .assign-form'));
        self::assertMatchesRegularExpression("/token\\.value = '[^']+';/", (string) $this->browser->getResponse()->getContent());
    }

    public function testWrongTokenChangesNothing(): void
    {
        $teamId = $this->team('Edge Lords', ['Alex Example']);
        $memberEntryId = $this->entryOf('Alex Example');
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $this->browser->request('POST', '/en/delete-team/' . $teamId, ['_token' => 'wrong']);
        $this->assertResponseStatusCodeSame(303);
        $this->browser->request('POST', '/en/rename-team/' . $teamId, ['_token' => 'wrong', 'name' => 'Hijacked']);
        $this->assertResponseStatusCodeSame(303);
        $this->browser->request('POST', '/en/assign-participant-to-team/' . $memberEntryId, ['_token' => 'wrong', 'team_id' => '']);
        $this->assertResponseStatusCodeSame(303);

        self::assertSame('Edge Lords', $this->database->fetchOne('SELECT name FROM competition_team WHERE id = :id', ['id' => $teamId]));
        self::assertSame($teamId, $this->database->fetchOne('SELECT team_id FROM competition_participant_round WHERE id = :id', ['id' => $memberEntryId]));

        $this->browser->followRedirect();
        $this->assertSelectorTextContains('.alert-danger', 'The page was open for too long.');
    }

    public function testTokenOfAnotherRoundIsRefused(): void
    {
        $this->team('Edge Lords', []);
        $otherRoundTeamId = $this->team('Solo Somehow', [], CompetitionSeriesFixture::ROUND_OFFLINE_SOLO);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', self::PAGE);
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->browser->request('POST', '/en/delete-team/' . $otherRoundTeamId, ['_token' => $token]);

        $this->assertResponseStatusCodeSame(303);
        self::assertNotFalse($this->database->fetchOne('SELECT id FROM competition_team WHERE id = :id', ['id' => $otherRoundTeamId]));
    }

    public function testRenamingNamesAndUnnamesATeam(): void
    {
        $teamId = $this->team(null, []);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', self::PAGE);
        $this->browser->submit($crawler->filter('#rename-team-' . $teamId)->form(['name' => '  Corner   Crew ']));

        $this->assertResponseStatusCodeSame(303);
        $this->assertResponseRedirects(self::PAGE . '#team-' . $teamId);
        self::assertSame('Corner Crew', $this->database->fetchOne('SELECT name FROM competition_team WHERE id = :id', ['id' => $teamId]));

        $crawler = $this->browser->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'Team renamed.');
        self::assertSame('Corner Crew', $crawler->filter('#rename-team-' . $teamId . '-name')->attr('value'));

        $this->browser->submit($crawler->filter('#rename-team-' . $teamId)->form(['name' => '']));
        self::assertNull($this->database->fetchOne('SELECT name FROM competition_team WHERE id = :id', ['id' => $teamId]));
        $this->browser->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'The team has no name now.');
    }

    public function testTooLongNameIsRefusedWithAMessage(): void
    {
        $teamId = $this->team('Edge Lords', []);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', self::PAGE);
        $form = $crawler->filter('#rename-team-' . $teamId)->form();
        $form->setValues(['name' => str_repeat('x', CompetitionTeam::NAME_MAX_LENGTH + 1)]);
        $this->browser->submit($form);

        $this->assertResponseStatusCodeSame(303);
        self::assertSame('Edge Lords', $this->database->fetchOne('SELECT name FROM competition_team WHERE id = :id', ['id' => $teamId]));
        $this->browser->followRedirect();
        $this->assertSelectorTextContains('.alert-danger', 'at most 255 characters');
    }

    public function testOnlyMaintainersRename(): void
    {
        $teamId = $this->team('Edge Lords', []);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('POST', '/en/rename-team/' . $teamId, ['_token' => 'any', 'name' => 'Hijacked']);

        $this->assertResponseStatusCodeSame(403);
        self::assertSame('Edge Lords', $this->database->fetchOne('SELECT name FROM competition_team WHERE id = :id', ['id' => $teamId]));
    }

    public function testOnlyMaintainersDeleteOrAssign(): void
    {
        $teamId = $this->team('Edge Lords', ['Alex Example']);
        $entryId = $this->entryOf('Alex Example');
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('POST', '/en/delete-team/' . $teamId, ['_token' => 'any']);
        $this->assertResponseStatusCodeSame(403);
        $this->browser->request('POST', '/en/assign-participant-to-team/' . $entryId, ['_token' => 'any', 'team_id' => '']);
        $this->assertResponseStatusCodeSame(403);

        self::assertSame($teamId, $this->database->fetchOne('SELECT team_id FROM competition_participant_round WHERE id = :id', ['id' => $entryId]));
    }

    public function testAssignAndRemoveFromTeam(): void
    {
        $teamId = $this->team('Edge Lords', []);
        $this->unassigned('Casey Example');
        $entryId = $this->entryOf('Casey Example');
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', self::PAGE);
        $this->browser->submit($crawler->filter('form[action$="/assign-participant-to-team/' . $entryId . '"]')->form(['team_id' => $teamId]));
        $this->assertResponseStatusCodeSame(303);
        self::assertSame($teamId, $this->database->fetchOne('SELECT team_id FROM competition_participant_round WHERE id = :id', ['id' => $entryId]));

        $crawler = $this->browser->followRedirect();
        $this->browser->submit($crawler->filter('#team-' . $teamId . ' form[action$="/assign-participant-to-team/' . $entryId . '"]')->form());
        $this->assertResponseStatusCodeSame(303);
        self::assertNull($this->database->fetchOne('SELECT team_id FROM competition_participant_round WHERE id = :id', ['id' => $entryId]));
    }

    public function testTeamOfAnotherRoundIsNotAssigned(): void
    {
        $this->team('Edge Lords', []);
        $otherRoundTeamId = $this->team('Solo Somehow', [], CompetitionSeriesFixture::ROUND_OFFLINE_SOLO);
        $this->unassigned('Casey Example');
        $entryId = $this->entryOf('Casey Example');
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', self::PAGE);
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
        $this->browser->request('POST', '/en/assign-participant-to-team/' . $entryId, ['_token' => $token, 'team_id' => $otherRoundTeamId]);

        $this->assertResponseStatusCodeSame(303);
        self::assertNull($this->database->fetchOne('SELECT team_id FROM competition_participant_round WHERE id = :id', ['id' => $entryId]));
        $this->browser->followRedirect();
        $this->assertSelectorTextContains('.alert-danger', 'That team is not in this round.');
    }

    public function testTeamsSharingANameAreToldApart(): void
    {
        $firstId = $this->team('Jigsaw Crew', ['Alex Example']);
        $secondId = $this->team('jigsaw crew', ['Blake Example']);
        $this->team('Corner Crew', []);
        $this->unassigned('Casey Example');
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', self::PAGE);

        self::assertCount(2, $crawler->filter('.card:contains("Another team in this round has the same name.")'));
        self::assertStringContainsString('Jigsaw Crew (Alex Example)', $crawler->filter('option[value="' . $firstId . '"]')->text());
        self::assertStringContainsString('jigsaw crew (Blake Example)', $crawler->filter('option[value="' . $secondId . '"]')->text());
    }

    /**
     * @param list<string> $members
     */
    private function team(null|string $name, array $members, string $roundId = CompetitionSeriesFixture::ROUND_OFFLINE_TEAM): string
    {
        $round = $this->entityManager->find(CompetitionRound::class, $roundId);
        self::assertNotNull($round);

        $team = new CompetitionTeam(Uuid::uuid7(), $round, $name);
        $this->entityManager->persist($team);

        foreach ($members as $member) {
            $participant = new CompetitionParticipant(Uuid::uuid7(), $member, 'cz', $round->competition);
            $this->entityManager->persist($participant);
            $this->entityManager->persist(new CompetitionParticipantRound(Uuid::uuid7(), $participant, $round, $team));
        }

        $this->entityManager->flush();

        return $team->id->toString();
    }

    private function unassigned(string $name): void
    {
        $round = $this->entityManager->find(CompetitionRound::class, CompetitionSeriesFixture::ROUND_OFFLINE_TEAM);
        self::assertNotNull($round);

        $participant = new CompetitionParticipant(Uuid::uuid7(), $name, 'cz', $round->competition);
        $this->entityManager->persist($participant);
        $this->entityManager->persist(new CompetitionParticipantRound(Uuid::uuid7(), $participant, $round));
        $this->entityManager->flush();
    }

    private function entryOf(string $name): string
    {
        $id = $this->database->fetchOne(
            'SELECT cpr.id FROM competition_participant_round cpr
             INNER JOIN competition_participant cp ON cp.id = cpr.participant_id
             WHERE cp.name = :name AND cpr.round_id = :roundId',
            ['name' => $name, 'roundId' => CompetitionSeriesFixture::ROUND_OFFLINE_TEAM],
        );
        self::assertIsString($id);

        return $id;
    }
}
