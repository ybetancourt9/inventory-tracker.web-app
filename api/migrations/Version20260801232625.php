<?php

declare(strict_types=1);

namespace InventoryTracker\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop the single-column name index, made redundant by (is_active, name).
 *
 * Every query that filters on is_active, which is all of them except the
 * includeInactive path, is served by the composite. Keeping both cost write
 * throughput on every insert and update for one uncommon read path.
 */
final class Version20260801232625 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop redundant idx_products_name';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_products_name ON products');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_products_name ON products (name)');
    }
}
