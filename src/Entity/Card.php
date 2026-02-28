<?php

namespace App\Entity;

use App\Repository\CardRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CardRepository::class)]
#[ORM\Table(name: 'cards')]
#[ORM\HasLifecycleCallbacks]
class Card
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['board:read', 'list:read', 'card:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BoardList::class, inversedBy: 'cards')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['card:read'])]
    private ?BoardList $list = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[Groups(['board:read', 'list:read', 'card:read', 'card:write'])]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['card:read', 'card:write'])]
    private ?string $description = null;

    #[ORM\Column]
    #[Groups(['board:read', 'list:read', 'card:read'])]
    private int $position = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['card:read', 'card:write'])]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column]
    #[Groups(['card:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['card:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\OneToMany(mappedBy: 'card', targetEntity: CardMember::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    #[Groups(['card:read'])]
    private Collection $assignees;

    #[ORM\OneToMany(mappedBy: 'card', targetEntity: CardLabel::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    #[Groups(['card:read', 'board:read', 'list:read'])]
    private Collection $cardLabels;

    #[ORM\OneToMany(mappedBy: 'card', targetEntity: Comment::class, orphanRemoval: true)]
    private Collection $comments;

    public function __construct()
    {
        $this->assignees = new ArrayCollection();
        $this->cardLabels = new ArrayCollection();
        $this->comments = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getList(): ?BoardList { return $this->list; }
    public function setList(?BoardList $list): static { $this->list = $list; return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): static { $this->position = $position; return $this; }

    public function getDueDate(): ?\DateTimeImmutable { return $this->dueDate; }
    public function setDueDate(?\DateTimeImmutable $dueDate): static { $this->dueDate = $dueDate; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    public function getAssignees(): Collection { return $this->assignees; }

    public function addAssignee(CardMember $assignee): static
    {
        if (!$this->assignees->contains($assignee)) {
            $this->assignees->add($assignee);
            $assignee->setCard($this);
        }
        return $this;
    }

    public function removeAssignee(CardMember $assignee): static
    {
        $this->assignees->removeElement($assignee);
        return $this;
    }

    public function getCardLabels(): Collection { return $this->cardLabels; }

    public function addCardLabel(CardLabel $cardLabel): static
    {
        if (!$this->cardLabels->contains($cardLabel)) {
            $this->cardLabels->add($cardLabel);
            $cardLabel->setCard($this);
        }
        return $this;
    }

    public function removeCardLabel(CardLabel $cardLabel): static
    {
        $this->cardLabels->removeElement($cardLabel);
        return $this;
    }

    public function getComments(): Collection { return $this->comments; }
}
