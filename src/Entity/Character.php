<?php

namespace App\Entity;

use App\Repository\CharacterRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CharacterRepository::class)]
// "character" est un mot réservé SQL -> on nomme la table "characters"
#[ORM\Table(name: 'characters')]
class Character
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  #[ORM\Column(length: 100)]
  private ?string $name = null;

  #[ORM\Column(length: 50)]
  private ?string $type = null;

  #[ORM\Column(type: Types::TEXT)]
  private ?string $description = null;

  #[ORM\Column(type: Types::TEXT)]
  private ?string $image = null;

  #[ORM\Column(type: Types::JSON)]
  private array $appearance = [];

  #[ORM\Column(length: 20)]
  private string $status = 'draft';

  #[ORM\Column(length: 100, nullable: true)]
  private ?string $armor = null;

  #[ORM\Column(length: 100, nullable: true)]
  private ?string $weapon = null;

  #[ORM\Column(length: 100, nullable: true)]
  private ?string $relique = null;

  #[ORM\ManyToOne]
  #[ORM\JoinColumn(nullable: false)]
  private ?User $creator = null;

  #[ORM\Column]
  private ?\DateTimeImmutable $createdAt = null;

  public function __construct()
  {
    $this->createdAt = new \DateTimeImmutable();
  }

  public function getId(): ?int
  {
    return $this->id;
  }

  public function getName(): ?string
  {
    return $this->name;
  }

  public function setName(string $name): static
  {
    $this->name = $name;
    return $this;
  }

  public function getType(): ?string
  {
    return $this->type;
  }

  public function setType(string $type): static
  {
    $this->type = $type;
    return $this;
  }

  public function getDescription(): ?string
  {
    return $this->description;
  }

  public function setDescription(string $description): static
  {
    $this->description = $description;
    return $this;
  }

  public function getImage(): ?string
  {
    return $this->image;
  }

  public function setImage(string $image): static
  {
    $this->image = $image;
    return $this;
  }

  public function getAppearance(): array
  {
    return $this->appearance;
  }

  public function setAppearance(array $appearance): static
  {
    $this->appearance = $appearance;
    return $this;
  }

  public function getStatus(): string
  {
    return $this->status;
  }

  public function setStatus(string $status): static
  {
    $this->status = $status;
    return $this;
  }

  public function getArmor(): ?string
  {
    return $this->armor;
  }

  public function setArmor(?string $armor): static
  {
    $this->armor = $armor;
    return $this;
  }

  public function getWeapon(): ?string
  {
    return $this->weapon;
  }

  public function setWeapon(?string $weapon): static
  {
    $this->weapon = $weapon;
    return $this;
  }

  public function getRelique(): ?string
  {
    return $this->relique;
  }

  public function setRelique(?string $relique): static
  {
    $this->relique = $relique;
    return $this;
  }

  public function getCreator(): ?User
  {
    return $this->creator;
  }

  public function setCreator(?User $creator): static
  {
    $this->creator = $creator;
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
}
