<?php
declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Exception;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * Initial migration for the "GlossaryEntry" entity
 */
class Version20230222100005 extends AbstractMigration
{
    /**
     * @throws Exception
     */
    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != "mysql");

        $this->addSql('CREATE TABLE sitegeist_lostintranslation_domain_model_glossaryentry (persistence_object_identifier VARCHAR(40) NOT NULL, aggregateidentifier VARCHAR(36) NOT NULL, lastmodificationdatetime DATETIME NOT NULL, glossarylanguage VARCHAR(2) NOT NULL, text VARCHAR(500) NOT NULL, INDEX aggregate (aggregateidentifier), UNIQUE INDEX sitegeist_lostintranslation_glossaryentry (aggregateidentifier, glossarylanguage), PRIMARY KEY(persistence_object_identifier)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB');
    }

    /**
     * @throws Exception
     */
    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != "mysql");

        $this->addSql('DROP TABLE sitegeist_lostintranslation_domain_model_glossaryentry');
    }
}
