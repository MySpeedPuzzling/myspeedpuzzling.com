<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard;

use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the one-round path infers instead of asking (W-creation-wizard-research.md §3.4) - every value is shown on the
 * Review step with a way to change it:
 *
 * - the round's name is its category ("Solo", "Pairs", "Teams") in the organiser's language - the event's name would
 *   repeat it on every page that shows "event - round"; it follows the category while nobody renamed it;
 * - the time limit starts at 90 minutes, editable on the Puzzle step;
 * - the reveal delay is the round default (RoundPuzzleReveal::DEFAULT_DELAY_MINUTES), the badge colour automatic.
 */
readonly final class EventWizardDefaults
{
    public const int MINUTES_LIMIT = 90;
    public const int TEAM_SIZE = 4;

    // The languages a draft may have been created in - a round's generated name is matched in each (challengeNumbersOf)
    public const array LOCALES = ['en', 'cs', 'de', 'es', 'fr', 'ja'];

    // At most this many challenges back to back are read from a name (the Puzzles grid's slots)
    private const int MAX_NAMED_CHALLENGES = 4;

    private const string NUMBERS_SLOT = '@@numbers@@';

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function roundName(RoundCategory $category): string
    {
        return $this->translator->trans('event_wizard.round_name.' . $category->value);
    }

    /**
     * A round of the Challenges template (spws-wizard.md): "Pairs – Challenge 5", "Pairs – Challenges 1 + 2"
     *
     * @param non-empty-list<int> $numbers
     */
    public function challengeRoundName(RoundCategory $category, array $numbers): string
    {
        return $this->translator->trans(count($numbers) === 1 ? 'event_wizard.structure.challenges.round_name_one' : 'event_wizard.structure.challenges.round_name_block', [
            '%category%' => $this->roundName($category),
            '%numbers%' => implode(' + ', $numbers),
        ]);
    }

    /**
     * A challenge's label on the Puzzles step ("Challenge 3" - RoundPuzzleLabel::MAX_LENGTH fits it)
     */
    public function challengeLabel(int $number): string
    {
        return $this->translator->trans('event_wizard.structure.challenges.label', ['%number%' => $number]);
    }

    /**
     * The challenge numbers a round's name carries when the Challenges template named it (challengeRoundName() in any of
     * the wizard's languages, with the round's category): "Pairs – Challenges 3 + 4" → [3, 4], "Pairs – Challenge 5" →
     * [5]. Anything else - "Solo 1", "Semifinal 1", a renamed round - → []. Package D of spws-wizard.md: a block left
     * "Decide later" keeps its plan when the wizard is opened again without `?challenges`.
     *
     * @return list<int>
     */
    public function challengeNumbersOf(string $name, RoundCategory $category): array
    {
        // A dash typed instead of the generated en dash ("Pairs - Challenge 5") is the same name
        $name = self::sameDashes(trim($name));

        foreach (self::LOCALES as $locale) {
            foreach (['round_name_one' => false, 'round_name_block' => true] as $key => $block) {
                $pattern = $this->translator->trans('event_wizard.structure.challenges.' . $key, [
                    '%category%' => $this->translator->trans('event_wizard.round_name.' . $category->value, locale: $locale),
                    '%numbers%' => self::NUMBERS_SLOT,
                ], locale: $locale);
                [$before, $after] = array_pad(explode(self::NUMBERS_SLOT, self::sameDashes($pattern), 2), 2, '');
                $numbers = $block ? '\\d{1,3}(?: \\+ \\d{1,3}){1,' . (self::MAX_NAMED_CHALLENGES - 1) . '}' : '\\d{1,3}';

                if (preg_match('/^' . preg_quote($before, '/') . '(' . $numbers . ')' . preg_quote($after, '/') . '$/u', $name, $match) !== 1) {
                    continue;
                }

                $list = array_map(intval(...), explode(' + ', $match[1]));

                if (in_array(0, $list, true) === false) {
                    return $list;
                }
            }
        }

        return [];
    }

    private static function sameDashes(string $text): string
    {
        return (string) preg_replace('/\s*[-\x{2013}\x{2014}]\s*/u', ' - ', $text);
    }
}
