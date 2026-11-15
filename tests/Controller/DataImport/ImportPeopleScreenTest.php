<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\DataImport;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Message\DecideImportPerson;
use SpeedPuzzling\Web\Tests\MessageHandler\DataImport\ImportAddingScenario;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DataImport\ImportPersonChoice;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "People in your file" (docs/features/data-import/people.md): the page of an import's people and the answer about
 * one of them - the owner's only, a POST with its token, a redirect when taken and a 422 when not.
 */
final class ImportPeopleScreenTest extends WebTestCase
{
    use ImportAddingScenario;

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testThePageListsThePeopleTakesAnAnswerAndRefusesWhatItCanNotTake(): void
    {
        $importer = $this->seedPairingPlayer('peoplepage');
        $partner = $this->seedPairingPlayer('peoplepartner');
        $friend = $this->seedPairingPlayer('peoplefriend');
        $other = $this->seedPairingPlayer('peopleother');
        $this->database->update('player', ['name' => 'Zq Pagefriend'], ['id' => $friend->id->toString()]);
        $this->database->update('player', ['name' => 'Zq Pagepartner'], ['id' => $partner->id->toString()]);
        $this->database->update('player', ['favorite_players' => json_encode([$friend->id->toString()])], ['id' => $importer->id->toString()]);
        $brand = $this->seedBrand('Zqpage');
        $this->seedCataloguePuzzle('Zq Page Harbour', 1000, $brand);
        $this->seedCataloguePuzzle('Zq Page Meadow', 1000, $brand);

        [$import, $sheet] = $this->importOf($importer);
        $this->resultRow($import, $sheet, 'Zq Page Harbour', 1000, 'Zqpage', '2024-03-01', 5400, ['type' => 'duo', 'people' => ['#' . $partner->code]]);
        $this->resultRow($import, $sheet, 'Zq Page Harbour', 1000, 'Zqpage', '2024-03-05', 5450, ['type' => 'duo', 'people' => ['#' . $partner->code]]);
        $nameRow = $this->resultRow($import, $sheet, 'Zq Page Meadow', 1000, 'Zqpage', '2024-03-02', 5500, ['type' => 'duo', 'people' => ['zq pagefriend']]);
        $this->pair($import);
        $importId = $import->id->toString();
        $this->database->executeStatement('UPDATE data_import_sheet SET parsed_version = 1, mapping_version = 1 WHERE import_id = :id', ['id' => $importId]);
        $path = '/en/import/' . $importId . '/people';

        // Signed out: to the sign-in page. Somebody else: not found
        $this->browser->request('GET', $path);
        self::assertResponseRedirects();
        TestingLogin::asPlayer($this->browser, $other->id->toString());
        $this->browser->request('GET', $path);
        self::assertResponseStatusCodeSame(404);

        TestingLogin::asPlayer($this->browser, $importer->id->toString());
        $crawler = $this->browser->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('noindex', (string) $this->browser->getResponse()->getContent());
        $people = $crawler->filter('[data-testid="import-person"]');
        // The most named first: the code that became the player by itself, then the name - a guest, with the
        // importing player's own favourite of that name offered
        self::assertSame(
            [[$partner->code, 'player', 'code', '2', $partner->id->toString()], ['zq pagefriend', 'guest', 'default', '1', '']],
            $people->each(static fn ($person): array => [$person->attr('data-name'), $person->attr('data-choice'), $person->attr('data-how'), $person->attr('data-rows'), $person->attr('data-player')]),
        );
        self::assertStringContainsString('Zq Pagepartner - found by the player code in your file', $people->eq(0)->filter('[data-testid="import-person-state"]')->text());
        self::assertCount(2, $people->eq(0)->filter('[data-testid="add-row"]'));
        self::assertSame([[$friend->id->toString(), 'true']], $people->eq(1)->filter('[data-testid="import-person-suggestion"]')->each(static fn ($suggestion): array => [$suggestion->attr('data-player'), $suggestion->attr('data-exact')]));
        self::assertCount(0, $crawler->filter('[data-testid="import-people-added-note"]'));
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
        $personId = substr((string) $people->eq(1)->attr('id'), strlen('person-'));

        // Without its token: refused before anything is read
        $this->browser->request('POST', $path, ['person' => $personId, 'answer' => $friend->id->toString()]);
        self::assertResponseStatusCodeSame(403);

        // A code nobody has - and a player who was not offered: nothing written, the page again with a 422
        foreach ([['answer' => 'code', 'code' => '#zz9zz9'], ['answer' => $other->id->toString()], ['answer' => 'nonsense']] as $fields) {
            $crawler = $this->browser->request('POST', $path, ['_token' => $token, 'person' => $personId, ...$fields]);
            self::assertResponseStatusCodeSame(422);
            self::assertCount(1, $crawler->filter('#person-' . $personId . ' [data-testid="import-person-refused"]'));
            self::assertSame('guest', $crawler->filter('#person-' . $personId)->attr('data-choice'));
        }

        // Somebody else can not answer for this import - the handler says "not found"
        try {
            self::getContainer()->get(MessageBusInterface::class)->dispatch(new DecideImportPerson($importId, $other->id->toString(), $personId, ImportPersonChoice::Guest));
            self::fail('Another player decided about a person of the import.');
        } catch (DataImportNotFound) {
            self::assertSame('guest', $this->database->fetchOne('SELECT choice FROM data_import_person WHERE id = :id', ['id' => $personId]));
        }

        // The friend offered: taken, a redirect back to the person
        $this->browser->request('POST', $path, ['_token' => $token, 'person' => $personId, 'answer' => $friend->id->toString()]);
        self::assertResponseRedirects($path . '#person-' . $personId);
        $crawler = $this->browser->followRedirect();
        self::assertSame(['player', 'player', $friend->id->toString()], [$crawler->filter('#person-' . $personId)->attr('data-choice'), $crawler->filter('#person-' . $personId)->attr('data-how'), $crawler->filter('#person-' . $personId)->attr('data-player')]);

        // A typed code works too, with or without its sign - and "that is me"
        $this->browser->request('POST', $path, ['_token' => $token, 'person' => $personId, 'answer' => 'code', 'code' => strtoupper($partner->code)]);
        self::assertResponseRedirects();
        self::assertSame($partner->id->toString(), $this->database->fetchOne('SELECT player_id FROM data_import_person WHERE id = :id', ['id' => $personId]));
        $this->browser->request('POST', $path, ['_token' => $token, 'person' => $personId, 'answer' => 'me']);
        self::assertResponseRedirects();
        self::assertSame('me', $this->database->fetchOne('SELECT choice FROM data_import_person WHERE id = :id', ['id' => $personId]));

        // Back to a guest, added - then mapped: the added result is not rewritten, the page says so and offers the
        // consent flow of "Pairs & teams" for the guest that is on the result
        $this->browser->request('POST', $path, ['_token' => $token, 'person' => $personId, 'answer' => 'guest']);
        $this->addAndWork($importId, $importer->id->toString());
        $resultId = $this->rowNow($nameRow)['solving_time_id'];
        $this->browser->request('POST', $path, ['_token' => $token, 'person' => $personId, 'answer' => $friend->id->toString()]);
        $crawler = $this->browser->request('GET', $path);
        self::assertCount(1, $crawler->filter('[data-testid="import-people-added-note"]'));
        $ask = $crawler->filter('#person-' . $personId . ' [data-testid="import-person-ask"]');
        self::assertSame('/en/pairs-and-teams/guests/link', $ask->attr('action'));
        self::assertSame(['g:zq pagefriend', $friend->code], [$ask->filter('input[name="guest"]')->attr('value'), $ask->filter('input[name="code"]')->attr('value')]);
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM puzzling_team_member member INNER JOIN puzzle_solving_time result ON result.puzzling_team_id = member.team_id WHERE result.id = :id AND member.player_id = :friend', ['id' => $resultId, 'friend' => $friend->id->toString()]));

        // The existing flow takes it from there: the friend is asked
        $this->browser->submit($ask->selectButton($ask->filter('button')->text())->form());
        self::assertResponseRedirects();
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM guest_link_request WHERE target_id = :id', ['id' => $friend->id->toString()]));

        // The partner reads their one notification - in every language the page exists in
        TestingLogin::asPlayer($this->browser, $partner->id->toString());
        $crawler = $this->browser->request('GET', '/en/notifications');
        self::assertResponseIsSuccessful();
        $told = $crawler->filter('[data-testid="import-partner-notification"]');
        self::assertCount(1, $told);
        self::assertSame('2', $told->attr('data-results'));
        self::assertStringContainsString('added 2 results with you from a spreadsheet.', $told->text());
        self::assertStringNotContainsString('results.xlsx', (string) $this->browser->getResponse()->getContent());

        // "These are not mine - take me off them": one action on that notification, with its token
        $leave = $told->closest('tr')?->filter('[data-testid="import-partner-leave"]');
        self::assertNotNull($leave);
        self::assertSame('/en/imported-results/' . $importer->id->toString() . '/leave', $leave->attr('action'));
        $this->browser->request('POST', '/en/imported-results/' . $importer->id->toString() . '/leave');
        self::assertResponseStatusCodeSame(403);
        $this->browser->submit($leave->selectButton('These are not mine - take me off them')->form());
        self::assertResponseRedirects('/en/notifications');
        self::assertStringContainsString('You were taken off 2 results.', $this->browser->followRedirect()->text());
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM puzzling_team_member member INNER JOIN puzzle_solving_time result ON result.puzzling_team_id = member.team_id WHERE member.player_id = :partner AND result.player_id = :importer', ['partner' => $partner->id->toString(), 'importer' => $importer->id->toString()]));
        self::assertSame(3, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :importer', ['importer' => $importer->id->toString()]));

        TestingLogin::asPlayer($this->browser, $importer->id->toString());

        // The next file: the name answered before is offered ("still right?"), a guest until one tap says yes - and
        // the partner who took themself off is a guest the page can not tell from an unknown code
        [$next, $nextSheet] = $this->importOf($importer);
        $this->resultRow($next, $nextSheet, 'Zq Page Meadow', 1000, 'Zqpage', '2024-06-01', 6000, ['type' => 'duo', 'people' => ['Zq Pagefriend']]);
        $this->resultRow($next, $nextSheet, 'Zq Page Meadow', 1000, 'Zqpage', '2024-06-02', 6100, ['type' => 'duo', 'people' => ['#' . $partner->code]]);
        $this->pair($next);
        $nextPath = '/en/import/' . $next->id->toString() . '/people';
        $this->database->executeStatement('UPDATE data_import_sheet SET parsed_version = 1, mapping_version = 1 WHERE import_id = :id', ['id' => $next->id->toString()]);
        $crawler = $this->browser->request('GET', $nextPath);
        self::assertSame(['guest', 'guest'], $crawler->filter('[data-testid="import-person"]')->each(static fn ($person): string => (string) $person->attr('data-choice')));
        $offer = $crawler->filter('[data-testid="import-person-remembered"]');
        self::assertSame(['player', $friend->id->toString()], [$offer->attr('data-remembered'), $offer->attr('data-player')]);
        self::assertStringContainsString('Last time “Zq Pagefriend” was Zq Pagefriend - still right?', $offer->text());
        $this->browser->submit($offer->selectButton('Yes, still right')->form());
        self::assertResponseRedirects();
        $crawler = $this->browser->followRedirect();
        self::assertCount(0, $crawler->filter('[data-testid="import-person-remembered"]'));
        self::assertSame($friend->id->toString(), $crawler->filter('[data-testid="import-person"][data-name="Zq Pagefriend"]')->attr('data-player'));

        foreach (['cs', 'de', 'es', 'fr', 'ja'] as $locale) {
            $crawler = $this->browser->request('GET', '/' . $locale . '/import/' . $importId . '/people');
            self::assertResponseIsSuccessful($locale);
            self::assertCount(2, $crawler->filter('[data-testid="import-person"]'), $locale);
            self::assertStringNotContainsString('data_import.', (string) $crawler->filter('[data-testid="import-people-page"]')->text(), $locale);
        }
    }
}
