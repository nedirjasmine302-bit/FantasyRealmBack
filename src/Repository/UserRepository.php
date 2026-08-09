<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
  public function __construct(ManagerRegistry $registry)
  {
    parent::__construct($registry, User::class);
  }

  public function existsByEmail(string $email): bool
  {
    return $this->findOneBy(['email' => $email]) !== null;
  }

  public function matchEmailAndPseudo(string $email, string $pseudo): bool
  {
    return $this->findOneBy([
      'email' => $email,
      'pseudo' => $pseudo
    ]) !== null;
  }

  /**
   * Retourne les joueurs : utilisateurs sans rôle employeur ni administrateur.
   *
   * @return User[]
   */
  public function findPlayers(): array
  {
    return array_values(array_filter(
      $this->findBy([], ['createdAt' => 'DESC']),
      fn (User $u) => !in_array('ROLE_EMPLOYER', $u->getRoles(), true)
        && !in_array('ROLE_ADMIN', $u->getRoles(), true)
    ));
  }

  /**
   * Retourne les employés : utilisateurs avec le rôle employeur mais pas administrateur.
   *
   * @return User[]
   */
  public function findEmployers(): array
  {
    return array_values(array_filter(
      $this->findBy([], ['createdAt' => 'DESC']),
      fn (User $u) => in_array('ROLE_EMPLOYER', $u->getRoles(), true)
        && !in_array('ROLE_ADMIN', $u->getRoles(), true)
    ));
  }
}

