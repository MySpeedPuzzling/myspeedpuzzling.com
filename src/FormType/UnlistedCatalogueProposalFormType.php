<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\UnlistedCatalogueProposalFormData;
use SpeedPuzzling\Web\Value\PuzzleBoxPhoto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The short form of "Add to the catalogue" on an unlisted puzzle (docs/features/data-import/unlisted-puzzles.md).
 * Nothing of the add-result form is used or changed: this one has plain inputs only, so it works without JavaScript
 * and inside the site's modal. The photo of the box is required exactly as there (PuzzleBoxPhoto).
 *
 * @extends AbstractType<UnlistedCatalogueProposalFormData>
 */
final class UnlistedCatalogueProposalFormType extends AbstractType
{
    public function __construct(
        readonly private TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // 16 px inputs: iOS does not zoom the page on focus
        $input = ['style' => 'font-size: 16px; min-height: 44px;', 'autocomplete' => 'off'];

        $builder->add('name', TextType::class, [
            'label' => 'unlisted_puzzles.catalogue.form.name',
            'label_attr' => ['class' => 'required'],
            'required' => false,
            'attr' => [...$input, 'maxlength' => 200],
        ]);

        $builder->add('brand', TextType::class, [
            'label' => 'forms.brand',
            'label_attr' => ['class' => 'required'],
            'required' => false,
            'attr' => [...$input, 'maxlength' => 100],
        ]);

        $builder->add('piecesCount', IntegerType::class, [
            'label' => 'forms.pieces_count',
            'label_attr' => ['class' => 'required'],
            'required' => false,
            'attr' => [...$input, 'inputmode' => 'numeric', 'min' => 10, 'max' => 99999],
        ]);

        $builder->add('photo', FileType::class, [
            'label' => 'unlisted_puzzles.catalogue.form.photo',
            'label_attr' => ['class' => 'required'],
            'help' => 'unlisted_puzzles.catalogue.form.photo_help',
            'required' => false,
            'constraints' => [PuzzleBoxPhoto::constraint()],
            'attr' => ['accept' => 'image/*', 'style' => 'min-height: 44px;'],
        ]);

        $builder->add('ean', TextType::class, [
            'label' => 'forms.ean',
            'required' => false,
            'attr' => [...$input, 'inputmode' => 'numeric', 'maxlength' => 100],
        ]);

        $builder->add('brandCode', TextType::class, [
            'label' => 'forms.puzzle_identification_number',
            'required' => false,
            'attr' => [...$input, 'maxlength' => 100],
        ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $data = $event->getData();

            if (!$data instanceof UnlistedCatalogueProposalFormData) {
                return;
            }

            $required = $this->translator->trans('forms.required_field');

            if ($data->name === null || trim($data->name) === '') {
                $form->get('name')->addError(new FormError($required));
            }

            // A brand is typed here as a name: an id would be read as "that brand" - nobody types one
            if ($data->brand === null || trim($data->brand) === '' || Uuid::isValid(trim($data->brand))) {
                $form->get('brand')->addError(new FormError($required));
            }

            if ($data->piecesCount === null) {
                $form->get('piecesCount')->addError(new FormError($required));
            }

            // The rule of every place a new puzzle is made: never without a photo of its box
            if ($data->photo === null && $form->get('photo')->getErrors()->count() === 0) {
                $form->get('photo')->addError(new FormError($this->translator->trans('unlisted_puzzles.catalogue.form.photo_required')));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => UnlistedCatalogueProposalFormData::class,
            // The CSRF token of the unlisted pages is checked by the controller, like every action of theirs
            'csrf_protection' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'catalogue';
    }
}
