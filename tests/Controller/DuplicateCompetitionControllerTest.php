<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Duplicate" (docs/features/competitions-management/README.md "Duplicating an event"): signed in, COMPETITION_EDIT,
 * the stateless CSRF token - a 303 to the new draft's edit page with a flash; offered in the ⋯ menu and on the edit page.
 */
final class DuplicateCompetitionControllerTest extends WebTestCase
{
    private const string PATH = '/en/duplicate-event/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP;

    public function testAGuestIsSentToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('POST', self::PATH, ['_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));
    }

    public function testAPlayerWhoCannotEditTheEventIsRefused(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $before = $this->copies();

        $browser->request('POST', self::PATH, ['_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->copies());
    }

    public function testARequestFromAnotherSiteIsRefused(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $before = $this->copies();

        $browser->request('POST', self::PATH, ['_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->copies());
    }

    public function testTheOrganiserGetsADraftCopyAndLandsOnItsEditPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $before = $this->copies();

        $browser->request('POST', self::PATH, ['_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(303);
        self::assertSame($before + 1, $this->copies());

        $copyId = self::text(self::getContainer()->get(Connection::class)->fetchOne(
            "SELECT id FROM competition WHERE name = 'Results Cup' AND id <> :id ORDER BY id DESC LIMIT 1",
            ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP],
        ));
        self::assertResponseRedirects('/en/edit-event/' . $copyId);
        self::assertSame([$this->trans('event_duplicate.flash')], $this->flashes($browser));

        $browser->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-duplicate-form][action="/en/duplicate-event/' . $copyId . '"]');
    }

    public function testTheManageMenuOffersIt(): void
    {
        $browser = self::createClient();
        // On the team of the organization the series is under
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $browser->request('GET', '/en/event-actions/competition/' . OrganizationFixture::EDITION_LANTERN_1);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/en/duplicate-event/' . OrganizationFixture::EDITION_LANTERN_1 . '"] button[data-duplicate]');
    }

    private function copies(): int
    {
        return self::number(self::getContainer()->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM competition WHERE name = 'Results Cup'"));
    }

    /**
     * @return list<mixed>
     */
    private function flashes(KernelBrowser $browser): array
    {
        /** @var Session $session */
        $session = $browser->getRequest()->getSession();

        return array_values($session->getFlashBag()->peek('success'));
    }

    private function trans(string $key): string
    {
        return self::getContainer()->get(TranslatorInterface::class)->trans($key);
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private static function number(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
