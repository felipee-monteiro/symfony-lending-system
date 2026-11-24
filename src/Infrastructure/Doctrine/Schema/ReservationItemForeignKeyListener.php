<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Schema;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Reservation references the item by id, not through an association, so the foreign key
 * cannot be declared on the entity. Adding it to the generated schema keeps
 * `doctrine:schema:validate` and future migration diffs aware of it.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final readonly class ReservationItemForeignKeyListener
{
    private const string TABLE = 'reservation';

    private const string CONSTRAINT = 'FK_7E3A4D2E8F5A1B9C';

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if (! $schema->hasTable(self::TABLE) || $schema->getTable(self::TABLE)->hasForeignKey(self::CONSTRAINT)) {
            return;
        }

        $args->setSchema(
            $schema->edit()
                ->modifyTable(
                    OptionallyQualifiedName::unquoted(self::TABLE),
                    static function (TableEditor $table): void {
                        $table->addForeignKeyConstraint(new ForeignKeyConstraint(
                            ['item_id'],
                            'item',
                            ['id'],
                            self::CONSTRAINT,
                            ['onDelete' => 'CASCADE'],
                        ));
                    },
                )
                ->create(),
        );
    }
}
