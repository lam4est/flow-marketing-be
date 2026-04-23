<?php

declare(strict_types=1);

namespace App\Entity\Workflow;

use App\Entity\ContactList;
use App\Entity\Traits\CurrentTimestampableEntityTrait;
use App\Entity\User;
use App\Repository\Workflow\WorkflowUserRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'workflow_user')]
#[ORM\Entity(repositoryClass: WorkflowUserRepository::class)]
#[ORM\HasLifecycleCallbacks]
class WorkflowUser
{
    use CurrentTimestampableEntityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Workflow::class)]
    #[ORM\JoinColumn(name: 'original_workflow_id', nullable: false, onDelete: 'CASCADE')]
    private Workflow $originalWorkflow;

    #[ORM\ManyToOne(targetEntity: Workflow::class)]
    #[ORM\JoinColumn(name: 'workflow_id', nullable: false, onDelete: 'CASCADE')]
    private Workflow $workflow;

    #[ORM\ManyToOne(targetEntity: ContactList::class)]
    #[ORM\JoinColumn(name: 'segment_id', nullable: true, onDelete: 'SET NULL')]
    private ?ContactList $segment = null;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getOriginalWorkflow(): Workflow
    {
        return $this->originalWorkflow;
    }

    public function setOriginalWorkflow(Workflow $originalWorkflow): static
    {
        $this->originalWorkflow = $originalWorkflow;

        return $this;
    }

    public function getWorkflow(): Workflow
    {
        return $this->workflow;
    }

    public function setWorkflow(Workflow $workflow): static
    {
        $this->workflow = $workflow;

        return $this;
    }

    public function getSegment(): ?ContactList
    {
        return $this->segment;
    }

    public function setSegment(?ContactList $segment): static
    {
        $this->segment = $segment;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }
}
