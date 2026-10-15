<?php

namespace App\Support\IO;

/** One column in an import/export resource template. */
class Field
{
    public function __construct(
        public string $key,            // CSV header label AND logical row key
        public bool $required = false,
        public ?string $hint = null,   // validation hint shown in the template
        public ?string $sample = null, // example value in the template's sample row
    ) {}
}
