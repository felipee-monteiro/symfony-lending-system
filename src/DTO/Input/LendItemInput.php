<?php

declare(strict_types=1);

namespace App\DTO\Input;

use App\Validator\NotInThePast;
use Symfony\Component\Validator\Constraints as Assert;

final class LendItemInput
{
    #[Assert\NotBlank]
    public ?string $borrowerId = null;

    #[Assert\NotBlank]
    #[NotInThePast]
    public ?\DateTimeImmutable $dueAt = null;
}
