<?php

declare(strict_types=1);

namespace App\Entity\Workflow;

use App\Entity\Traits\CurrentTimestampableEntityTrait;
use App\Repository\Workflow\WorkflowRunRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'workflow_run')]
#[ORM\Entity(repositoryClass: WorkflowRunRepository::class)]
#[ORM\Index(columns: ['status'], name: 'idx_workflow_run_status')]
#[ORM\Index(columns: ['started_at'], name: 'idx_workflow_run_started_at')]
#[ORM\HasLifecycleCallbacks]
class WorkflowRun
{
    use CurrentTimestampableEntityTrait;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WorkflowUser::class)]
    #[ORM\JoinColumn(name: 'workflow_user_id', nullable: false, onDelete: 'CASCADE')]
    private WorkflowUser $workflowUser;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(name: 'trigger_source', length: 50, nullable: true)]
    private ?string $triggerSource = null;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $finishedAt = null;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWorkflowUser(): WorkflowUser
    {
        return $this->workflowUser;
    }

    public function setWorkflowUser(WorkflowUser $workflowUser): static
    {
        $this->workflowUser = $workflowUser;

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

    public function getTriggerSource(): ?string
    {
        return $this->triggerSource;
    }

    public function setTriggerSource(?string $triggerSource): static
    {
        $this->triggerSource = $triggerSource;

        return $this;
    }

    public function getStartedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getFinishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?DateTimeImmutable $finishedAt): static
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }
}
