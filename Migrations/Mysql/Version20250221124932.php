<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250221124932 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySqlPlatform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySqlPlatform'."
        );

        $this->addSql('CREATE TABLE sitegeist_lostintranslation_domain_model_glossary (persistence_object_identifier VARCHAR(40) NOT NULL, sourcelanguagekey VARCHAR(255) NOT NULL, targetlanguagekey VARCHAR(255) NOT NULL, syncronizationdate DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', syncronizationidentifier VARCHAR(255) NOT NULL, modificationdate DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(persistence_object_identifier)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE sitegeist_lostintranslation_domain_model_glossaryentry (persistence_object_identifier VARCHAR(40) NOT NULL, glossary VARCHAR(40) DEFAULT NULL, sourcetext LONGTEXT NOT NULL, targettext LONGTEXT NOT NULL, INDEX IDX_74CB8EB1B0850B43 (glossary), PRIMARY KEY(persistence_object_identifier)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE sitegeist_lostintranslation_domain_model_glossaryentry ADD CONSTRAINT FK_74CB8EB1B0850B43 FOREIGN KEY (glossary) REFERENCES sitegeist_lostintranslation_domain_model_glossary (persistence_object_identifier)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySqlPlatform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySqlPlatform'."
        );

        $this->addSql('ALTER TABLE sitegeist_lostintranslation_domain_model_glossaryentry DROP FOREIGN KEY FK_74CB8EB1B0850B43');
        $this->addSql('DROP TABLE sitegeist_lostintranslation_domain_model_glossary');
        $this->addSql('DROP TABLE sitegeist_lostintranslation_domain_model_glossaryentry');
    }
}
