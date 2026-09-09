<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909175848 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create app_user table (human authentication for the UI, spec 08-security-retention.md §8.1)';
    }

    public function up(Schema $schema): void
    {
        // "user" is reserved in PostgreSQL.
        $this->addSql(<<<'SQL'
            CREATE TABLE app_user (
                id        UUID NOT NULL,
                email     VARCHAR(180) NOT NULL,
                password  VARCHAR(255) NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_app_user_email ON app_user (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_user');
    }
}
