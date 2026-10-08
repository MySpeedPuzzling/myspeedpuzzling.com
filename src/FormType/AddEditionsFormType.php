<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use SpeedPuzzling\Web\FormData\AddEditionsFormData;
use SpeedPuzzling\Web\Value\EditionDateRule;
use SpeedPuzzling\Web\Value\EditionDateRuleKind;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Add several dates" - the rule or the picked days and the names (docs/features/organizations/README.md, P15). A GET
 * form without a name: its fields are the query string (`?how=repeat&rule=last_weekday&weekday=2&…`), so the preview is
 * a plain link that works without JavaScript; the controller creates it with `createNamed('', …)`.
 *
 * @extends AbstractType<AddEditionsFormData>
 */
final class AddEditionsFormType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('how', ChoiceType::class, [
            'label' => 'organizer_tools.add_editions.how',
            'choices' => [
                'organizer_tools.add_editions.how_repeat' => AddEditionsFormData::HOW_REPEAT,
                'organizer_tools.add_editions.how_pick' => AddEditionsFormData::HOW_PICK,
            ],
            'expanded' => true,
            'placeholder' => false,
        ]);

        $builder->add('rule', EnumType::class, [
            'label' => 'organizer_tools.add_editions.rule_label',
            'class' => EditionDateRuleKind::class,
            'choice_label' => static fn (EditionDateRuleKind $kind): string => $kind->translationKey(),
            'required' => false,
            'placeholder' => false,
        ]);

        $builder->add('nth', ChoiceType::class, [
            'label' => 'organizer_tools.add_editions.nth_label',
            'help' => 'organizer_tools.add_editions.nth_help',
            'choices' => [
                'organizer_tools.add_editions.nth.1' => 1,
                'organizer_tools.add_editions.nth.2' => 2,
                'organizer_tools.add_editions.nth.3' => 3,
                'organizer_tools.add_editions.nth.4' => 4,
            ],
            'required' => false,
            'placeholder' => false,
        ]);

        $builder->add('weekday', ChoiceType::class, [
            'label' => 'organizer_tools.add_editions.weekday',
            'choices' => $this->weekdays(),
            'choice_translation_domain' => false,
            'required' => false,
            'placeholder' => false,
        ]);

        $builder->add('starting', DateType::class, [
            'label' => 'organizer_tools.add_editions.starting',
            'help' => 'organizer_tools.add_editions.starting_help',
            'required' => false,
            'widget' => 'single_text',
            'format' => 'dd.MM.yyyy',
            'html5' => false,
            'input' => 'datetime_immutable',
            'input_format' => 'd.m.Y',
        ]);

        $builder->add('count', IntegerType::class, [
            'label' => 'organizer_tools.add_editions.count',
            'required' => false,
            'attr' => ['min' => 1, 'max' => EditionDateRule::MAX_COUNT, 'inputmode' => 'numeric'],
        ]);

        $builder->add('dates', TextType::class, [
            'label' => 'organizer_tools.add_editions.dates',
            'help' => 'organizer_tools.add_editions.dates_help',
            'help_translation_parameters' => ['%max%' => EditionDateRule::MAX_COUNT],
            'required' => false,
        ]);

        $builder->add('namePattern', TextType::class, [
            'label' => 'organizer_tools.add_editions.name_pattern',
            'help' => 'organizer_tools.add_editions.name_pattern_help',
            'attr' => ['maxlength' => AddEditionsFormData::NAME_PATTERN_MAX_LENGTH],
        ]);

        $builder->add('eligibility', TextType::class, [
            'label' => 'organizer_tools.form.eligibility',
            'help' => 'organizer_tools.form.eligibility_edition_help',
            'required' => false,
            'attr' => ['maxlength' => 120],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AddEditionsFormData::class,
            'method' => 'GET',
            'csrf_protection' => false,
            // The page's links carry ?return= / ?return_title=
            'allow_extra_fields' => true,
        ]);
    }

    /**
     * Monday … Sunday in the page's language, as ISO-8601 numbers (1 … 7)
     *
     * @return array<string, int>
     */
    private function weekdays(): array
    {
        $formatter = new IntlDateFormatter($this->translator->getLocale(), IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, 'EEEE');
        // 5 January 2026 is a Monday
        $monday = new DateTimeImmutable('2026-01-05', new DateTimeZone('UTC'));
        $weekdays = [];

        for ($day = 1; $day <= 7; $day++) {
            $name = $formatter->format($monday->modify(sprintf('+%d days', $day - 1)));
            $weekdays[is_string($name) ? $name : (string) $day] = $day;
        }

        return $weekdays;
    }
}
