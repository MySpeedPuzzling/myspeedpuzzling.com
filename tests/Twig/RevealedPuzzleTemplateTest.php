<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Services\SecretRevealPreview;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * What the organiser confirms (competition/_revealed_puzzle.html.twig - the round edit, a removal, a round deletion):
 * when each secret puzzle comes out and how far, never a made-up date for a reveal that waits for a manual one - and
 * "N minutes after the round starts" reads right in every language.
 *
 * @phpstan-import-type RevealedPuzzle from SecretRevealPreview
 */
final class RevealedPuzzleTemplateTest extends KernelTestCase
{
    private const string TIMEZONE = 'Europe/Vienna';

    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get(Environment::class);
    }

    public function testARoundEditSaysWhenInsteadOfWhen(): void
    {
        $previous = new DateTimeImmutable('2030-10-24 08:15:00 UTC');

        $earlier = $this->render($this->item(revealsAt: new DateTimeImmutable('2030-10-24 08:10:00 UTC')), $previous);
        self::assertStringContainsString('Secret A – on Thursday, October 24, 2030 at 10:10', self::text($earlier));
        self::assertStringContainsString('instead of Thursday, October 24, 2030 at 10:15', $earlier);

        $rightAway = $this->render($this->item(revealsAt: null), $previous);
        self::assertStringContainsString('Secret A – right away', self::text($rightAway));
        self::assertStringNotContainsString('instead of', $rightAway);
    }

    public function testARemovalSaysNoWhen(): void
    {
        $html = $this->render($this->item(revealsAt: null), null);

        self::assertStringNotContainsString('data-revealed-when', $html);
        self::assertStringNotContainsString('right away', $html);
    }

    public function testHowFarEachPuzzleComesOut(): void
    {
        $until = new DateTimeImmutable('2030-10-25 08:00:00 UTC');

        $everywhere = $this->render($this->item(), null);
        self::assertStringNotContainsString('data-revealed-scope', $everywhere);

        self::assertStringContainsString(
            'on this event only – another round keeps it hidden everywhere else until Friday, October 25, 2030 at 10:00',
            $this->render($this->item(scope: SecretRevealPreview::SCOPE_EVENT, hiddenElsewhereUntil: $until), null),
        );
        self::assertStringContainsString(
            'its name everywhere – elsewhere its picture stays hidden until Friday, October 25, 2030 at 10:00',
            $this->render($this->item(scope: SecretRevealPreview::SCOPE_NAME_EVERYWHERE, hiddenElsewhereUntil: $until), null),
        );
    }

    /**
     * A public catalogue puzzle the round keeps secret on its event pages only (SecretRevealPreview::SCOPE_EVENT with no
     * until): it comes out here - and never reads as if it came out everywhere
     */
    public function testAPuzzleSecretOnTheEventPageOnlySaysItWasPublicElsewhere(): void
    {
        $previous = new DateTimeImmutable('2030-10-24 08:15:00 UTC');

        foreach ([null, $previous] as $roundEdit) {
            $html = $this->render($this->item(scope: SecretRevealPreview::SCOPE_EVENT), $roundEdit);

            self::assertStringContainsString('on this event page only – it was public elsewhere on MySpeedPuzzling already', self::text($html));
            self::assertStringNotContainsString('another round', $html);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        foreach (['en', 'cs', 'de', 'es', 'fr', 'ja'] as $locale) {
            yield $locale => [$locale];
        }
    }

    #[DataProvider('locales')]
    public function testEveryScopeTextExistsInEveryLanguage(string $locale): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);

        foreach (['only_this_event', 'only_this_event_manual', 'only_this_event_public_elsewhere', 'name_everywhere_image_elsewhere', 'name_everywhere_image_elsewhere_manual'] as $key) {
            self::assertTrue(
                $translator->getCatalogue($locale)->defines('competition.reveal.confirm.' . $key),
                "{$locale}: competition.reveal.confirm.{$key}",
            );
        }
    }

    /**
     * The "Change" links' accessible name contains the visible word in every language (WCAG 2.5.3 label in name), and the
     * texts of the reveal changes exist in every language
     */
    #[DataProvider('locales')]
    public function testTheChangeLinkAndRevealTextsExistInEveryLanguage(string $locale): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $catalogue = $translator->getCatalogue($locale);

        foreach (['competition.reveal.change_delay', 'competition.reveal.change_delay_label', 'competition.reveal.flash.automatic_changed', 'competition.reveal.form.revealed_earlier_at'] as $key) {
            self::assertTrue($catalogue->defines($key), "{$locale}: {$key}");
        }

        $visible = $translator->trans('competition.reveal.change_delay', [], null, $locale);
        self::assertStringContainsString(
            mb_strtolower($visible),
            mb_strtolower($translator->trans('competition.reveal.change_delay_label', [], null, $locale)),
            $locale,
        );
    }

    /**
     * Another round waits for its manual reveal: stored as the far future, said in words
     */
    #[DataProvider('manualHoldTimezones')]
    public function testAHoldUntilAManualRevealIsNeverADate(string $timezone): void
    {
        $never = new DateTimeImmutable(CompetitionRoundPuzzle::HIDDEN_UNTIL_REVEALED)->setTimezone(new \DateTimeZone($timezone));

        $event = $this->render($this->item(scope: SecretRevealPreview::SCOPE_EVENT, hiddenElsewhereUntil: $never), null);
        self::assertStringContainsString('on this event only – everywhere else it stays hidden until it is revealed manually', $event);
        self::assertStringNotContainsString('9999', $event);

        $name = $this->render($this->item(scope: SecretRevealPreview::SCOPE_NAME_EVERYWHERE, hiddenElsewhereUntil: $never), null);
        self::assertStringContainsString('its name everywhere – elsewhere its picture stays hidden until it is revealed manually', $name);
        self::assertStringNotContainsString('9999', $name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function manualHoldTimezones(): iterable
    {
        yield 'UTC' => ['UTC'];
        yield 'west of UTC' => ['America/Chicago'];
        yield 'east of UTC' => ['Asia/Tokyo'];
    }

    /**
     * @param array<int, string> $expected minutes => text
     */
    #[DataProvider('afterStartTexts')]
    public function testMinutesAfterTheStartReadRightInEveryLanguage(string $locale, array $expected): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);

        foreach ($expected as $minutes => $text) {
            self::assertSame($text, $translator->trans('competition.reveal.after_start', ['%count%' => $minutes], null, $locale), "{$locale}: {$minutes}");
        }

        // The sentences that used to say "10 minutes" take the fragment - with nothing left over
        foreach (['competition.round_puzzle.form.hide_help', 'competition.reveal.source.automatic', 'competition.reveal.form.automatic'] as $key) {
            $sentence = $translator->trans($key, ['%after_start%' => $expected[25], '%date%' => 'D', '%time%' => 'T'], null, $locale);
            self::assertStringContainsString($expected[25], $sentence, "{$locale}: {$key}");
            self::assertStringNotContainsString('%', $sentence, "{$locale}: {$key}");
            self::assertStringNotContainsString('10', $sentence, "{$locale}: {$key}");
        }
    }

    /**
     * @return iterable<string, array{string, array<int, string>}>
     */
    public static function afterStartTexts(): iterable
    {
        yield 'en' => ['en', [
            0 => 'when the round starts',
            1 => '1 minute after the round starts',
            2 => '2 minutes after the round starts',
            5 => '5 minutes after the round starts',
            25 => '25 minutes after the round starts',
        ]];
        yield 'cs' => ['cs', [
            0 => 'hned na začátku kola',
            1 => '1 minutu po začátku kola',
            2 => '2 minuty po začátku kola',
            4 => '4 minuty po začátku kola',
            5 => '5 minut po začátku kola',
            25 => '25 minut po začátku kola',
        ]];
        yield 'de' => ['de', [
            0 => 'zu Rundenbeginn',
            1 => '1 Minute nach Rundenbeginn',
            2 => '2 Minuten nach Rundenbeginn',
            25 => '25 Minuten nach Rundenbeginn',
        ]];
        yield 'es' => ['es', [
            0 => 'al empezar la ronda',
            1 => '1 minuto después de que empiece la ronda',
            2 => '2 minutos después de que empiece la ronda',
            25 => '25 minutos después de que empiece la ronda',
        ]];
        yield 'fr' => ['fr', [
            0 => 'dès le début de la manche',
            1 => '1 minute après le début de la manche',
            2 => '2 minutes après le début de la manche',
            25 => '25 minutes après le début de la manche',
        ]];
        yield 'ja' => ['ja', [
            0 => 'ラウンド開始時',
            1 => 'ラウンド開始の1分後',
            25 => 'ラウンド開始の25分後',
        ]];
    }

    /**
     * @param 'everywhere'|'name_everywhere'|'event' $scope
     * @return RevealedPuzzle
     */
    private function item(
        null|DateTimeImmutable $revealsAt = null,
        string $scope = SecretRevealPreview::SCOPE_EVERYWHERE,
        null|DateTimeImmutable $hiddenElsewhereUntil = null,
    ): array {
        return [
            'id' => '018d0000-0000-0000-0000-00000000aaaa',
            'name' => 'Secret A',
            'revealsAt' => $revealsAt,
            'previousRevealsAt' => null,
            'scope' => $scope,
            'everywhere' => $scope === SecretRevealPreview::SCOPE_EVERYWHERE,
            'hiddenElsewhereUntil' => $hiddenElsewhereUntil,
        ];
    }

    /**
     * @param RevealedPuzzle $item
     * @param null|DateTimeImmutable $previousRevealsAt the moment the item moves from (a round edit), null for a removal
     */
    private function render(array $item, null|DateTimeImmutable $previousRevealsAt): string
    {
        $item['previousRevealsAt'] = $previousRevealsAt;
        $html = $this->twig->render('competition/_revealed_puzzle.html.twig', [
            'item' => $item,
            'timezone' => self::TIMEZONE,
            'timezone_assumed' => false,
        ]);

        // One line, and ICU's narrow no-break space before AM/PM as a plain one
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{202F}", ' ', $html)));
    }

    private static function text(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html))));
    }
}
