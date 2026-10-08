<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\RoundBadgeColor;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The round's name is shown on a badge in its colour wherever the round appears, the text colour picked for contrast
 * (RoundBadgeColor). The round form asks only for the colour, previews the badge and explains it; the organiser's
 * round list shows the badge exactly as the event pages do.
 */
final class CompetitionRoundBadgeTest extends WebTestCase
{
    public function testAddFormExplainsTheColourAndPreviewsTheBadgeWithoutATextColourField(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/add-event-round/' . CompetitionFixture::COMPETITION_UNAPPROVED);
        $this->assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('[name="competition_round_form[badgeTextColor]"]'));
        self::assertSame('', (string) $crawler->filter('input[name="competition_round_form[badgeBackgroundColor]"]')->attr('value'));
        self::assertStringContainsString(
            'The text color is always chosen for you',
            $crawler->filter('#competition_round_form_badgeBackgroundColor_help')->text(),
        );

        $form = $crawler->filter('form[data-controller="round-badge-preview"]');
        self::assertCount(1, $form);
        // The event has no round yet: the new one gets the first palette colour
        self::assertSame(RoundBadgeColor::PALETTE[0], $form->attr('data-round-badge-preview-automatic-color-value'));
        self::assertSame(RoundBadgeColor::ROUND_FORM_DEFAULT, $form->attr('data-round-badge-preview-round-form-default-value'));

        $badge = $crawler->filter('[data-round-badge-preview-target="badge"]');
        self::assertSame('Round name', $badge->text());
        self::assertStringContainsString('background-color: ' . RoundBadgeColor::PALETTE[0], (string) $badge->attr('style'));
        self::assertStringContainsString('color: ' . RoundBadgeColor::text(RoundBadgeColor::PALETTE[0]), (string) $badge->attr('style'));
        self::assertNull($crawler->filter('[data-round-badge-preview-target="automaticNote"]')->attr('hidden'));
        self::assertCount(1, $crawler->filter('input[name="competition_round_form[name]"][data-round-badge-preview-target="name"]'));
        self::assertCount(1, $crawler->filter('input[name="competition_round_form[badgeBackgroundColor]"][data-round-badge-preview-target="color"][data-controller="colorpicker"][data-colorpicker-clear-label-value="Automatic color"]'));
    }

    public function testRoundAddedWithoutAColourGetsTheAutomaticOne(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/add-event-round/' . CompetitionFixture::COMPETITION_UNAPPROVED);
        $browser->submitForm('Add Round', [
            'competition_round_form[name]' => 'Automatic Round',
            'competition_round_form[minutesLimit]' => '60',
            'competition_round_form[startsAt]' => '24.10.2030 10:00',
        ]);
        $this->assertResponseRedirects();

        $round = $this->roundNamed('Automatic Round');
        self::assertNull($round->badgeBackgroundColor);
        self::assertNull($round->badgeTextColor);

        // The organiser's list shows it as the event pages do: the event's first round, first palette colour
        $crawler = $browser->followRedirect();
        $badge = $this->badgeOf($crawler, 'Automatic Round');
        self::assertStringContainsString('background-color: ' . RoundBadgeColor::PALETTE[0], (string) $badge->attr('style'));
    }

    public function testRoundAddedWithAColourKeepsItAndTheMatchingTextColour(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/add-event-round/' . CompetitionFixture::COMPETITION_UNAPPROVED);
        $browser->submitForm('Add Round', [
            'competition_round_form[name]' => 'Navy Round',
            'competition_round_form[minutesLimit]' => '60',
            'competition_round_form[startsAt]' => '24.10.2030 10:00',
            'competition_round_form[badgeBackgroundColor]' => '#000075',
        ]);
        $this->assertResponseRedirects();

        $round = $this->roundNamed('Navy Round');
        self::assertSame('#000075', $round->badgeBackgroundColor);
        self::assertSame('#ffffff', $round->badgeTextColor);
    }

    public function testEditFormPreviewsTheRoundsBadgeAndSavesWithoutATextColour(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        // Final Round: yellow, the second round of WJPC 2024
        $crawler = $browser->request('GET', '/en/edit-event-round/' . CompetitionRoundFixture::ROUND_WJPC_FINAL);
        $this->assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('[name="competition_round_form[badgeTextColor]"]'));
        self::assertSame('#ffc107', $crawler->filter('input[name="competition_round_form[badgeBackgroundColor]"]')->attr('value'));
        self::assertSame(RoundBadgeColor::PALETTE[1], $crawler->filter('form[data-controller="round-badge-preview"]')->attr('data-round-badge-preview-automatic-color-value'));

        $badge = $crawler->filter('[data-round-badge-preview-target="badge"]');
        self::assertSame('Final Round', $badge->text());
        self::assertStringContainsString('background-color: #ffc107', (string) $badge->attr('style'));
        self::assertStringContainsString('color: #000000', (string) $badge->attr('style'));
        self::assertNotNull($crawler->filter('[data-round-badge-preview-target="automaticNote"]')->attr('hidden'));

        $browser->submitForm('Save Changes', ['competition_round_form[badgeBackgroundColor]' => '#800000']);
        $this->assertResponseRedirects();

        $round = $this->roundNamed('Final Round', CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertSame('#800000', $round->badgeBackgroundColor);
        self::assertSame('#ffffff', $round->badgeTextColor);

        // Emptied: back to the automatic colour
        $browser->request('GET', '/en/edit-event-round/' . CompetitionRoundFixture::ROUND_WJPC_FINAL);
        $browser->submitForm('Save Changes', ['competition_round_form[badgeBackgroundColor]' => '']);
        $this->assertResponseRedirects();

        $round = $this->roundNamed('Final Round', CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertNull($round->badgeBackgroundColor);
        self::assertNull($round->badgeTextColor);
    }

    public function testSomethingThatIsNoColourIsAFormError(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/edit-event-round/' . CompetitionRoundFixture::ROUND_WJPC_FINAL);
        $browser->submitForm('Save Changes', ['competition_round_form[badgeBackgroundColor]' => 'red']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'Enter a colour like #1e88e5');
        self::assertSame('#ffc107', $this->roundNamed('Final Round', CompetitionFixture::COMPETITION_WJPC_2024)->badgeBackgroundColor);
    }

    public function testTheOldFormDefaultReadsAsNoColour(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $this->entityManager()->getConnection()->executeStatement(
            "UPDATE competition_round SET badge_background_color = '#fe696a', badge_text_color = '#ffffff' WHERE id = :id",
            ['id' => CompetitionRoundFixture::ROUND_WJPC_FINAL],
        );

        $crawler = $browser->request('GET', '/en/edit-event-round/' . CompetitionRoundFixture::ROUND_WJPC_FINAL);
        $this->assertResponseIsSuccessful();
        self::assertSame('', (string) $crawler->filter('input[name="competition_round_form[badgeBackgroundColor]"]')->attr('value'));
        self::assertStringContainsString('background-color: ' . RoundBadgeColor::PALETTE[1], (string) $crawler->filter('[data-round-badge-preview-target="badge"]')->attr('style'));
        self::assertNull($crawler->filter('[data-round-badge-preview-target="automaticNote"]')->attr('hidden'));

        $browser->submitForm('Save Changes');
        $this->assertResponseRedirects();

        $round = $this->roundNamed('Final Round', CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertNull($round->badgeBackgroundColor);
        self::assertNull($round->badgeTextColor);
    }

    public function testRoundListShowsTheBadgeAsTheEventPagesDoAndEveryCategory(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/manage-event-rounds/' . CompetitionFixture::COMPETITION_WJPC_2024);
        $this->assertResponseIsSuccessful();

        // Computed, never the stored text colour - white reads best on this blue (APCA)
        $badge = $this->badgeOf($crawler, 'Qualification Round');
        self::assertStringContainsString('background-color: #007bff', (string) $badge->attr('style'));
        self::assertStringContainsString('color: ' . RoundBadgeColor::text('#007bff'), (string) $badge->attr('style'));
        self::assertSame('#ffffff', RoundBadgeColor::text('#007bff'));

        // Solo rounds say so too, like pair and team rounds
        $categories = $crawler->filter('[data-round-category]')->each(static fn (Crawler $node): string => $node->text());
        self::assertSame(['Solo', 'Solo'], $categories);
    }

    private function badgeOf(Crawler $crawler, string $roundName): Crawler
    {
        $badge = $crawler->filter('[data-round-badge]')->reduce(static fn (Crawler $node): bool => $node->text() === $roundName);
        self::assertCount(1, $badge, $roundName);

        return $badge;
    }

    private function roundNamed(string $name, string $competitionId = CompetitionFixture::COMPETITION_UNAPPROVED): CompetitionRound
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();

        $round = $entityManager->getRepository(CompetitionRound::class)->findOneBy([
            'name' => $name,
            'competition' => $competitionId,
        ]);
        self::assertNotNull($round);

        return $round;
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
