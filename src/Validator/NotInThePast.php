<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Rejects calendar dates before "today" according to the injected clock, so the rule
 * follows the application clock instead of PHP's real "now".
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class NotInThePast extends Constraint
{
    public string $message = 'The date cannot be in the past.';
}
