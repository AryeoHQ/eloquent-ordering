<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Attributes;

use Attribute;
use Illuminate\Database\Eloquent\Model;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class OrderedIn
{
    /**
     * @param  class-string<Model>  $parent
     */
    public function __construct(public readonly string $parent) {}
}
