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
use SpeedPuzzling\Web\Tests\Controller\InternalApi\InternalApiRequests;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The expected team size of a team round (participants-spreadsheet.md D5, delivery contract O6) on the round form and
 * through the internal API: team rounds only, 2 to 20, never a limit - the participants sheet points out teams of
 * another size.
 */
final class RoundTeamSizeTest extends WebTestCase
{
    use InternalApiRequests;

    // Organised by PLAYER_REGULAR
    private const string COMPETITION = CompetitionFixture::COMPETITION_UNAPPROVED;
    private const string ADD_URL = '/en/add-event-round/' . self::COMPETITION;
    private const string ROUNDS_URL = '/en/manage-event-rounds/' . self::COMPETITION;
    private const string FIELD = 'competition_round_form[teamSize]';

    public function testTheAddFormStoresTheSizeOfATeamRoundOnly(): void
    {
        $browser = $this->organiser();

        $crawler = $browser->request('GET', self::ADD_URL);
        self::assertResponseIsSuccessful();
        $input = $crawler->filter('input[name="' . self::FIELD . '"]');
        self::assertCount(1, $input);
        self::assertSame('2', $input->attr('min'));
        self::assertSame('20', $input->attr('max'));
        self::assertStringContainsString('Members per team', $crawler->filter('label[for="' . $input->attr('id') . '"]')->text());

        $relay = $this->addRound($browser, 'Team Relay', 'team', '4');
        self::assertSame(4, $this->teamSize($relay));

        // A pair always has 2 - a size typed for a pair round is ignored
        $pairs = $this->addRound($browser, 'Pairs', 'duo', '3');
        self::assertNull($this->teamSize($pairs));

        $open = $this->addRound($browser, 'Open Teams', 'team', '');
        self::assertNull($this->teamSize($open));
    }

    public function testASizeOutsideTwoToTwentyIsRefused(): void
    {
        $browser = $this->organiser();

        foreach (['1', '21'] as $invalid) {
            $browser->request('GET', self::ADD_URL);
            $browser->submitForm('Add Round', $this->roundFields('Invalid ' . $invalid, 'team') + [self::FIELD => $invalid]);
            self::assertResponseStatusCodeSame(422);
            self::assertFalse($this->roundIdNamed('Invalid ' . $invalid));
        }
    }

    public function testTheEditFormIsPrefilledWithTheMostCommonSizeAndAnEmptyFieldTakesTheSizeAway(): void
    {
        $browser = $this->organiser();
        $roundId = $this->addRound($browser, 'Team Relay', 'team', '');

        // Teams of 4, 4 and 3 people, and one made in advance with nobody yet
        $this->team($roundId, 'Corners', 4);
        $this->team($roundId, 'Edges', 4);
        $this->team($roundId, 'Middles', 3);
        $this->team($roundId, 'Ready Team', 0);

        $crawler = $browser->request('GET', $this->editUrl($roundId));
        self::assertResponseIsSuccessful();
        self::assertSame('4', $crawler->filter('input[name="' . self::FIELD . '"]')->attr('value'));
        self::assertNull($this->teamSize($roundId), 'Only shown - stored when the organiser saves');

        $browser->submitForm('Save Changes');
        self::assertResponseRedirects(self::ROUNDS_URL);
        self::assertSame(4, $this->teamSize($roundId));

        $browser->request('GET', $this->editUrl($roundId));
        $browser->submitForm('Save Changes', [self::FIELD => '5']);
        self::assertSame(5, $this->teamSize($roundId));

        $browser->request('GET', $this->editUrl($roundId));
        $browser->submitForm('Save Changes', [self::FIELD => '']);
        self::assertResponseRedirects(self::ROUNDS_URL);
        self::assertNull($this->teamSize($roundId));
    }

    /**
     * Review A-r13: the guess never makes the untouched form invalid - teams of 25 offer 20, teams of one offer nothing.
     */
    public function testTheGuessStaysWithinWhatTheFormAccepts(): void
    {
        $browser = $this->organiser();
        $big = $this->addRound($browser, 'Big Teams', 'team', '');
        $this->team($big, 'Crowd', 25);
        $this->team($big, 'Mob', 25);

        $crawler = $browser->request('GET', $this->editUrl($big));
        self::assertSame('20', $crawler->filter('input[name="' . self::FIELD . '"]')->attr('value'));
        $browser->submitForm('Save Changes');
        self::assertResponseRedirects(self::ROUNDS_URL);
        self::assertSame(20, $this->teamSize($big));

        $singles = $this->addRound($browser, 'Single Teams', 'team', '');
        $this->team($singles, 'Lone', 1);
        $this->team($singles, 'Wolf', 1);
        $this->team($singles, 'Pair', 2);

        $crawler = $browser->request('GET', $this->editUrl($singles));
        self::assertSame('', (string) $crawler->filter('input[name="' . self::FIELD . '"]')->attr('value'));
    }

    public function testTheInternalApiSetsKeepsAndClearsTheSize(): void
    {
        $browser = self::createClient();

        $round = self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024 . '/rounds', [
            'name' => 'Team Relay',
            'category' => 'team',
            'startsAt' => '2026-10-10T10:00:00Z',
            'minutesLimit' => 90,
            'teamSize' => 4,
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(4, $round['teamSize']);
        $roundId = $round['roundId'];
        self::assertIsString($roundId);

        // Left out: kept
        $renamed = self::callInternalApi($browser, 'PATCH', '/internal-api/rounds/' . $roundId, ['name' => 'Team Relay Final']);
        self::assertResponseIsSuccessful();
        self::assertSame(4, $renamed['teamSize']);

        $changed = self::callInternalApi($browser, 'PATCH', '/internal-api/rounds/' . $roundId, ['teamSize' => 6]);
        self::assertSame(6, $changed['teamSize']);

        $invalid = self::callInternalApi($browser, 'PATCH', '/internal-api/rounds/' . $roundId, ['teamSize' => 21]);
        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($invalid['errors']);
        self::assertArrayHasKey('teamSize', $invalid['errors']);
        self::assertSame(6, $this->teamSize($roundId));

        $cleared = self::callInternalApi($browser, 'PATCH', '/internal-api/rounds/' . $roundId, ['teamSize' => null]);
        self::assertResponseIsSuccessful();
        self::assertNull($cleared['teamSize']);

        // A solo round has no team size, whatever is sent
        $solo = self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024 . '/rounds', [
            'name' => 'Solo Heat',
            'startsAt' => '2026-10-10T12:00:00Z',
            'minutesLimit' => 60,
            'teamSize' => 4,
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertNull($solo['teamSize']);
    }

    private function organiser(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        return $browser;
    }

    /**
     * @return array<string, string>
     */
    private function roundFields(string $name, string $category): array
    {
        return [
            'competition_round_form[name]' => $name,
            'competition_round_form[category]' => $category,
            'competition_round_form[minutesLimit]' => '90',
            'competition_round_form[startsAt]' => '24.10.2030 10:05',
            'competition_round_form[timezone]' => 'Europe/Vienna',
        ];
    }

    private function addRound(KernelBrowser $browser, string $name, string $category, string $teamSize): string
    {
        $browser->request('GET', self::ADD_URL);
        $browser->submitForm('Add Round', $this->roundFields($name, $category) + [self::FIELD => $teamSize]);
        self::assertResponseRedirects(self::ROUNDS_URL);

        $roundId = $this->roundIdNamed($name);
        self::assertIsString($roundId);

        return $roundId;
    }

    private function roundIdNamed(string $name): string|false
    {
        $roundId = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT id FROM competition_round WHERE competition_id = :competition AND name = :name',
            ['competition' => self::COMPETITION, 'name' => $name],
        );

        return is_string($roundId) ? $roundId : false;
    }

    private function teamSize(string $roundId): mixed
    {
        return self::getContainer()->get(Connection::class)->fetchOne('SELECT team_size FROM competition_round WHERE id = :id', ['id' => $roundId]);
    }

    private function editUrl(string $roundId): string
    {
        return '/en/edit-event-round/' . $roundId;
    }

    private function team(string $roundId, string $name, int $members): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $round = $entityManager->find(CompetitionRound::class, $roundId);
        assert($round !== null);

        $team = new CompetitionTeam(Uuid::uuid7(), $round, $name);
        $entityManager->persist($team);

        for ($i = 1; $i <= $members; $i++) {
            $participant = new CompetitionParticipant(Uuid::uuid7(), $name . ' Member ' . $i, null, $round->competition);
            $entityManager->persist($participant);
            $entityManager->persist(new CompetitionParticipantRound(Uuid::uuid7(), $participant, $round, $team));
        }

        $entityManager->flush();
    }
}
