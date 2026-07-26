<?php

declare(strict_types=1);

namespace InventoryTracker\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create the users table backing API authentication.
 *
 * Charset, collation and engine are stated explicitly rather than inherited
 * from the server default: local MySQL runs with utf8mb4_unicode_ci while RDS
 * defaults to utf8mb4_0900_ai_ci, and username uniqueness is collation
 * dependent. Being explicit keeps the schema identical in both environments.
 */
final class Version20260726010547 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create users table (username unique, argon2id password hash, timestamps)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE users (id INT AUTO_INCREMENT NOT NULL, username VARCHAR(64) NOT NULL, password_hash VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_users_username (username), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE users');
    }
}
