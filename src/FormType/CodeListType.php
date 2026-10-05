<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A puzzle's EANs or brand codes in a form: one input per code (docs/features/puzzle-names/README.md, decision 6),
 * rendered by templates/puzzle/_code_inputs.html.twig - the first input always there, more behind a quiet "+ another"
 * on the label line (optional_rows_controller.js). Blank inputs stay in the data, so a refused form comes back with
 * every input it had; EanList::fromInputs() / BrandCodeList::fromInputs() drop them.
 *
 * @extends AbstractType<array<int, null|string>>
 */
final class CodeListType extends AbstractType
{
    /**
     * @param array{numeric: bool} $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Before the collection's own listener builds the inputs: there is always a first one to type in
        $atLeastOneInput = static function (FormEvent $event): void {
            $data = $event->getData();

            if (is_array($data) === false || $data === []) {
                $event->setData(['']);
            }
        };

        $builder->addEventListener(FormEvents::PRE_SET_DATA, $atLeastOneInput, 1);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, $atLeastOneInput, 1);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'entry_type' => TextType::class,
            'allow_add' => true,
            'allow_delete' => true,
            'required' => false,
            'error_bubbling' => false,
            // Barcodes: the phone shows its number pad
            'numeric' => false,
        ]);

        $resolver->setAllowedTypes('numeric', 'bool');

        $resolver->setNormalizer('entry_options', static function (Options $options, mixed $value): array {
            $value = is_array($value) ? $value : [];
            $attr = ['autocomplete' => 'off'];

            if ($options['numeric'] === true) {
                $attr['inputmode'] = 'numeric';
            }

            $value['attr'] = (is_array($value['attr'] ?? null) ? $value['attr'] : []) + $attr;

            return $value + ['label' => false, 'required' => false];
        });
    }

    public function getParent(): string
    {
        return CollectionType::class;
    }
}
