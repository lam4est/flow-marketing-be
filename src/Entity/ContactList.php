<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\CurrentTimestampableEntityTrait;
use App\Repository\ContactListRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContactListRepository::class)]
#[ORM\Table(name: 'contact_list')]
#[ORM\HasLifecycleCallbacks]
class ContactList
{
    use CurrentTimestampableEntityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(name: 'contacts_count', options: ['default' => 0])]
    private int $contactsCount = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getContactsCount(): int
    {
        return $this->contactsCount;
    }

    public function setContactsCount(int $contactsCount): static
    {
        $this->contactsCount = $contactsCount;

        return $this;
    }
}
