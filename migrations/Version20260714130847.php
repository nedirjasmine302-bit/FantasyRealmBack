<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260714130847 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la colonne roles (JSON) sur user';
    }

    public function up(Schema $schema): void
    {
        // Ajout en nullable d'abord, puis remplissage des lignes existantes,
        // puis passage en NOT NULL (JSON ne peut pas avoir de valeur par défaut).
        $this->addSql('ALTER TABLE user ADD roles JSON DEFAULT NULL');
        $this->addSql("UPDATE user SET roles = '[]' WHERE roles IS NULL");
        $this->addSql('ALTER TABLE user MODIFY roles JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user DROP roles');
    }
}
