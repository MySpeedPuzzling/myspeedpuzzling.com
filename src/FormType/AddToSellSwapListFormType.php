<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\AddToSellSwapListFormData;
use SpeedPuzzling\Web\Results\MarketplaceEvent;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AddToSellSwapListFormData>
 */
final class AddToSellSwapListFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('listingType', ChoiceType::class, [
            'label' => 'sell_swap_list.form.listing_type',
            'required' => true,
            'expanded' => true,
            'choices' => [
                'sell_swap_list.listing_type.swap' => ListingType::Swap,
                'sell_swap_list.listing_type.sell' => ListingType::Sell,
                'sell_swap_list.listing_type.both' => ListingType::Both,
                'sell_swap_list.listing_type.free' => ListingType::Free,
            ],
            'choice_value' => static fn (null|ListingType $choice): null|string => $choice?->value,
        ]);

        $builder->add('price', NumberType::class, [
            'label' => 'sell_swap_list.form.price',
            'required' => false,
            'help' => 'sell_swap_list.form.price_help',
            'html5' => true,
            'attr' => [
                'min' => 0,
                'step' => 'any',
            ],
        ]);

        $builder->add('condition', ChoiceType::class, [
            'label' => 'sell_swap_list.form.condition',
            'required' => true,
            'placeholder' => 'sell_swap_list.form.condition_placeholder',
            'choices' => [
                'sell_swap_list.condition.new' => PuzzleCondition::New,
                'sell_swap_list.condition.like_new' => PuzzleCondition::LikeNew,
                'sell_swap_list.condition.normal' => PuzzleCondition::Normal,
                'sell_swap_list.condition.not_so_good' => PuzzleCondition::NotSoGood,
                'sell_swap_list.condition.missing_pieces' => PuzzleCondition::MissingPieces,
            ],
        ]);

        $builder->add('comment', TextareaType::class, [
            'label' => 'sell_swap_list.form.comment',
            'required' => false,
            'attr' => [
                'rows' => 3,
                'maxlength' => 500,
            ],
        ]);

        $builder->add('publishedOnMarketplace', CheckboxType::class, [
            'label' => 'sell_swap_list.form.publish_on_marketplace',
            'required' => false,
            'help' => 'sell_swap_list.form.publish_on_marketplace_help',
        ]);

        /** @var list<MarketplaceEvent> $marketplaceEvents */
        $marketplaceEvents = $options['marketplace_events'];

        // Marketplace at events (docs/features/marketplace/11-events.md): only for a seller going to one
        if ($marketplaceEvents !== []) {
            $names = [];
            foreach ($marketplaceEvents as $event) {
                $names[$event->competitionId] = $event->reference->displayName();
            }

            $builder->add('eventIds', ChoiceType::class, [
                'label' => 'marketplace_events.form.field_label',
                'required' => false,
                'expanded' => true,
                'multiple' => true,
                'choices' => array_keys($names),
                'choice_value' => static fn (null|string $competitionId): string => (string) $competitionId,
                'choice_label' => static fn (string $competitionId): string => $names[$competitionId] ?? $competitionId,
                'choice_translation_domain' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AddToSellSwapListFormData::class,
            // list<MarketplaceEvent> - the marketplace events the seller goes to (GetMarketplaceEvents::forPlayer())
            'marketplace_events' => [],
        ]);
        $resolver->setAllowedTypes('marketplace_events', 'array');
    }
}
