<?php

namespace App\Entity;

use App\Repository\BoardRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BoardRepository::class)]
#[ORM\Table(name: 'boards')]
#[ORM\HasLifecycleCallbacks]
class Board
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['board:read', 'card:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[Groups(['board:read', 'board:write', 'card:read'])]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['board:read', 'board:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\Regex(pattern: '/^#[0-9A-Fa-f]{6}$/', message: 'Color must be a valid hex color (e.g. #ff5733)')]
    #[Groups(['board:read', 'board:write'])]
    private ?string $color = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'ownedBoards')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['board:read'])]
    private ?User $owner = null;

    #[ORM\Column]
    #[Groups(['board:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\OneToMany(mappedBy: 'board', targetEntity: BoardMember::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    #[Groups(['board:read'])]
    private Collection $members;

    #[ORM\OneToMany(mappedBy: 'board', targetEntity: BoardList::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Groups(['board:read'])]
    private Collection $lists;

    #[ORM\OneToMany(mappedBy: 'board', targetEntity: Label::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    #[Groups(['board:read'])]
    private Collection $labels;

    #[ORM\OneToMany(mappedBy: 'board', targetEntity: Activity::class, orphanRemoval: true)]
    private Collection $activities;

    public function __construct()
    {
        $this->members = new ArrayCollection();
        $this->lists = new ArrayCollection();
        $this->labels = new ArrayCollection();
        $this->activities = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): static { $this->color = $color; return $this; }

    public function getOwner(): ?User { return $this->owner; }
    public function setOwner(?User $owner): static { $this->owner = $owner; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    public function getMembers(): Collection { return $this->members; }

    public function addMember(BoardMember $member): static
    {
        if (!$this->members->contains($member)) {
            $this->members->add($member);
            $member->setBoard($this);
        }
        return $this;
    }

    public function removeMember(BoardMember $member): static
    {
        if ($this->members->removeElement($member)) {
            if ($member->getBoard() === $this) {
                $member->setBoard(null);
            }
        }
        return $this;
    }

    public function getLists(): Collection { return $this->lists; }

    public function addList(BoardList $list): static
    {
        if (!$this->lists->contains($list)) {
            $this->lists->add($list);
            $list->setBoard($this);
        }
        return $this;
    }

    public function removeList(BoardList $list): static
    {
        $this->lists->removeElement($list);
        return $this;
    }

    public function getLabels(): Collection { return $this->labels; }

    public function addLabel(Label $label): static
    {
        if (!$this->labels->contains($label)) {
            $this->labels->add($label);
            $label->setBoard($this);
        }
        return $this;
    }

    public function removeLabel(Label $label): static
    {
        $this->labels->removeElement($label);
        return $this;
    }

    public function getActivities(): Collection { return $this->activities; }
}
