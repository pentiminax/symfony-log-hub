<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create log_record table (LogHub canonical log storage, spec 05-domain-data-model.md)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE log_record (
                id                UUID NOT NULL,
                project_id        UUID NOT NULL,
                timestamp         TIMESTAMPTZ NOT NULL,
                severity_text     VARCHAR(16) NOT NULL,
                severity_number   SMALLINT NOT NULL,
                message           TEXT NOT NULL,
                channel           VARCHAR(100) DEFAULT NULL,
                service_name      VARCHAR(255) NOT NULL,
                service_version   VARCHAR(100) DEFAULT NULL,
                environment       VARCHAR(100) DEFAULT NULL,
                host_name         VARCHAR(255) DEFAULT NULL,
                trace_id          VARCHAR(32) DEFAULT NULL,
                span_id           VARCHAR(16) DEFAULT NULL,
                resource          JSONB NOT NULL,
                attributes        JSONB NOT NULL,
                exception         JSONB DEFAULT NULL,
                ingested_at       TIMESTAMPTZ NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        $this->addSql('CREATE INDEX idx_log_project_timestamp ON log_record (project_id, timestamp DESC)');
        $this->addSql('CREATE INDEX idx_log_project_severity_timestamp ON log_record (project_id, severity_number, timestamp DESC)');
        $this->addSql('CREATE INDEX idx_log_project_service_timestamp ON log_record (project_id, service_name, timestamp DESC)');
        $this->addSql('CREATE INDEX idx_log_project_environment_timestamp ON log_record (project_id, environment, timestamp DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE log_record');
    }
}
