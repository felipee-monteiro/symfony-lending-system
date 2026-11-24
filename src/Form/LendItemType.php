<?php

declare(strict_types=1);

namespace App\Form;

use App\DTO\Input\LendItemInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class LendItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('borrowerId', TextType::class, [
                'label' => 'Borrower',
            ])
            ->add('dueAt', DateType::class, [
                'label'  => 'Due',
                'widget' => 'single_text',
                'input'  => 'datetime_immutable',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LendItemInput::class,
        ]);
    }
}
