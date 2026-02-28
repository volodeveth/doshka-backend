<?php

namespace App\Entity;

use App\Repository\CardLabelRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: CardLabelRepository::class)]
#[ORM\Table(name: 'card_labels')]
#[ORM\UniqueConstraint(name: 'unique_card_label', columns: ['card_id', 'label_id'])]
class CardLabel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['card:read', 'board:read', 'list:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Card::class, inversedBy: 'cardLabels')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Card $card = null;

    #[ORM\ManyToOne(targetEntity: Label::class, inversedBy: 'cardLabels')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['card:read', 'board:read', 'list:read'])]
    private ?Label $label = null;

    public function getId(): ?int { return $this->id; }

    public function getCard(): ?Card { return $this->card; }
    public function setCard(?Card $card): static { $this->card = $card; return $this; }

    public function getLabel(): ?Label { return $this->label; }
    public function setLabel(?Label $label): static { $this->label = $label; return $this; }
}
