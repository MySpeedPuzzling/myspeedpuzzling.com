<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * The add/edit organization forms (docs/features/organizations/README.md "Forms"): any signed-in player adds one
 * (waiting for approval, or a draft through "Save as draft"); its team edits it - a rename keeps the slug, the "URL"
 * field changes it; a refused submit answers 422 and keeps what was typed and the chosen logo; the team list shows on
 * the edit page only.
 */
final class OrganizationFormsTest extends WebTestCase
{
    private const string ADD = '/en/add-organization';
    private const string EDIT_RIVERBEND = '/en/edit-organization/' . OrganizationFixture::ORGANIZATION_RIVERBEND;

    public function testAGuestIsSentToSignIn(): void
    {
        $browser = self::createClient();
        $browser->request('GET', self::ADD);

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $browser->getResponse()->headers->get('Location'));
    }

    public function testAddingAnOrganizationSubmitsItForApproval(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', self::ADD);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('button[name="organization_form[saveDraft]"]'), 'the secondary "Save as draft"');
        self::assertCount(0, $crawler->filter('input[name="organization_form[slug]"]'), 'a new organization gets its URL from its name');

        $form = $crawler->selectButton('Submit for approval')->form([
            'organization_form[name]' => 'Willowmere Puzzle Society',
            'organization_form[shortName]' => 'WPS',
            'organization_form[kind]' => 'club',
            'organization_form[countryCode]' => self::countryOptionValue($crawler, 'Canada'),
            'organization_form[region]' => 'Willowmere',
            'organization_form[about]' => "Puzzle evenings by the lake.\nEveryone welcome.",
            'organization_form[website]' => 'https://willowmere-puzzles.example',
            'organization_form[socialLinks]' => "https://www.instagram.com/willowmerepuzzles\n\n  https://discord.gg/willowmere  \n",
            'organization_form[maintainers]' => PlayerFixture::PLAYER_WITH_FAVORITES,
        ]);
        $browser->submit($form);

        self::assertResponseRedirects('/en/organizations/willowmere-puzzle-society');

        $row = self::organizationRow('willowmere-puzzle-society');
        self::assertSame('Willowmere Puzzle Society', $row['name']);
        self::assertSame('WPS', $row['short_name']);
        self::assertSame('club', $row['kind']);
        self::assertSame('ca', $row['country_code']);
        self::assertSame('Willowmere', $row['region']);
        self::assertSame('https://willowmere-puzzles.example', $row['website']);
        self::assertIsString($row['social_links']);
        self::assertSame(['https://www.instagram.com/willowmerepuzzles', 'https://discord.gg/willowmere'], json_decode($row['social_links'], true));
        self::assertNull($row['approved_at'], 'waiting for approval');
        self::assertFalse((bool) $row['is_draft']);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $row['added_by_player_id']);
        self::assertIsString($row['id']);
        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES], self::maintainerIds($row['id']));

        // Its page is reachable for everyone at once (not indexed while it waits)
        $browser->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Willowmere Puzzle Society');
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
    }

    public function testAnAdminsOrganizationIsApprovedAtOnce(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', self::ADD);

        $form = $crawler->selectButton('Submit for approval')->form([
            'organization_form[name]' => 'Larkspur Puzzle Guild',
        ]);
        $browser->submit($form);

        self::assertResponseRedirects('/en/organizations/larkspur-puzzle-guild');
        self::assertQueuedEmailCount(0);
        self::assertNotNull(self::organizationRow('larkspur-puzzle-guild')['approved_at']);
    }

    public function testSaveAsDraftKeepsItForTheTeam(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', self::ADD);

        $browser->submit($crawler->selectButton('Save as draft')->form([
            'organization_form[name]' => 'Larkspur Jigsaw Circle',
        ]));

        self::assertResponseRedirects('/en/organizations/larkspur-jigsaw-circle');
        self::assertTrue((bool) self::organizationRow('larkspur-jigsaw-circle')['is_draft']);

        // Its creator sees it, a guest does not
        $browser->followRedirect();
        self::assertResponseIsSuccessful();
        $browser->getCookieJar()->clear();
        $browser->request('GET', '/en/organizations/larkspur-jigsaw-circle');
        self::assertResponseStatusCodeSame(404);
    }

    public function testARefusedSubmitAnswers422AndKeepsTheInputAndTheLogo(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        // The kept logo lives in the kernel's storage - one kernel for both submits
        $browser->disableReboot();
        $crawler = $browser->request('GET', self::ADD);

        $form = $crawler->selectButton('Submit for approval')->form([
            'organization_form[name]' => 'Thistle Hill Puzzlers',
            'organization_form[socialLinks]' => "https://www.instagram.com/thistlehill\nnot a web address",
        ]);
        self::attachLogo($form);
        $crawler = $browser->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('not a web address', $crawler->filter('.invalid-feedback, .form-error-message')->text());
        self::assertStringContainsString('is not a web address (http:// or https://)', $crawler->filter('form')->text());
        self::assertSame("https://www.instagram.com/thistlehill\nnot a web address", $crawler->filter('textarea[name="organization_form[socialLinks]"]')->text(null, false));
        self::assertSame('Thistle Hill Puzzlers', $crawler->filter('input[name="organization_form[name]"]')->attr('value'));
        $token = $crawler->filter('input[name="photo_stash[logo]"]')->attr('value');
        self::assertNotNull($token, 'the chosen logo is kept');
        self::assertNull(self::organizationRowOrNull('thistle-hill-puzzlers'));

        // Fixed: saved, with the kept logo
        $browser->submit($crawler->selectButton('Submit for approval')->form([
            'organization_form[socialLinks]' => 'https://www.instagram.com/thistlehill',
        ]));

        self::assertResponseRedirects('/en/organizations/thistle-hill-puzzlers');
        $logo = self::organizationRow('thistle-hill-puzzlers')['logo'];
        self::assertIsString($logo);
        self::assertStringStartsWith('organizations/', $logo);
    }

    public function testAtMostTenSocialLinksAndANameAreRequired(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', self::ADD);

        $links = implode("\n", array_map(static fn (int $i): string => 'https://puzzles-' . $i . '.example', range(1, 11)));
        $crawler = $browser->submit($crawler->selectButton('Submit for approval')->form([
            'organization_form[name]' => '',
            'organization_form[socialLinks]' => $links,
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Add at most 10 links.', $crawler->filter('form')->text());
        self::assertCount(1, $crawler->filter('input[name="organization_form[name]"].is-invalid'));

        // A link typed twice counts once: ten different links pass
        $tenTwice = implode("\n", array_map(static fn (int $i): string => 'https://puzzles-' . min($i, 10) . '.example', range(1, 11)));
        $browser->submit($crawler->selectButton('Submit for approval')->form([
            'organization_form[name]' => 'Ten Links Puzzle Club',
            'organization_form[socialLinks]' => $tenTwice,
        ]));

        self::assertResponseRedirects('/en/organizations/ten-links-puzzle-club');
    }

    public function testTheEditPageIsPrefilledAndListsTheTeamForTheTeamOnly(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $browser->request('GET', self::EDIT_RIVERBEND);

        self::assertResponseIsSuccessful();
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND_NAME, $crawler->filter('input[name="organization_form[name]"]')->attr('value'));
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG, $crawler->filter('input[name="organization_form[slug]"]')->attr('value'));
        self::assertSame('localhost/en/organizations/', trim($crawler->filter('[data-slug-prefix]')->text()));
        self::assertSame(
            "https://www.instagram.com/riverbendjigsaw\nhttps://discord.gg/riverbendjigsaw",
            $crawler->filter('textarea[name="organization_form[socialLinks]"]')->text(null, false),
        );
        self::assertSame('association', $crawler->filter('select[name="organization_form[kind]"] option[selected]')->attr('value'));
        self::assertCount(0, $crawler->filter('button[name="organization_form[saveDraft]"]'));

        $team = $crawler->filter('[data-org-team-member]')->each(static fn (Crawler $member): string => (string) $member->attr('data-org-team-member'));
        self::assertSame([self::playerCode(PlayerFixture::PLAYER_WITH_STRIPE), self::playerCode(PlayerFixture::PLAYER_WITH_FAVORITES)], $team, 'the creator first, then the maintainers');
        self::assertCount(1, $crawler->filter('[data-org-team-member] .badge'), 'the creator is marked');
    }

    public function testSomeoneOffTheTeamCannotEdit(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::EDIT_RIVERBEND);

        self::assertResponseStatusCodeSame(403);
    }

    public function testARenameKeepsTheUrl(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', self::EDIT_RIVERBEND);

        $browser->submit($crawler->selectButton('Save changes')->form([
            'organization_form[name]' => 'Riverbend Valley Jigsaw Association',
            'organization_form[region]' => 'Upper Riverbend',
        ]));

        self::assertResponseRedirects('/en/organizations/' . OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG);
        $row = self::organizationRow(OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG);
        self::assertSame('Riverbend Valley Jigsaw Association', $row['name']);
        self::assertSame('Upper Riverbend', $row['region']);
        // The team stays as it was
        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES], self::maintainerIds(OrganizationFixture::ORGANIZATION_RIVERBEND));
    }

    public function testTheUrlFieldChangesTheUrlAndReturnsBackWhereOpened(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', self::EDIT_RIVERBEND . '?return=/en/you-organize');

        $browser->submit($crawler->selectButton('Save changes')->form([
            'organization_form[slug]' => 'Riverbend Jigsaw',
        ]));

        self::assertResponseRedirects('/en/you-organize');
        self::assertNotNull(self::organizationRowOrNull('riverbend-jigsaw'));
    }

    public function testATakenUrlIsAFormError(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', self::EDIT_RIVERBEND);

        $crawler = $browser->submit($crawler->selectButton('Save changes')->form([
            'organization_form[slug]' => OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT_SLUG,
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('This URL is already taken.', $crawler->filter('form')->text());
        self::assertNotNull(self::organizationRowOrNull(OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG), 'nothing changed');
    }

    public function testTheEditPageSaysWhyItIsNotPublicYet(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $browser->request('GET', '/en/edit-organization/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING);
        self::assertSelectorExists('[data-org-pending]');

        $browser->request('GET', '/en/edit-organization/' . OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT);
        self::assertSelectorExists('[data-org-draft-note]');

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            "UPDATE organization SET rejected_at = NOW(), rejection_reason = 'Duplicate of another organization' WHERE id = :id",
            ['id' => OrganizationFixture::ORGANIZATION_MAPLE_PENDING],
        );

        $browser->request('GET', '/en/edit-organization/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING);
        self::assertSelectorTextContains('[data-org-rejected]', 'Duplicate of another organization');
    }

    /**
     * The country select's option values are positions (the most common countries are listed twice)
     */
    private static function countryOptionValue(Crawler $crawler, string $country): string
    {
        $option = $crawler->filter('select[name="organization_form[countryCode]"] option')
            ->reduce(static fn (Crawler $option): bool => trim($option->text()) === $country)
            ->first();

        return (string) $option->attr('value');
    }

    private static function attachLogo(Form $form): void
    {
        $path = sys_get_temp_dir() . '/' . uniqid('logo-', true) . '.jpg';
        $image = imagecreatetruecolor(300, 200);
        assert($image !== false);
        imagejpeg($image, $path);

        $field = $form['organization_form[logo]'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($path);
    }

    /**
     * @return array<string, mixed>
     */
    private static function organizationRow(string $slug): array
    {
        $row = self::organizationRowOrNull($slug);
        self::assertNotNull($row, 'organization ' . $slug);

        return $row;
    }

    /**
     * @return null|array<string, mixed>
     */
    private static function organizationRowOrNull(string $slug): null|array
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $row = $connection->fetchAssociative('SELECT * FROM organization WHERE slug = :slug', ['slug' => $slug]);

        return $row === false ? null : $row;
    }

    /**
     * @return list<string>
     */
    private static function maintainerIds(string $organizationId): array
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        /** @var list<string> */
        return $connection->fetchFirstColumn(
            'SELECT player_id FROM organization_maintainer WHERE organization_id = :id ORDER BY player_id',
            ['id' => $organizationId],
        );
    }

    private static function playerCode(string $playerId): string
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        $code = $connection->fetchOne('SELECT code FROM player WHERE id = :id', ['id' => $playerId]);
        self::assertIsString($code);

        return $code;
    }
}
