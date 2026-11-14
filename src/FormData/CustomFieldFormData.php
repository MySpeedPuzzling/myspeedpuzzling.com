<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Results\CustomFieldOverview;
use SpeedPuzzling\Web\Value\CustomFieldDefinition;
use SpeedPuzzling\Web\Value\CustomFieldType;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class CustomFieldFormData
{
    #[NotBlank]
    #[Length(max: CustomFieldDefinition::NAME_MAX_LENGTH)]
    public null|string $name = null;

    public CustomFieldType $type = CustomFieldType::Text;

    // One choice per line - only "one of a list" reads it
    #[Length(max: 3000)]
    public null|string $choices = null;

    public bool $forResults = true;

    public bool $forCollectionItems = false;

    public static function fromField(CustomFieldOverview $field): self
    {
        $data = new self();
        $data->name = $field->name;
        $data->type = $field->type;
        $data->choices = implode("\n", $field->choices);
        $data->forResults = $field->forResults;
        $data->forCollectionItems = $field->forCollectionItems;

        return $data;
    }

    /**
     * @return list<string>
     */
    public function choiceLines(): array
    {
        return array_values(array_filter(
            array_map(trim(...), preg_split('/\R/u', $this->choices ?? '') ?: []),
            static fn (string $line): bool => $line !== '',
        ));
    }
}
