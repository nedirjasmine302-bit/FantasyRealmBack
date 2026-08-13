<?php

namespace App\Controller\Api;

use App\Entity\Character;
use App\Entity\User;
use App\Repository\CharacterRepository;
use App\Repository\CommentRepository;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api', name: 'api_')]
class CharacterController extends AbstractController
{
  // Classes de personnage autorisées (value => label)
  private const CLASSES = [
    'warrior' => 'Guerrier',
    'archer' => 'Archer',
    'mage' => 'Mage',
    'paladin' => 'Paladin',
    'wizard' => 'Enchanteur',
    'druid' => 'Druide',
  ];

  // Valeurs d'apparence autorisées, par attribut (value => label)
  private const APPEARANCE = [
    'hairColor' => ['blond' => 'Blond', 'brun' => 'Brun', 'noir' => 'Noir', 'roux' => 'Roux'],
    'eyeColor' => ['bleu' => 'Bleu', 'vert' => 'Vert', 'marron' => 'Marron', 'noisette' => 'Noisette'],
    'skinColor' => ['clair' => 'Clair', 'medium' => 'Medium', 'fonce' => 'Foncé'],
    'mouthShape' => ['fine' => 'Fine', 'normale' => 'Normale', 'pulpeuse' => 'Pulpeuse'],
    'eyeShape' => ['ronds' => 'Ronds', 'amande' => 'En amande', 'fermes' => 'Fermés'],
    'noseShape' => ['fin' => 'Fin', 'large' => 'Large', 'pointu' => 'Pointu'],
  ];


  // Pour créer un personnage
  #[Route('/characters', name: 'characters_create', methods: ['POST'])]
  public function create(Request $request, EntityManagerInterface $em, ActivityLogger $logger): JsonResponse
  {
    $user = $this->getUser();

    if (!$user instanceof User) {
      return $this->json([
        'success' => false,
        'message' => 'Vous devez être connecté pour créer un personnage.'
      ], 401);
    }

    $data = json_decode($request->getContent(), true);

    $name = trim($data['name'] ?? '');
    $type = trim($data['type'] ?? $data['gender'] ?? '');
    $description = trim($data['description'] ?? '');
    $image = $data['image'] ?? null;
    $appearance = $data['appearance'] ?? [];

    if (mb_strlen($name) < 3) {
      return $this->json([
        'success' => false,
        'message' => 'Le nom doit contenir au moins 3 caractères.'
      ], 400);
    }

    if ($type === '') {
      return $this->json([
        'success' => false,
        'message' => 'Le type du personnage est obligatoire.'
      ], 400);
    }

    if (!isset(self::CLASSES[$type])) {
      return $this->json([
        'success' => false,
        'message' => 'La classe du personnage est invalide.'
      ], 400);
    }

    $appearance = is_array($appearance) ? $appearance : [];

    if ($appearanceError = $this->validateAppearance($appearance)) {
      return $this->json([
        'success' => false,
        'message' => $appearanceError
      ], 400);
    }

    if (mb_strlen($description) < 30) {
      return $this->json([
        'success' => false,
        'message' => 'La description doit contenir au moins 30 caractères.'
      ], 400);
    }

    if (!$image) {
      return $this->json([
        'success' => false,
        'message' => 'L\'image du personnage est obligatoire.'
      ], 400);
    }

    $character = new Character();
    $character->setName($name);
    $character->setType($type);
    $character->setDescription($description);
    $character->setImage($image);
    $character->setAppearance($appearance);
    $character->setStatus('draft');
    $character->setCreator($user);

    $em->persist($character);
    $em->flush();

    $logger->log(
      $user,
      'create',
      'Création d\'un personnage',
      $logger->actorLabel($user) . ' a créé le personnage "' . $character->getName() . '".'
    );

    return $this->json([
      'success' => true,
      'message' => 'Votre personnage a été créé et est en attente de validation.',
      'character' => $this->serializeCharacter($character)
    ], 201);
  }


  // Pour lister les personnages
  #[Route('/characters', name: 'characters_list', methods: ['GET'])]
  public function list(CharacterRepository $repo): JsonResponse
  {
    $characters = $repo->findBy([], ['createdAt' => 'DESC']);

    return $this->json([
      'characters' => array_map(
        fn (Character $c) => $this->serializeCharacter($c),
        $characters
      )
    ], 200);
  }


  // Pour afficher le détail d'un personnage
  #[Route('/characters/{id}', name: 'characters_show', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function show(int $id, CharacterRepository $repo): JsonResponse
  {
    $character = $repo->find($id);

    if (!$character) {
      return $this->json([
        'success' => false,
        'message' => 'Personnage introuvable.'
      ], 404);
    }

    return $this->json([
      'character' => $this->serializeCharacter($character)
    ], 200);
  }


  // Pour modifier un personnage
  #[Route('/characters/{id}', name: 'characters_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
  public function update(
    int $id,
    Request $request,
    CharacterRepository $repo,
    EntityManagerInterface $em,
    ActivityLogger $logger
  ): JsonResponse {
    $user = $this->getUser();
    $character = $repo->find($id);

    if (!$user instanceof User) {
      return $this->json([
        'success' => false,
        'message' => 'Utilisateur non authentifié.'
      ], 401);
    }

    if (!$character) {
      return $this->json([
        'success' => false,
        'message' => 'Personnage introuvable.'
      ], 404);
    }

    if ($character->getCreator()?->getId() !== $user->getId()) {
      return $this->json([
        'success' => false,
        'message' => 'Ce personnage ne vous appartient pas.'
      ], 403);
    }

    $data = json_decode($request->getContent(), true) ?? [];

    $mustRevalidate = false;

    if (isset($data['name'])) {
      $name = trim($data['name']);

      if (mb_strlen($name) < 3) {
        return $this->json([
          'success' => false,
          'message' => 'Le nom doit contenir au moins 3 caractères.'
        ], 400);
      }

      if ($name !== $character->getName()) {
        $mustRevalidate = true;
      }

      $character->setName($name);
    }

    if (isset($data['type'])) {
      $type = trim($data['type']);

      if (!isset(self::CLASSES[$type])) {
        return $this->json([
          'success' => false,
          'message' => 'La classe du personnage est invalide.'
        ], 400);
      }

      if ($type !== $character->getType()) {
        $mustRevalidate = true;
      }

      $character->setType($type);
    }

    if (isset($data['description'])) {
      $description = trim($data['description']);

      if (mb_strlen($description) < 30) {
        return $this->json([
          'success' => false,
          'message' => 'La description doit contenir au moins 30 caractères.'
        ], 400);
      }

      if ($description !== $character->getDescription()) {
        $mustRevalidate = true;
      }

      $character->setDescription($description);
    }

    if (isset($data['image'])) {
      if ($data['image'] !== $character->getImage()) {
        $mustRevalidate = true;
      }

      $character->setImage($data['image']);
    }

    if (isset($data['appearance']) && is_array($data['appearance'])) {
      if ($appearanceError = $this->validateAppearance($data['appearance'])) {
        return $this->json([
          'success' => false,
          'message' => $appearanceError
        ], 400);
      }

      if ($data['appearance'] != $character->getAppearance()) {
        $mustRevalidate = true;
      }

      $character->setAppearance($data['appearance']);
    }

    if ($character->getStatus() === 'valid') {
      if (array_key_exists('armor', $data)) {
        $character->setArmor($data['armor']);
      }

      if (array_key_exists('weapon', $data)) {
        $character->setWeapon($data['weapon']);
      }

      if (array_key_exists('relique', $data)) {
        $character->setRelique($data['relique']);
      }
    }

    if ($mustRevalidate) {
      $character->setStatus('draft');
      $character->setShared(false);
      $this->purgeFavorites($em, $character->getId());
    }

    $em->flush();

    $logger->log(
      $user,
      'update',
      'Modification d\'un personnage',
      $logger->actorLabel($user) . ' a modifié le personnage "' . $character->getName() . '".'
    );

    return $this->json([
      'success' => true,
      'message' => 'Personnage mis à jour.',
      'character' => $this->serializeCharacter($character)
    ], 200);
  }


  // Pour changer le statut d'un personnage
  #[Route('/characters/{id}/status', name: 'characters_status', methods: ['PATCH'], requirements: ['id' => '\d+'])]
  #[IsGranted('ROLE_EMPLOYER')]
  public function updateStatus(
    int $id,
    Request $request,
    CharacterRepository $repo,
    EntityManagerInterface $em,
    MailerInterface $mailer,
    ActivityLogger $logger
  ): JsonResponse {
    $character = $repo->find($id);

    if (!$character) {
      return $this->json([
        'success' => false,
        'message' => 'Personnage introuvable.'
      ], 404);
    }

    $data = json_decode($request->getContent(), true) ?? [];
    $status = $data['status'] ?? null;

    if (!in_array($status, ['pending', 'valid', 'refused'], true)) {
      return $this->json([
        'success' => false,
        'message' => 'Statut invalide (pending, valid ou refused).'
      ], 400);
    }

    $character->setStatus($status);

    if ($status !== 'valid') {
      $this->purgeFavorites($em, $character->getId());
    }

    $em->flush();

    if ($status === 'valid') {
      $this->sendCharacterApprovalMail($mailer, $character);
    }

    $moderator = $this->getUser();
    $verb = ['valid' => 'a validé', 'refused' => 'a refusé', 'pending' => 'a remis en attente'][$status];
    $labelStatus = ['valid' => 'Validation', 'refused' => 'Refus', 'pending' => 'Mise en attente'][$status];
    $logger->log(
      $moderator instanceof User ? $moderator : null,
      'moderate',
      $labelStatus . ' d\'un personnage',
      $logger->actorLabel($moderator instanceof User ? $moderator : null) . ' ' . $verb . ' le personnage "' . $character->getName() . '".'
    );

    return $this->json([
      'success' => true,
      'message' => 'Statut mis à jour.',
      'character' => $this->serializeCharacter($character)
    ], 200);
  }


  // Pour refuser un personnage : motif obligatoire, mail au propriétaire puis suppression définitive
  #[Route('/characters/{id}/reject', name: 'characters_reject', methods: ['POST'], requirements: ['id' => '\d+'])]
  #[IsGranted('ROLE_EMPLOYER')]
  public function reject(
    int $id,
    Request $request,
    CharacterRepository $repo,
    CommentRepository $commentRepo,
    EntityManagerInterface $em,
    MailerInterface $mailer,
    ActivityLogger $logger
  ): JsonResponse {
    $character = $repo->find($id);

    if (!$character) {
      return $this->json([
        'success' => false,
        'message' => 'Personnage introuvable.'
      ], 404);
    }

    $data = json_decode($request->getContent(), true) ?? [];
    $reason = trim($data['reason'] ?? '');

    if (mb_strlen($reason) < 10) {
      return $this->json([
        'success' => false,
        'message' => 'Un motif de refus d\'au moins 10 caractères est obligatoire.'
      ], 400);
    }

    $this->sendCharacterRejectionMail($mailer, $character, $reason);

    $characterName = $character->getName();

    $this->purgeFavorites($em, $character->getId());

    foreach ($commentRepo->findBy(['character' => $character]) as $comment) {
      $em->remove($comment);
    }

    $em->remove($character);
    $em->flush();

    $moderator = $this->getUser();
    $logger->log(
      $moderator instanceof User ? $moderator : null,
      'moderate',
      'Refus d\'un personnage',
      $logger->actorLabel($moderator instanceof User ? $moderator : null) . ' a refusé et supprimé le personnage "' . $characterName . '".'
    );

    return $this->json([
      'success' => true,
      'message' => 'Personnage refusé et supprimé.'
    ], 200);
  }

  // Pour archiver un personnage côté employeur
  #[Route('/characters/{id}/archive', name: 'characters_archive', methods: ['PATCH'], requirements: ['id' => '\d+'])]
  #[IsGranted('ROLE_EMPLOYER')]
  public function archive(int $id, CharacterRepository $repo, EntityManagerInterface $em): JsonResponse
  {
    $character = $repo->find($id);

    if (!$character) {
      return $this->json([
        'success' => false,
        'message' => 'Personnage introuvable.'
      ], 404);
    }

    $character->setArchivedByEmployer(true);
    $em->flush();

    return $this->json([
      'success' => true,
      'message' => 'Personnage retiré de la liste de gestion.'
    ], 200);
  }


  // Pour lister les personnages de l'utilisateur connecté
  #[Route('/my-characters', name: 'characters_mine', methods: ['GET'])]
  public function mine(CharacterRepository $repo): JsonResponse
  {
    $user = $this->getUser();

    if (!$user instanceof User) {
      return $this->json([
        'success' => false,
        'message' => 'Utilisateur non authentifié.'
      ], 401);
    }

    $characters = $repo->findBy(['creator' => $user], ['createdAt' => 'DESC']);

    return $this->json([
      'characters' => array_map(
        fn (Character $c) => $this->serializeCharacter($c),
        $characters
      )
    ], 200);
  }


  // Pour demander la validation de son personnage
  #[Route('/characters/{id}/request-validation', name: 'characters_request_validation', methods: ['POST'], requirements: ['id' => '\d+'])]
  public function requestValidation(int $id, CharacterRepository $repo, EntityManagerInterface $em): JsonResponse
  {
    $user = $this->getUser();
    $character = $repo->find($id);

    if (!$user instanceof User) {
      return $this->json(['success' => false, 'message' => 'Utilisateur non authentifié.'], 401);
    }

    if (!$character) {
      return $this->json(['success' => false, 'message' => 'Personnage introuvable.'], 404);
    }

    if ($character->getCreator()?->getId() !== $user->getId()) {
      return $this->json(['success' => false, 'message' => 'Ce personnage ne vous appartient pas.'], 403);
    }

    if (!in_array($character->getStatus(), ['draft', 'refused'], true)) {
      return $this->json(['success' => false, 'message' => 'Ce personnage ne peut pas être soumis à validation.'], 400);
    }

    $character->setStatus('pending');
    $em->flush();

    return $this->json([
      'success' => true,
      'message' => 'Votre personnage a été envoyé pour validation.',
      'character' => $this->serializeCharacter($character)
    ], 200);
  }


  // Pour partager ou arrêter le partage d'un personnage validé
  #[Route('/characters/{id}/share', name: 'characters_share', methods: ['PATCH'], requirements: ['id' => '\d+'])]
  public function share(int $id, Request $request, CharacterRepository $repo, EntityManagerInterface $em, ActivityLogger $logger): JsonResponse
  {
    $user = $this->getUser();
    $character = $repo->find($id);

    if (!$user instanceof User) {
      return $this->json(['success' => false, 'message' => 'Utilisateur non authentifié.'], 401);
    }

    if (!$character) {
      return $this->json(['success' => false, 'message' => 'Personnage introuvable.'], 404);
    }

    if ($character->getCreator()?->getId() !== $user->getId()) {
      return $this->json(['success' => false, 'message' => 'Ce personnage ne vous appartient pas.'], 403);
    }

    if ($character->getStatus() !== 'valid') {
      return $this->json(['success' => false, 'message' => 'Seul un personnage validé peut être partagé.'], 400);
    }

    $data = json_decode($request->getContent(), true) ?? [];
    $shared = array_key_exists('shared', $data) ? (bool) $data['shared'] : !$character->isShared();

    $character->setShared($shared);
    $em->flush();

    $logger->log(
      $user,
      $shared ? 'publish' : 'unpublish',
      $shared ? 'Publication d\'un personnage' : 'Dépublication d\'un personnage',
      $logger->actorLabel($user) . ($shared
        ? ' a publié le personnage "' . $character->getName() . '".'
        : ' a retiré de la publication le personnage "' . $character->getName() . '".')
    );

    return $this->json([
      'success' => true,
      'message' => $shared ? 'Personnage partagé.' : 'Partage arrêté.',
      'shared' => $shared,
      'character' => $this->serializeCharacter($character)
    ], 200);
  }


  // Pour dupliquer son personnage
  #[Route('/characters/{id}/duplicate', name: 'characters_duplicate', methods: ['POST'], requirements: ['id' => '\d+'])]
  public function duplicate(int $id, CharacterRepository $repo, EntityManagerInterface $em): JsonResponse
  {
    $user = $this->getUser();
    $character = $repo->find($id);

    if (!$user instanceof User) {
      return $this->json(['success' => false, 'message' => 'Utilisateur non authentifié.'], 401);
    }

    if (!$character) {
      return $this->json(['success' => false, 'message' => 'Personnage introuvable.'], 404);
    }

    if ($character->getCreator()?->getId() !== $user->getId()) {
      return $this->json(['success' => false, 'message' => 'Ce personnage ne vous appartient pas.'], 403);
    }

    $copy = new Character();
    $copy->setName($character->getName());
    $copy->setType($character->getType());
    $copy->setDescription($character->getDescription());
    $copy->setImage($character->getImage());
    $copy->setAppearance($character->getAppearance());
    $copy->setStatus('draft');
    $copy->setShared(false);
    $copy->setCreator($user);

    $em->persist($copy);
    $em->flush();

    return $this->json([
      'success' => true,
      'message' => 'Personnage dupliqué.',
      'character' => $this->serializeCharacter($copy)
    ], 201);
  }


  // Pour supprimer un personnage
  #[Route('/characters/{id}', name: 'characters_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
  public function delete(int $id, CharacterRepository $repo, CommentRepository $commentRepo, EntityManagerInterface $em, ActivityLogger $logger): JsonResponse
  {
    $user = $this->getUser();
    $character = $repo->find($id);

    if (!$user instanceof User) {
      return $this->json(['success' => false, 'message' => 'Utilisateur non authentifié.'], 401);
    }

    if (!$character) {
      return $this->json(['success' => false, 'message' => 'Personnage introuvable.'], 404);
    }

    if ($character->getCreator()?->getId() !== $user->getId() && !$this->isGranted('ROLE_EMPLOYER')) {
      return $this->json(['success' => false, 'message' => 'Ce personnage ne vous appartient pas.'], 403);
    }

    $characterName = $character->getName();

    $this->purgeFavorites($em, $character->getId());

    foreach ($commentRepo->findBy(['character' => $character]) as $comment) {
      $em->remove($comment);
    }

    $em->remove($character);
    $em->flush();

    $logger->log(
      $user,
      'delete',
      'Suppression d\'un personnage',
      $logger->actorLabel($user) . ' a supprimé le personnage "' . $characterName . '".'
    );

    return $this->json([
      'success' => true,
      'message' => 'Personnage supprimé.'
    ], 200);
  }


  // Envoie un mail au propriétaire quand son personnage est validé
  private function sendCharacterApprovalMail(MailerInterface $mailer, Character $character): void
  {
    $creator = $character->getCreator();

    if (!$creator || !$creator->getEmail()) {
      return;
    }

    $mail = (new Email())
      ->from('no-reply@fantasyrealm-online.com')
      ->to($creator->getEmail())
      ->subject('Votre personnage a été validé')
      ->text(sprintf(
        "Bonjour %s,\n\nBonne nouvelle : votre personnage \"%s\" a été validé par notre équipe.\nIl est désormais disponible sur FantasyRealm Online.\n\nÀ bientôt !",
        $creator->getPseudo(),
        $character->getName()
      ));

    $mailer->send($mail);
  }


  // Envoie un mail au propriétaire quand son personnage est refusé
  private function sendCharacterRejectionMail(MailerInterface $mailer, Character $character, string $reason): void
  {
    $creator = $character->getCreator();

    if (!$creator || !$creator->getEmail()) {
      return;
    }

    $mail = (new Email())
      ->from('no-reply@fantasyrealm-online.com')
      ->to($creator->getEmail())
      ->subject('Votre personnage a été refusé')
      ->text(sprintf(
        "Bonjour %s,\n\nVotre personnage \"%s\" n'a pas été validé et a été supprimé.\n\nMotif du refus :\n%s\n\nVous pouvez créer un nouveau personnage en tenant compte de cette remarque.\n\nÀ bientôt sur FantasyRealm Online !",
        $creator->getPseudo(),
        $character->getName(),
        $reason
      ));

    $mailer->send($mail);
  }


  // Pour récupérer toutes les options de personnage (classe + apparence)
  #[Route('/character-options', name: 'character_options', methods: ['GET'])]
  public function options(): JsonResponse
  {
    return $this->json([
      'classes' => $this->formatOptions(self::CLASSES),
      'hairColors' => $this->formatOptions(self::APPEARANCE['hairColor']),
      'eyeColors' => $this->formatOptions(self::APPEARANCE['eyeColor']),
      'skinColors' => $this->formatOptions(self::APPEARANCE['skinColor']),
      'mouthShapes' => $this->formatOptions(self::APPEARANCE['mouthShape']),
      'eyeShapes' => $this->formatOptions(self::APPEARANCE['eyeShape']),
      'noseShapes' => $this->formatOptions(self::APPEARANCE['noseShape']),
    ], 200);
  }


  // Transforme une map value => label en liste
  private function formatOptions(array $map): array
  {
    return array_map(
      fn ($value, $label) => ['value' => $value, 'label' => $label],
      array_keys($map),
      array_values($map)
    );
  }


  // Vérifie que chaque attribut d'apparence est connu et a une valeur autorisée
  private function validateAppearance(array $appearance): ?string
  {
    foreach ($appearance as $key => $value) {
      if (!isset(self::APPEARANCE[$key])) {
        return 'Attribut d\'apparence inconnu : ' . $key . '.';
      }

      if (!isset(self::APPEARANCE[$key][$value])) {
        return 'Valeur d\'apparence invalide pour ' . $key . '.';
      }
    }

    return null;
  }


  // Retire un personnage des favoris de tous les utilisateurs
  private function purgeFavorites(EntityManagerInterface $em, int $characterId): void
  {
    $em->getConnection()->executeStatement(
      'DELETE FROM favorites WHERE character_id = :id',
      ['id' => $characterId]
    );
  }


  // Pour transformer un Character en tableau JSON
  private function serializeCharacter(Character $c): array
  {
    return [
      'id' => $c->getId(),
      'name' => $c->getName(),
      'type' => $c->getType(),
      'description' => $c->getDescription(),
      'image' => $c->getImage(),
      'appearance' => $c->getAppearance(),
      'status' => $c->getStatus(),
      'shared' => $c->isShared(),
      'archivedByEmployer' => $c->isArchivedByEmployer(),
      'armor' => $c->getArmor(),
      'weapon' => $c->getWeapon(),
      'relique' => $c->getRelique(),
      'creator' => $c->getCreator()?->getPseudo(),
      'createdAt' => $c->getCreatedAt()?->format(\DateTimeInterface::ATOM)
    ];
  }
}
