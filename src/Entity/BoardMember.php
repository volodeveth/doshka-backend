<?php

namespace App\Entity;

use App\Repository\BoardMemberRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BoardMemberRepository::class)]
#[ORM\Table(name: 'board_members')]
#[ORM\UniqueConstraint(name: 'unique_board_member', columns: ['board_id', 'user_id'])]
class BoardMember
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';

    public const ROLES = [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_MEMBER];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['board:read', 'member:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Board::class, inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Board $board = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'boardMemberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['board:read', 'member:read'])]
    private ?User $user = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [BoardMember::ROLE_OWNER, BoardMember::ROLE_ADMIN, BoardMember::ROLE_MEMBER])]
    #[Groups(['board:read', 'member:read', 'member:write'])]
    private string $role = self::ROLE_MEMBER;

    public function getId(): ?int { return $this->id; }

    public function getBoard(): ?Board { return $this->board; }
    public function setBoard(?Board $board): static { $this->board = $board; return $this; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getRole(): string { return $this->role; }
    public function setRole(string $role): static { $this->role = $role; return $this; }

    public function isOwner(): bool { return $this->role === self::ROLE_OWNER; }
    public function isAdmin(): bool { return $this->role === self::ROLE_ADMIN; }
    public function canManage(): bool { return in_array($this->role, [self::ROLE_OWNER, self::ROLE_ADMIN]); }
}
