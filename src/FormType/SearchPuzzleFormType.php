<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\SearchPuzzleFormData;
use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Query\GetTags;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<SearchPuzzleFormData>
 */
final class SearchPuzzleFormType extends AbstractType
{
    public function __construct(
        readonly private GetManufacturers $getManufacturers,
        readonly private GetTags $getTags,
        readonly private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $brandChoices = [];
        foreach ($this->getManufacturers->onlyApprovedOrAddedByPlayer() as $manufacturer) {
            $brandChoices["{$manufacturer->manufacturerName} ({$manufacturer->puzzlesCount})"] = $manufacturer->manufacturerId;
        }

        $tagChoices = [];
        foreach ($this->getTags->all() as $tag) {
            $tagChoices[$tag->name] = $tag->tagId;
        }

        $builder->add('brand', ChoiceType::class, [
            'label' => 'forms.brand',
            'required' => false,
            'autocomplete' => true,
            'choices' => $brandChoices,
            'placeholder' => 'forms.brand',
            'empty_data' => '',
            'choice_translation_domain' => false,
        ]);

        $builder->add('pieces', ChoiceType::class, [
            'required' => false,
            'expanded' => true,
            'multiple' => false,
            'empty_data' => '',
            'choices' => [
                '' => $this->translator->trans('all_pieces'),
                '1-499' => '1-499',
                '500' => '500',
                '501-999' => '501-999',
                '1000' => '1000',
                '1001+' => '1001+',
            ],
            'choice_translation_domain' => false,
        ]);

        $builder->add('tag', ChoiceType::class, [
            'label' => 'forms.competition',
            'required' => false,
            'autocomplete' => true,
            'empty_data' => '',
            'choice_translation_domain' => false,
            'choices' => $tagChoices,
            'placeholder' => 'forms.competition',
        ]);

        $builder->add('search', TextType::class, [
            'required' => false,
            'empty_data' => '',
            'attr' => [
                'placeholder' => 'forms.puzzle_search_placeholder',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SearchPuzzleFormData::class,
            'method' => 'get',
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        // Return an empty string to remove form name prefix from field names
        return '';
    }
}
