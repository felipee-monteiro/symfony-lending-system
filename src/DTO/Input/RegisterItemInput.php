<?php

declare(strict_types=1);

namespace App\DTO\Input;

use Symfony\Component\Validator\Constraints as Assert;

final class RegisterItemInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $title = null;
}
