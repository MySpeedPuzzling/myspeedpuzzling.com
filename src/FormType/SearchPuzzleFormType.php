<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\SearchPlayerFormData;
use SpeedPuzzling\Web\FormData\SearchPuzzleFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
    ) {
    }

    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('piecesCount', TextType::class, [
            'label' => 'Pieces count',
            'required' => false,
        ]);

        $builder->add('brand', TextType::class, [
            'label' => 'Brand',
            'required' => false,
        ]);

        $builder->add('tags', ChoiceType::class, [
            'required' => false,
        ]);

        $builder->add('puzzle', TextType::class, [
            'required' => false,
            'label' => 'Puzzle code, EAN or name',
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
            'method' => 'GET',
        ]);
    }
}
