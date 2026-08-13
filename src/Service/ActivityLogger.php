<?php

namespace App\Service;

use App\Entity\User;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\BSON\UTCDateTime;

// Service qui enregistre et relit le journal d'activité dans MongoDB.
class ActivityLogger
{
  private const USER_TYPE_LABELS = [
    'player' => 'Joueur',
    'employer' => 'Employé',
    'admin' => 'Administrateur',
  ];

  private ?Collection $collection = null;

  public function __construct(
    private string $mongoUrl,
    private string $databaseName,
    private string $collectionName = 'activity_logs'
  ) {
  }


  // Enregistre une action dans le journal.
  public function log(?User $actor, string $action, string $label, string $message): void
  {
    try {
      $this->getCollection()->insertOne([
        'userType' => $this->resolveUserType($actor),
        'actorPseudo' => $actor?->getPseudo() ?? 'Inconnu',
        'action' => $action,
        'label' => $label,
        'message' => $message,
        'createdAt' => new UTCDateTime(),
      ]);
    } catch (\Throwable $e) {
      // On n'interrompt pas l'action de l'utilisateur si l'écriture du log échoue.
    }
  }


  // Relit tout le journal, du plus récent au plus ancien, sous forme de tableaux prêts pour le JSON.
  public function findAll(): array
  {
    try {
      $cursor = $this->getCollection()->find([], ['sort' => ['createdAt' => -1]]);

      return array_map(
        fn ($doc) => $this->serializeLog($doc),
        iterator_to_array($cursor)
      );
    } catch (\Throwable $e) {
      return [];
    }
  }


  // Construit le début d'une phrase de log
  public function actorLabel(?User $actor): string
  {
    $pseudo = $actor?->getPseudo() ?? 'Inconnu';

    return match ($this->resolveUserType($actor)) {
      'admin' => 'L\'administrateur ' . $pseudo,
      'employer' => 'L\'employé ' . $pseudo,
      default => 'Le joueur ' . $pseudo,
    };
  }


  // Déduit le type d'utilisateur à partir de ses rôles
  private function resolveUserType(?User $actor): string
  {
    if (!$actor) {
      return 'player';
    }

    $roles = $actor->getRoles();

    if (in_array('ROLE_ADMIN', $roles, true)) {
      return 'admin';
    }

    if (in_array('ROLE_EMPLOYER', $roles, true)) {
      return 'employer';
    }

    return 'player';
  }


  // Transforme un document MongoDB en tableau JSON
  private function serializeLog($doc): array
  {
    $userType = $doc['userType'] ?? 'player';
    $createdAt = $doc['createdAt'] ?? null;

    return [
      'id' => (string) $doc['_id'],
      'userType' => $userType,
      'userTypeLabel' => self::USER_TYPE_LABELS[$userType] ?? $userType,
      'actorPseudo' => $doc['actorPseudo'] ?? 'Inconnu',
      'action' => $doc['action'] ?? '',
      'label' => $doc['label'] ?? '',
      'message' => $doc['message'] ?? '',
      'createdAt' => $createdAt instanceof UTCDateTime
        ? $createdAt->toDateTime()->format(\DateTimeInterface::ATOM)
        : null,
    ];
  }


  // Connexion paresseuse à la collection MongoDB
  private function getCollection(): Collection
  {
    if ($this->collection === null) {
      $this->collection = (new Client($this->mongoUrl))
        ->getDatabase($this->databaseName)
        ->getCollection($this->collectionName);
    }

    return $this->collection;
  }
}
