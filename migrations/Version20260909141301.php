<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909141301 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create project and api_key tables (LogHub project & API key management, spec 05-domain-data-model.md, spec 08-security-retention.md)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE project (
                id                UUID NOT NULL,
                name              VARCHAR(255) NOT NULL,
                retention_days    INT DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE api_key (
                id                UUID NOT NULL,
                project_id        UUID NOT NULL,
                hashed_key        VARCHAR(64) NOT NULL,
                label             VARCHAR(255) NOT NULL,
                created_at        TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                revoked_at        TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_api_key_hashed_key ON api_key (hashed_key)');
        $this->addSql('CREATE INDEX idx_api_key_project_id ON api_key (project_id)');
        $this->addSql('ALTER TABLE api_key ADD CONSTRAINT fk_api_key_project FOREIGN KEY (project_id) REFERENCES project (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE api_key');
        $this->addSql('DROP TABLE project');
    }
}
