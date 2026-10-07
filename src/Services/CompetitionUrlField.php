<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "URL" (slug) field of the event, edition and series edit forms: what the organiser typed, normalised like a
 * generated slug ("My New URL" → "my-new-url") and checked unique in its scope by CompetitionSlugGenerator - the same
 * checks the handlers run (and the internal API's explicit `slug`). A URL that cannot be used is an error on the field.
 */
readonly final class CompetitionUrlField
{
    private const string PLACEHOLDER = 'competition-url-placeholder';

    public function __construct(
        private CompetitionSlugGenerator $slugGenerator,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * The slug to send for a standalone event (`$seriesId` null) or an edition: null = unchanged.
     *
     * @param FormInterface<mixed> $field
     */
    public function competitionSlug(FormInterface $field, null|string $currentSlug, null|string $seriesId, string $competitionId): null|string
    {
        return $this->resolve(
            $field,
            $currentSlug,
            fn (string $slug): bool => $this->slugGenerator->isTaken($slug, $seriesId, $competitionId),
        );
    }

    /**
     * The slug to send for a series: null = unchanged.
     *
     * @param FormInterface<mixed> $field
     */
    public function seriesSlug(FormInterface $field, null|string $currentSlug, string $seriesId): null|string
    {
        return $this->resolve(
            $field,
            $currentSlug,
            fn (string $slug): bool => $this->slugGenerator->isSeriesSlugTaken($slug, $seriesId),
        );
    }

    /**
     * When the handler finds the slug taken after all (another save in between).
     *
     * @param FormInterface<mixed> $field
     */
    public function markTaken(FormInterface $field): void
    {
        $field->addError(new FormError($this->translator->trans('competition.form.slug_taken')));
    }

    /**
     * The address in front of the slug, e.g. "myspeedpuzzling.com/en/events/" - from the real route.
     *
     * @param array<string, string> $parameters the route's other parameters
     */
    public function prefix(string $routeName, string $slugParameter, array $parameters = []): string
    {
        $url = $this->urlGenerator->generate(
            $routeName,
            [...$parameters, $slugParameter => self::PLACEHOLDER],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $position = strrpos($url, self::PLACEHOLDER);
        $prefix = $position === false ? $url : substr($url, 0, $position);

        return rawurldecode((string) preg_replace('#^https?://#', '', $prefix));
    }

    /**
     * @param FormInterface<mixed> $field
     * @param callable(string): bool $isTaken
     */
    private function resolve(FormInterface $field, null|string $currentSlug, callable $isTaken): null|string
    {
        $typed = $field->getData();
        $typed = is_string($typed) ? trim($typed) : '';

        if ($typed === '') {
            // A competition without a slug may stay without one; an existing address cannot be removed
            if ($currentSlug !== null) {
                $field->addError(new FormError($this->translator->trans('competition.form.slug_required')));
            }

            return null;
        }

        if ($typed === $currentSlug) {
            return null;
        }

        $slug = $this->slugGenerator->normalize($typed);

        if ($slug === $currentSlug) {
            return null;
        }

        if (CompetitionSlugGenerator::isValid($slug) === false) {
            $field->addError(new FormError($this->translator->trans('competition.form.slug_invalid')));

            return null;
        }

        if ($isTaken($slug)) {
            $this->markTaken($field);

            return null;
        }

        return $slug;
    }
}
