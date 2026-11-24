<?php

declare(strict_types=1);

namespace App\Validator;

use Psr\Clock\ClockInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class NotInThePastValidator extends ConstraintValidator
{
    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (! $constraint instanceof NotInThePast) {
            throw new UnexpectedTypeException($constraint, NotInThePast::class);
        }

        if ($value === null) {
            return;
        }

        if (! $value instanceof \DateTimeInterface) {
            throw new UnexpectedTypeException($value, \DateTimeInterface::class);
        }

        if ($value < $this->clock->now()->setTime(0, 0)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
