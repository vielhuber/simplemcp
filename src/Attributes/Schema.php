<?php

declare(strict_types=1);

namespace vielhuber\simplemcp\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PARAMETER)]
class Schema
{
    public ?array $definition = null;
    public ?string $type = null;
    public ?string $description = null;
    public mixed $default = null;
    public ?array $enum = null;
    public ?string $format = null;
    public ?int $minLength = null;
    public ?int $maxLength = null;
    public ?string $pattern = null;
    public int|float|null $minimum = null;
    public int|float|null $maximum = null;
    public ?bool $exclusiveMinimum = null;
    public ?bool $exclusiveMaximum = null;
    public int|float|null $multipleOf = null;
    public ?array $items = null;
    public ?int $minItems = null;
    public ?int $maxItems = null;
    public ?bool $uniqueItems = null;
    public ?array $properties = null;
    public ?array $required = null;
    public bool|array|null $additionalProperties = null;

    public function __construct(
        ?array $definition = null,
        ?string $type = null,
        ?string $description = null,
        mixed $default = null,
        ?array $enum = null,
        ?string $format = null,
        ?int $minLength = null,
        ?int $maxLength = null,
        ?string $pattern = null,
        int|float|null $minimum = null,
        int|float|null $maximum = null,
        ?bool $exclusiveMinimum = null,
        ?bool $exclusiveMaximum = null,
        int|float|null $multipleOf = null,
        ?array $items = null,
        ?int $minItems = null,
        ?int $maxItems = null,
        ?bool $uniqueItems = null,
        ?array $properties = null,
        ?array $required = null,
        bool|array|null $additionalProperties = null
    ) {
        if ($definition !== null) {
            $this->definition = $definition;
        } else {
            $this->type = $type;
            $this->description = $description;
            $this->default = $default;
            $this->enum = $enum;
            $this->format = $format;
            $this->minLength = $minLength;
            $this->maxLength = $maxLength;
            $this->pattern = $pattern;
            $this->minimum = $minimum;
            $this->maximum = $maximum;
            $this->exclusiveMinimum = $exclusiveMinimum;
            $this->exclusiveMaximum = $exclusiveMaximum;
            $this->multipleOf = $multipleOf;
            $this->items = $items;
            $this->minItems = $minItems;
            $this->maxItems = $maxItems;
            $this->uniqueItems = $uniqueItems;
            $this->properties = $properties;
            $this->required = $required;
            $this->additionalProperties = $additionalProperties;
        }
    }
}
