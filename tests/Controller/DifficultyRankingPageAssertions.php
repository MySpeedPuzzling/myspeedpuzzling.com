<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Value\DifficultyTier;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Assertions for the public hardest / easiest pages. Difficulty is members-only:
 * the public HTML must carry no score, tier or "x% harder" wording anywhere,
 * hidden markup included (Google indexes hidden text).
 */
trait DifficultyRankingPageAssertions
{
    /**
     * @param list<float> $scores every seeded score
     */
    private function assertNoDifficultyIn(Crawler $crawler, string $html, array $scores, string $locale): void
    {
        self::assertStringNotContainsString('badge-tier-', $html);
        self::assertStringNotContainsString('data-difficulty-tier', $html);

        foreach (DifficultyTier::cases() as $tier) {
            // Neither the tier icons nor the tier names - the lock icon is the only difficulty icon
            self::assertStringNotContainsString('#' . $tier->icon(), $html);

            $name = $this->translateTo($tier->translationKey(), $locale);
            self::assertDoesNotMatchRegularExpression(
                '/(?<!\p{L})' . preg_quote($name, '/') . '(?!\p{L})/u',
                $html,
                sprintf('The public page must not name the tier "%s"', $name),
            );
        }

        // The members' "x% harder than average" wording, around its number
        $humanWording = [$this->translateTo('puzzle_intelligence.human.about_average', $locale)];

        foreach (['puzzle_intelligence.human.harder_than_avg', 'puzzle_intelligence.human.easier_than_avg'] as $key) {
            foreach (explode('%percent%%', $this->translateTo($key, $locale)) as $fragment) {
                if (mb_strlen(trim($fragment)) >= 5) {
                    $humanWording[] = trim($fragment);
                }
            }
        }

        self::assertGreaterThanOrEqual(3, count($humanWording));

        foreach ($humanWording as $wording) {
            self::assertStringNotContainsString($wording, $html);
        }

        // Rounded scores are short enough to collide with the inline critical CSS
        // in <head> (1.375rem ...), so those are checked in the page body and the
        // structured data only
        $pageContent = $crawler->filter('main')->outerHtml()
            . implode('', $crawler->filter('script[type="application/ld+json"]')->each(
                static fn (Crawler $script): string => $script->text(),
            ));

        foreach ($scores as $score) {
            self::assertStringNotContainsString(number_format($score, 4), $html);
            self::assertStringNotContainsString(number_format($score, 2), $pageContent);
        }
    }

    /**
     * @return list<string>
     */
    private function rankedNames(Crawler $crawler): array
    {
        /** @var list<string> $names */
        $names = $crawler->filter('#difficulty-ranking > li a.fw-medium')->each(
            static fn (Crawler $link): string => $link->text(),
        );

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonLd(Crawler $crawler, string $type): array
    {
        foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
            $data = json_decode((string) $script->textContent, true, flags: JSON_THROW_ON_ERROR);

            if (is_array($data) && ($data['@type'] ?? null) === $type) {
                /** @var array<string, mixed> $data */
                return $data;
            }
        }

        self::fail(sprintf('No %s JSON-LD found', $type));
    }

    private function translateTo(string $key, string $locale = 'en'): string
    {
        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get('translator');

        return $translator->trans($key, [], 'messages', $locale);
    }
}
