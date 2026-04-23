<?php

declare(strict_types=1);

namespace App\Entity\Workflow;

use App\Entity\Traits\CurrentTimestampableEntityTrait;
use App\Repository\Workflow\WorkflowRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'workflows')]
#[ORM\Entity(repositoryClass: WorkflowRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Workflow
{
    use CurrentTimestampableEntityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(name: 'workflow_key', length: 100)]
    private string $workflowKey;

    #[ORM\Column(name: 'workflow_name', length: 255)]
    private string $workflowName;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getWorkflowKey(): string
    {
        return $this->workflowKey;
    }

    public function setWorkflowKey(string $workflowKey): static
    {
        $this->workflowKey = $workflowKey;

        return $this;
    }

    public function getWorkflowName(): string
    {
        return $this->workflowName;
    }

    public function setWorkflowName(string $workflowName): static
    {
        $this->workflowName = $workflowName;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }
}
