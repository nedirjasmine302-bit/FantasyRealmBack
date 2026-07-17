<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  #[ORM\Column(length: 180)]
  private ?string $email = null;

  #[ORM\Column(length: 50)]
  private ?string $pseudo = null;

  #[ORM\Column(length: 255)]
  private ?string $password = null;

  #[ORM\Column]
  private array $roles = [];

  #[ORM\Column]
  private ?\DateTimeImmutable $createdAt = null;

  #[ORM\Column(length: 255, nullable: true)]
  private ?string $temporaryPassword = null;

  #[ORM\Column(nullable: true)]
  private ?\DateTimeImmutable $temporaryPasswordExpiresAt = null;

  /**
   * @var Collection<int, Character>
   */
  #[ORM\ManyToMany(targetEntity: Character::class)]
  #[ORM\JoinTable(name: 'favorites')]
  private Collection $favorites;

  public function __construct()
  {
    $this->createdAt = new \DateTimeImmutable();
    $this->favorites = new ArrayCollection();
  }

  public function getId(): ?int
  {
    return $this->id;
  }

  public function getEmail(): ?string
  {
    return $this->email;
  }

  public function setEmail(string $email): static
  {
    $this->email = $email;
    return $this;
  }

  public function getPseudo(): ?string
  {
    return $this->pseudo;
  }

  public function setPseudo(string $pseudo): static
  {
    $this->pseudo = $pseudo;
    return $this;
  }

  public function getPassword(): ?string
  {
    return $this->password;
  }

  public function setPassword(string $password): static
  {
    $this->password = $password;
    return $this;
  }

  public function getCreatedAt(): ?\DateTimeImmutable
  {
    return $this->createdAt;
  }

  public function setCreatedAt(\DateTimeImmutable $createdAt): static
  {
    $this->createdAt = $createdAt;
    return $this;
  }

  public function getTemporaryPassword(): ?string
  {
    return $this->temporaryPassword;
  }

  public function setTemporaryPassword(?string $temporaryPassword): static
  {
    $this->temporaryPassword = $temporaryPassword;
    return $this;
  }

  public function getTemporaryPasswordExpiresAt(): ?\DateTimeImmutable
  {
    return $this->temporaryPasswordExpiresAt;
  }

  public function setTemporaryPasswordExpiresAt(?\DateTimeImmutable $temporaryPasswordExpiresAt): static
  {
    $this->temporaryPasswordExpiresAt = $temporaryPasswordExpiresAt;
    return $this;
  }

  /**
   * @return Collection<int, Character>
   */
  public function getFavorites(): Collection
  {
    return $this->favorites;
  }

  public function addFavorite(Character $favorite): static
  {
    if (!$this->favorites->contains($favorite)) {
      $this->favorites->add($favorite);
    }

    return $this;
  }

  public function removeFavorite(Character $favorite): static
  {
    $this->favorites->removeElement($favorite);
    return $this;
  }

  public function getUserIdentifier(): string
  {
    return $this->email;
  }

  public function getRoles(): array
  {
    $roles = $this->roles;
    $roles[] = 'ROLE_USER';

    return array_unique($roles);
  }

  public function setRoles(array $roles): static
  {
    $this->roles = $roles;
    return $this;
  }

  public function eraseCredentials(): void
  {
    // rien à effacer
  }
}
