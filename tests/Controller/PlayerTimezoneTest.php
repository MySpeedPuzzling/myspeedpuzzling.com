<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The player's time zone (docs/features/player-timezone.md): detected at sign-up, chosen in the settings, asked for
 * by the "Complete your profile" panel on the player's own profile.
 */
final class PlayerTimezoneTest extends WebTestCase
{
    public function testTheRegistrationFormCarriesTheBrowsersTimezone(): void
    {
        $browser = self::createClient();
        $browser->setServerParameter('REMOTE_ADDR', sprintf('198.51.100.%d', random_int(1, 254)));

        $crawler = $browser->request('GET', '/register');
        $form = $crawler->selectButton('Create account')->form();
        $name = $form->getName();

        $hidden = $crawler->filter(sprintf('input[type="hidden"][name="%s[timezone]"]', $name));
        self::assertCount(1, $hidden);
        self::assertSame('timezone-detect', $hidden->attr('data-controller'));
        self::assertSame('', (string) $hidden->attr('value'), 'Filled in the browser, never by the server');

        $terms = $form[$name . '[termsAccepted]'];
        self::assertInstanceOf(ChoiceFormField::class, $terms);
        $terms->tick();

        $browser->submit($form, [
            $name . '[email]' => sprintf('register.timezone+%s@example.com', bin2hex(random_bytes(4))),
            $name . '[plainPassword]' => 'a-properly-long-passphrase',
            $name . '[timezone]' => 'America/Toronto',
        ]);

        self::assertResponseRedirects('/welcome');

        $token = $browser->getContainer()->get(TokenStorageInterface::class)->getToken();
        self::assertNotNull($token);
        $player = $browser->getContainer()->get(PlayerRepository::class)->findByUserId($token->getUserIdentifier());
        self::assertNotNull($player);
        self::assertSame('America/Toronto', $player->timezone);
    }

    public function testTheSettingsFieldIsGroupedByRegionWithAStableAnchor(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/edit-profile');
        self::assertResponseIsSuccessful();

        $field = $crawler->filter('.timezone-field[data-controller="timezone-select"]');
        self::assertCount(1, $field);
        $aliases = json_decode((string) $field->attr('data-timezone-select-aliases-value'), true);
        self::assertIsArray($aliases);
        self::assertSame('Asia/Kolkata', $aliases['Asia/Calcutta'] ?? null, 'Old names browsers report map to the listed zone');

        $select = $field->filter('select#timezone[name="edit_profile_form[timezone]"]');
        self::assertCount(1, $select);
        self::assertCount(1, $crawler->filter('label[for="timezone"]'));
        self::assertSame('Central European Time (Prague)', $select->filter('optgroup[label="Europe"] option[value="Europe/Prague"]')->text());
        self::assertCount(0, $select->filter('option[selected]'), 'Nothing saved, nothing preselected by the server - the browser suggests');
        self::assertCount(1, $field->filter('[data-timezone-select-target="detected"][hidden]'));
    }

    public function testTheSettingsSaveTheChosenTimezone(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/edit-profile');
        $form = $crawler->filter('form[name="edit_profile_form"]')->form();
        $browser->submit($form, ['edit_profile_form[timezone]' => 'Europe/Prague']);

        self::assertResponseRedirects();
        self::assertSame('Europe/Prague', $this->storedTimezone($browser, PlayerFixture::PLAYER_REGULAR));

        $crawler = $browser->request('GET', '/en/edit-profile');
        self::assertSame('Europe/Prague', $crawler->filter('select#timezone option[selected]')->attr('value'));
    }

    public function testAnUnknownTimezoneIsAFormError(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/edit-profile');
        $values = $crawler->filter('form[name="edit_profile_form"]')->form()->getPhpValues()['edit_profile_form'] ?? null;
        self::assertIsArray($values);
        $values['timezone'] = 'Mars/Olympus_Mons';

        $browser->request('POST', '/en/edit-profile', ['edit_profile_form' => $values]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Europe/London', $this->storedTimezone($browser, PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testTheFinishProfilePageKeepsTheTimezone(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/finish-profile');
        self::assertCount(1, $crawler->filter('details select#timezone'));

        $browser->submit($crawler->selectButton('Save')->form());

        self::assertResponseRedirects('/en/hub');
        self::assertSame('Europe/London', $this->storedTimezone($browser, PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testTheWelcomeScreenSendsTheTimezoneOnlyWhileThereIsNone(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/welcome');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-controller="timezone-detect"][data-timezone-detect-url-value="/detect-timezone"]'));

        $browser->request('POST', '/detect-timezone', ['timezone' => 'Europe/Prague']);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('Europe/Prague', $this->storedTimezone($browser, PlayerFixture::PLAYER_REGULAR));

        $crawler = $browser->request('GET', '/welcome');
        self::assertCount(0, $crawler->filter('[data-controller="timezone-detect"]'));

        // Detection never changes a zone that is there
        $browser->request('POST', '/detect-timezone', ['timezone' => 'Asia/Tokyo']);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('Europe/Prague', $this->storedTimezone($browser, PlayerFixture::PLAYER_REGULAR));
    }

    public function testGuestsCannotPostATimezone(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/detect-timezone', ['timezone' => 'Europe/Prague']);

        self::assertResponseRedirects();
    }

    public function testTheOwnProfileAsksForAMissingTimezone(): void
    {
        $browser = self::createClient();
        $this->notANewcomer($browser, PlayerFixture::PLAYER_REGULAR);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);
        self::assertResponseIsSuccessful();

        $panel = $crawler->filter('.profile-completion.mb-3');
        self::assertCount(1, $panel);
        self::assertStringContainsString('Complete your profile.', $panel->text());
        self::assertSame('profile_completion', $panel->attr('data-dismiss-hint-type-value'));
        self::assertSame('/en/edit-profile#timezone', $panel->filter('a.profile-completion-item')->attr('href'));
        self::assertStringContainsString('Add your time zone', $panel->filter('a.profile-completion-item')->text());
        self::assertCount(1, $panel->filter('button.profile-completion-close[data-action="dismiss-hint#dismiss"]'));

        // Somebody else's profile is about them
        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertCount(0, $crawler->filter('.profile-completion'));
    }

    public function testNothingIsAskedOnceTheTimezoneIsKnown(): void
    {
        $browser = self::createClient();
        $this->notANewcomer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.profile-completion'));
    }

    public function testGuestsAreNotAsked(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.profile-completion'));
    }

    public function testANewcomersProfileLeavesItToTheGettingStartedChecklist(): void
    {
        $browser = self::createClient();
        // Fixture players registered just now: the newcomer checklist is the one card on their profile
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);

        self::assertCount(1, $crawler->filter('.getting-started'));
        self::assertCount(0, $crawler->filter('.profile-completion'));
    }

    public function testItWaitsWhileTheCountryNudgeAsks(): void
    {
        $browser = self::createClient();
        $this->notANewcomer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->getContainer()->get(Connection::class)
            ->executeStatement('UPDATE player SET country = NULL WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);
        self::assertCount(1, $crawler->filter('.players-nudge'));
        self::assertCount(0, $crawler->filter('.profile-completion'), 'One ask at a time');

        $browser->request('POST', '/en/dismiss-hint', ['type' => 'players_country_nudge']);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);
        self::assertCount(0, $crawler->filter('.players-nudge'));
        self::assertCount(1, $crawler->filter('.profile-completion'));
    }

    public function testOnceDismissedItIsGoneForGood(): void
    {
        $browser = self::createClient();
        $this->notANewcomer($browser, PlayerFixture::PLAYER_REGULAR);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', '/en/dismiss-hint', ['type' => 'profile_completion']);
        self::assertResponseStatusCodeSame(204);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);
        self::assertCount(0, $crawler->filter('.profile-completion'));
    }

    private function storedTimezone(KernelBrowser $browser, string $playerId): mixed
    {
        return $browser->getContainer()->get(Connection::class)
            ->fetchOne('SELECT timezone FROM player WHERE id = :id', ['id' => $playerId]);
    }

    private function notANewcomer(KernelBrowser $browser, string $playerId): void
    {
        $browser->getContainer()->get(Connection::class)
            ->executeStatement("UPDATE player SET registered_at = NOW() - INTERVAL '60 days' WHERE id = :id", ['id' => $playerId]);
    }
}
