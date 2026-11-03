<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Exceptions;

use InvalidArgumentException;

final class NotAMember extends InvalidArgumentException
{
    /**
     * @param  class-string  $class
     * @param  non-empty-list<int|string>  $ids
     */
    public function __construct(public readonly string $class, public readonly array $ids)
    {
        parent::__construct(sprintf(
            'No order holds [%s] as a current member: [%s].',
            $class,
            implode('], [', $ids),
        ));
    }
}
