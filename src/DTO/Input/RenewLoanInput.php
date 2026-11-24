<?php

declare(strict_types=1);

namespace App\DTO\Input;

use Symfony\Component\Validator\Constraints as Assert;

final class RenewLoanInput
{
    #[Assert\NotBlank]
    public ?\DateTimeImmutable $newDueAt = null;
}
