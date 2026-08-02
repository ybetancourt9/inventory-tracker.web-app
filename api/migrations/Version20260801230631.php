<?php

declare(strict_types=1);

namespace InventoryTracker\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Enforce non-negative stock at the database level.
 *
 * Written by hand because Doctrine's schema tool does not model CHECK
 * constraints, so db:diff neither generates nor drops this.
 */
final class Version20260801230631 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CHECK constraint enforcing products.quantity >= 0';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE products
             ADD CONSTRAINT chk_products_quantity_non_negative CHECK (quantity >= 0)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products DROP CONSTRAINT chk_products_quantity_non_negative');
    }
}
