<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute le champ archived_by_employer sur characters :
 * permet à un employeur de retirer un personnage de la vue management
 * sans le supprimer (il reste dans l'espace du joueur).
 */
final class Version20260804120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute archived_by_employer sur characters (masquage management sans suppression)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE characters ADD archived_by_employer TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE characters DROP archived_by_employer');
    }
}
