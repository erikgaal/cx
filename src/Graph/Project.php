<?php

declare(strict_types=1);

namespace Cx\Graph;

final class Project
{
    public function __construct(
        public readonly string $name,
        public readonly string $root,
        /** @var list<string> */
        public readonly array $scripts,
    ) {}

    public function isRoot(): bool
    {
        return $this->root === '';
    }
}
