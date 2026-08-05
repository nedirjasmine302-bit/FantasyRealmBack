<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute le champ active sur accessory :
 * permet à un employeur d'activer ou de désactiver un accessoire
 * depuis la page management sans le supprimer.
 */
final class Version20260804140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute active sur accessory (activation / désactivation depuis management)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accessory ADD active TINYINT(1) DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accessory DROP active');
    }
}
