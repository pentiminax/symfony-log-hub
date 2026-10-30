<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20261030000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop log_record, api_key and project: logs, projects and API keys now live in the engine (fresh start, no data migration)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS log_record');
        $this->addSql('DROP TABLE IF EXISTS api_key');
        $this->addSql('DROP TABLE IF EXISTS project');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('The dropped data is not recoverable.');
    }
}
