<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Exceptions;

enum ParentUnavailability: string
{
    case Trashed = 'trashed';
    case Missing = 'missing';
}
