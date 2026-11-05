<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Attributes;

use Attribute;
use Illuminate\Database\Eloquent\Model;

#[Attribute(Attribute::TARGET_CLASS)]
final class OrderedThrough
{
    /** @var list<class-string<Model>> */
    public readonly array $parents;

    /**
     * @param  class-string<Model>  ...$parents
     */
    public function __construct(string ...$parents)
    {
        $this->parents = array_values($parents);
    }
}
