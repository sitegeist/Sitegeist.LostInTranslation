<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250207162120 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            (
                !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MariaDBPlatform
                && !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQLPlatform
            ),
            "Migration can only be executed safely on 'MySQLPlatform or MariaDBPlatform'."
        );

        $this->addSql('CREATE TABLE sitegeist_lostintranslation_highestobservedsequencenumber (cridentifier VARCHAR(255) NOT NULL, sequencenumber INT NOT NULL, PRIMARY KEY(cridentifier)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            (
                !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MariaDBPlatform
                && !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQLPlatform
            ),
            "Migration can only be executed safely on 'MySQLPlatform or MariaDBPlatform'."
        );

        $this->addSql('ALTER TABLE sitegeist_lostintranslation_highestobservedsequencenumber DROP cridentifier, DROP sequencenumber');
    }
}
