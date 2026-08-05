<?php

namespace App\Entity;

use App\Repository\AccessoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccessoryRepository::class)]
class Accessory
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  #[ORM\Column(length: 100)]
  private ?string $name = null;

  #[ORM\Column(length: 50)]
  private ?string $type = null;

  #[ORM\Column(length: 20)]
  private ?string $rarity = null;

  #[ORM\Column(type: Types::TEXT)]
  private ?string $description = null;

  #[ORM\Column(type: Types::TEXT)]
  private ?string $image = null;

  #[ORM\ManyToOne]
  #[ORM\JoinColumn(nullable: false)]
  private ?User $creator = null;

  #[ORM\Column(options: ['default' => true])]
  private bool $active = true;

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

  public function getRarity(): ?string
  {
    return $this->rarity;
  }

  public function setRarity(string $rarity): static
  {
    $this->rarity = $rarity;
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

  public function getCreator(): ?User
  {
    return $this->creator;
  }

  public function setCreator(?User $creator): static
  {
    $this->creator = $creator;
    return $this;
  }

  public function isActive(): bool
  {
    return $this->active;
  }

  public function setActive(bool $active): static
  {
    $this->active = $active;
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
