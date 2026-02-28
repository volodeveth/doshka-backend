<?php

namespace App\Entity;

use App\Repository\LabelRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: LabelRepository::class)]
#[ORM\Table(name: 'labels')]
class Label
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['board:read', 'label:read', 'card:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Board::class, inversedBy: 'labels')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Board $board = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Groups(['board:read', 'label:read', 'label:write', 'card:read'])]
    private ?string $name = null;

    #[ORM\Column(length: 7)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^#[0-9A-Fa-f]{6}$/', message: 'Color must be a valid hex color')]
    #[Groups(['board:read', 'label:read', 'label:write', 'card:read'])]
    private ?string $color = null;

    #[ORM\OneToMany(mappedBy: 'label', targetEntity: CardLabel::class, orphanRemoval: true)]
    private Collection $cardLabels;

    public function __construct()
    {
        $this->cardLabels = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getBoard(): ?Board { return $this->board; }
    public function setBoard(?Board $board): static { $this->board = $board; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(string $color): static { $this->color = $color; return $this; }

    public function getCardLabels(): Collection { return $this->cardLabels; }
}
