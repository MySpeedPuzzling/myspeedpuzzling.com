<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\Entity\PartnerListing;
use SpeedPuzzling\Web\FormData\PartnerListingFormData;
use SpeedPuzzling\Web\Results\PartnerFinderOffer;
use SpeedPuzzling\Web\Value\PartnerGoal;
use SpeedPuzzling\Web\Value\PartnerKind;
use SpeedPuzzling\Web\Value\PartnerStrength;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Intl\Languages;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The partner finder listing form (templates/partner_finder/_listing_form.html.twig). Field names equal
 * PartnerListingInvalid::FIELD_* so a refused value lands on its field. "Looking for" offers only the event's kinds;
 * "I am" a group only when the event has a team round (a group looks for a team).
 *
 * @extends AbstractType<PartnerListingFormData>
 */
final class PartnerListingFormType extends AbstractType
{
    /**
     * @param array{offer: PartnerFinderOffer, locale: string} $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $offer = $options['offer'];

        $builder->add('listingId', HiddenType::class);

        if ($offer->offers(PartnerKind::Team)) {
            $builder->add('iAm', ChoiceType::class, [
                'label' => 'partner_finder.form.i_am',
                'expanded' => true,
                'choices' => [
                    'partner_finder.form.i_am_alone' => PartnerListingFormData::ALONE,
                    'partner_finder.form.i_am_group' => PartnerListingFormData::GROUP,
                ],
            ]);

            $builder->add('groupSize', IntegerType::class, [
                'label' => 'partner_finder.form.group_size',
                'required' => false,
                'attr' => ['min' => 2, 'max' => PartnerListing::GROUP_SIZE_MAX, 'inputmode' => 'numeric'],
            ]);

            $builder->add('needed', IntegerType::class, [
                'label' => 'partner_finder.form.needed',
                'required' => false,
                'attr' => ['min' => 1, 'max' => PartnerListing::GROUP_SIZE_MAX - 2, 'inputmode' => 'numeric'],
            ]);
        }

        $kindChoices = [];

        foreach ($offer->kinds as $kind) {
            $kindChoices['partner_finder.form.looking_for_' . $kind->value] = $kind->value;
        }

        $builder->add('lookingFor', ChoiceType::class, [
            'label' => 'partner_finder.form.looking_for',
            'expanded' => true,
            'multiple' => true,
            'choices' => $kindChoices,
        ]);

        $builder->add('goal', EnumType::class, [
            'class' => PartnerGoal::class,
            'label' => 'partner_finder.form.goal',
            'expanded' => true,
            'required' => false,
            'placeholder' => false,
            'choice_label' => static fn (PartnerGoal $goal): string => $goal->translationKey(),
        ]);

        $strengthChoices = [];

        foreach (PartnerStrength::cases() as $strength) {
            $strengthChoices[$strength->translationKey()] = $strength->value;
        }

        $builder->add('strengths', ChoiceType::class, [
            'label' => 'partner_finder.form.strengths',
            'expanded' => true,
            'multiple' => true,
            'choices' => $strengthChoices,
        ]);

        $builder->add('customStrengths', TextType::class, [
            'label' => 'partner_finder.form.custom_strengths',
            'required' => false,
            'attr' => ['maxlength' => 200, 'autocomplete' => 'off'],
        ]);

        $builder->add('languages', ChoiceType::class, [
            'label' => 'partner_finder.form.languages',
            'multiple' => true,
            'required' => false,
            'choices' => self::languageChoices($options['locale']),
            'choice_translation_domain' => false,
            'autocomplete' => true,
            'tom_select_options' => [
                'maxItems' => PartnerListing::MAX_LANGUAGES,
                'closeAfterSelect' => true,
            ],
        ]);

        $builder->add('note', TextareaType::class, [
            'label' => 'partner_finder.form.note',
            'required' => false,
            'attr' => ['maxlength' => PartnerListing::NOTE_MAX_LENGTH, 'rows' => 3],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PartnerListingFormData::class,
            'locale' => 'en',
        ]);
        $resolver->setRequired('offer');
        $resolver->setAllowedTypes('offer', PartnerFinderOffer::class);
        $resolver->setAllowedTypes('locale', 'string');
    }

    /**
     * Every ISO 639-1 language by its name in the page's language.
     *
     * @return array<string, string> name => code
     */
    private static function languageChoices(string $locale): array
    {
        $choices = [];

        foreach (Languages::getNames($locale) as $code => $name) {
            if (strlen($code) === 2) {
                $choices[$name . ' (' . strtoupper($code) . ')'] = $code;
            }
        }

        return $choices;
    }
}
