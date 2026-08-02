<?php

declare(strict_types=1);

namespace InventoryTracker\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create the products table.
 *
 * Three indexes: unique on sku, one on name for prefix search and default
 * ordering, and a composite on (is_active, quantity) for the low-stock filter.
 */
final class Version20260729234226 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create products table with sku, name and low-stock indexes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE products (id INT AUTO_INCREMENT NOT NULL, sku VARCHAR(64) NOT NULL, name VARCHAR(128) NOT NULL, quantity INT DEFAULT 0 NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_products_name (name), INDEX idx_products_active_quantity (is_active, quantity), UNIQUE INDEX uniq_products_sku (sku), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE products');
    }
}
