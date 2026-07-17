<?php

namespace App\Entity;

use App\Repository\CommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommentRepository::class)]
class Comment
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  #[ORM\Column(type: Types::TEXT)]
  private ?string $message = null;

  #[ORM\Column]
  private ?int $rating = null;

  #[ORM\Column(length: 20)]
  private string $status = 'pending';

  #[ORM\ManyToOne]
  #[ORM\JoinColumn(nullable: false)]
  private ?User $author = null;

  #[ORM\ManyToOne]
  #[ORM\JoinColumn(nullable: false)]
  private ?Character $character = null;

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

  public function getMessage(): ?string
  {
    return $this->message;
  }

  public function setMessage(string $message): static
  {
    $this->message = $message;
    return $this;
  }

  public function getRating(): ?int
  {
    return $this->rating;
  }

  public function setRating(int $rating): static
  {
    $this->rating = $rating;
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

  public function getAuthor(): ?User
  {
    return $this->author;
  }

  public function setAuthor(?User $author): static
  {
    $this->author = $author;
    return $this;
  }

  public function getCharacter(): ?Character
  {
    return $this->character;
  }

  public function setCharacter(?Character $character): static
  {
    $this->character = $character;
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
