<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\FeedbackFormData;
use SpeedPuzzling\Web\Value\FeedbackScreenshotLimits;
use SpeedPuzzling\Web\Value\PhotoUploadLimits;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<FeedbackFormData>
 */
final class FeedbackFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('message', TextareaType::class, [
            'label' => 'forms.feedback',
            'help' => 'forms.feedback_help',
            'empty_data' => '',
            'constraints' => [
                new NotBlank(),
            ],
        ]);

        $builder->add('url', HiddenType::class);

        // Only screenshots whose background upload could not work come this way (feedback_screenshots_controller.js);
        // the others arrive as `photo_stash[screenshots][]` tokens
        $builder->add('screenshots', FileType::class, [
            'label' => false,
            'required' => false,
            'multiple' => true,
            'attr' => [
                'accept' => implode(',', PhotoUploadLimits::MIME_TYPES),
            ],
            'constraints' => [
                new Count(max: FeedbackScreenshotLimits::MAX_SCREENSHOTS),
                new All(constraints: [
                    new File(
                        maxSize: PhotoUploadLimits::MAX_PHOTO_BYTES,
                        mimeTypes: PhotoUploadLimits::MIME_TYPES,
                    ),
                ]),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => FeedbackFormData::class,
        ]);
    }
}
