<?php

namespace MagicTest\MagicTest\Element;

use Illuminate\Support\Str;

class Attribute
{
    public string $name;

    public string $value;

    public bool $isUnique;

    public function __construct($name, $value, $isUnique = true)
    {
        $this->name = $name;
        $this->value = $value;
        $this->isUnique = $isUnique;
    }

    public function isUnique(): bool
    {
        return $this->isUnique;
    }

    public function buildSelector($element = 'input', $forceInputSyntax = false): string
    {
        $escapedValue = str_replace(".", "\.", str_replace("'", "\\", $this->value));


        return [
            'wire:model' => $this->buildLivewireSelector($element),
            'dusk' => "@{$escapedValue}",
            'name' => $forceInputSyntax ? $this->buildFullSelector($element) : $escapedValue,
            'id' => $forceInputSyntax ? $this->buildFullSelector($element) : "#{$escapedValue}",
        ][$this->name] ?? $this->buildFullSelector($element);
    }

    public function buildFullSelector(string $element): string
    {
        $escapedValue = str_replace("'", "\\", $this->value);


        return "{$element}[{$this->name}={$escapedValue}]";
    }

    public function buildLivewireSelector(string $element): string
    {
        $firstPart = Str::of($this->name)->before(':');
        $secondPart = Str::of($this->name)->after($firstPart);

        return "{$element}[{$firstPart}\\{$secondPart}={$this->value}]";
    }
}
