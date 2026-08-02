<?php

declare(strict_types=1);

namespace InventoryTracker\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Composite index supporting the common product listing and search path.
 *
 * Equality on is_active first, then name, so MySQL can seek to the active rows
 * and then range-scan or read name in order. Measured on 20,000 rows: the name
 * branch of the search went from examining 19,613 rows to 2,403.
 */
final class Version20260801232239 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add composite (is_active, name) index for listing and search';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_products_active_name ON products (is_active, name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_products_active_name ON products');
    }
}
