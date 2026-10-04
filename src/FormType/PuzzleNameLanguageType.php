<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\Value\PuzzleNameLanguageChoices;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Event\PreSetDataEvent;
use Symfony\Component\Form\Event\PreSubmitEvent;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The language of a puzzle name: PuzzleNameLanguageChoices in the page language, empty = not known. `extra_tags` keeps
 * a tag outside the list selectable - the one the name already has. The names editor's types add the field through
 * addTo(), which offers the tag the name had and the one submitted, so a 422 re-render keeps either.
 *
 * @extends AbstractType<null|string>
 */
final class PuzzleNameLanguageType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Adds (or replaces) the language field `$child` on a form whose data holds the tag at the same property path -
     * call it from PRE_SET_DATA and PRE_SUBMIT listeners.
     *
     * @param array<string, mixed> $options
     */
    public static function addTo(FormEvent $event, string $child, array $options = []): void
    {
        $form = $event->getForm();

        // The tag the name was loaded with, then (on submit) the one sent - PuzzleNameLanguageChoices drops invalid ones
        $extraTags = [self::tagOf($event instanceof PreSetDataEvent ? $event->getData() : $form->getData(), $child)];

        if ($event instanceof PreSubmitEvent) {
            $submitted = $event->getData();
            $extraTags[] = is_array($submitted) && is_string($submitted[$child] ?? null) ? $submitted[$child] : null;
        }

        $form->add($child, self::class, ['extra_tags' => array_values(array_filter($extraTags, is_string(...)))] + $options);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'extra_tags' => [],
            'label' => false,
            'required' => false,
            'placeholder' => 'puzzle_names.language_not_known',
            // The editor's texts are translated in every form it sits in, the admin ones included
            'translation_domain' => 'messages',
            'choice_translation_domain' => false,
            'choices' => fn (Options $options): array => PuzzleNameLanguageChoices::choices(
                $this->translator->getLocale(),
                array_values(array_filter((array) $options['extra_tags'], is_string(...))),
            ),
        ]);

        $resolver->setAllowedTypes('extra_tags', 'array');
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    private static function tagOf(mixed $data, string $property): null|string
    {
        if (is_object($data) === false || property_exists($data, $property) === false) {
            return null;
        }

        $tag = $data->{$property};

        return is_string($tag) ? $tag : null;
    }
}
