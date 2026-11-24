<?php

declare(strict_types=1);

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\UX\Toolkit\UXToolkitBundle;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use Symfonycasts\TailwindBundle\SymfonycastsTailwindBundle;
use TalesFromADev\Twig\Extra\Tailwind\Bridge\Symfony\Bundle\TalesFromADevTwigExtraTailwindBundle;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

return [
    FrameworkBundle::class                      => ['all' => true],
    DoctrineBundle::class                       => ['all' => true],
    DoctrineMigrationsBundle::class             => ['all' => true],
    TwigBundle::class                           => ['all' => true],
    SymfonycastsTailwindBundle::class           => ['all' => true],
    TwigComponentBundle::class                  => ['all' => true],
    UXToolkitBundle::class                      => ['dev' => true, 'test' => true],
    TalesFromADevTwigExtraTailwindBundle::class => ['all' => true],
    TwigExtraBundle::class                      => ['all' => true],
];
