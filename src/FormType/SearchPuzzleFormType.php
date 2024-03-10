<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\SearchPlayerFormData;
use SpeedPuzzling\Web\FormData\SearchPuzzleFormData;
use SpeedPuzzling\Web\Query\GetManufacturers;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @extends AbstractType<SearchPuzzleFormData>
 */
final class SearchPuzzleFormType extends AbstractType
{
    public function __construct(
        readonly private UrlGeneratorInterface $urlGenerator,
        readonly private GetManufacturers $getManufacturers,
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

        $builder->add('brand', ChoiceType::class, [
            'label' => 'Brand',
            'required' => false,
            //'autocomplete' => true,
            'choices' => $brandChoices,
            // loading_more_text
            // no_results_found_text
            // no_more_results_text
        ]);

        $builder->add('pieces', ChoiceType::class, [
            'required' => false,
            'expanded' => true,
            'multiple' => false,
            'choices' => [
                '' => 'Any',
                '1-499' => '1-499',
                '500' => '500',
                '501-999' => '501-999',
                '1000' => '1000',
                '1000+' => '1000+',
            ],
            'choice_translation_domain' => false,
        ]);

        $builder->add('tags', ChoiceType::class, [
            'required' => false,
        ]);

        $builder->add('search', TextType::class, [
            'required' => false,
            'attr' => [
                'placeholder' => 'Name, code or EAN...',
            ],
        ]);

        $builder->add('onlyWithResults', CheckboxType::class, [
            'label' => 'Only puzzle with entered time',
            'required' => false,
        ]);

        $builder->add('onlySolvedByMe', CheckboxType::class, [
            'label' => 'Only puzzle I have solved',
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SearchPuzzleFormData::class,
        ]);
    }
}
