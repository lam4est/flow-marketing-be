<?php

declare(strict_types=1);

namespace App\Entity\Workflow;

use App\Entity\Traits\CurrentTimestampableEntityTrait;
use App\Repository\Workflow\WorkflowStepRunRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'workflow_step_run')]
#[ORM\Entity(repositoryClass: WorkflowStepRunRepository::class)]
#[ORM\Index(columns: ['status'], name: 'idx_workflow_step_run_status')]
#[ORM\Index(columns: ['scheduled_at'], name: 'idx_workflow_step_run_scheduled_at')]
#[ORM\HasLifecycleCallbacks]
class WorkflowStepRun
{
    use CurrentTimestampableEntityTrait;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SENT = 'sent';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WorkflowRun::class)]
    #[ORM\JoinColumn(name: 'workflow_run_id', nullable: false, onDelete: 'CASCADE')]
    private WorkflowRun $workflowRun;

    #[ORM\ManyToOne(targetEntity: WorkflowStep::class)]
    #[ORM\JoinColumn(name: 'workflow_step_id', nullable: false, onDelete: 'CASCADE')]
    private WorkflowStep $workflowStep;

    #[ORM\ManyToOne(targetEntity: WorkflowStepUser::class)]
    #[ORM\JoinColumn(name: 'workflow_step_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?WorkflowStepUser $workflowStepUser = null;

    #[ORM\Column(name: 'step_order', type: 'smallint', options: ['unsigned' => true])]
    private int $stepOrder;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(name: 'scheduled_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $scheduledAt = null;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $finishedAt = null;

    #[ORM\Column(name: 'channel_used', length: 50)]
    private string $channelUsed;

    #[ORM\Column(name: 'template_id_used', length: 36, nullable: true)]
    private ?string $templateIdUsed = null;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'payload_snapshot', type: 'json', nullable: true)]
    private ?array $payloadSnapshot = null;

    #[ORM\Column(name: 'dispatch_reference', length: 255, nullable: true)]
    private ?string $dispatchReference = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWorkflowRun(): WorkflowRun
    {
        return $this->workflowRun;
    }

    public function setWorkflowRun(WorkflowRun $workflowRun): static
    {
        $this->workflowRun = $workflowRun;

        return $this;
    }

    public function getWorkflowStep(): WorkflowStep
    {
        return $this->workflowStep;
    }

    public function setWorkflowStep(WorkflowStep $workflowStep): static
    {
        $this->workflowStep = $workflowStep;

        return $this;
    }

    public function getWorkflowStepUser(): ?WorkflowStepUser
    {
        return $this->workflowStepUser;
    }

    public function setWorkflowStepUser(?WorkflowStepUser $workflowStepUser): static
    {
        $this->workflowStepUser = $workflowStepUser;

        return $this;
    }

    public function getStepOrder(): int
    {
        return $this->stepOrder;
    }

    public function setStepOrder(int $stepOrder): static
    {
        $this->stepOrder = $stepOrder;

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

    public function getScheduledAt(): ?DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(?DateTimeImmutable $scheduledAt): static
    {
        $this->scheduledAt = $scheduledAt;

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

    public function getChannelUsed(): string
    {
        return $this->channelUsed;
    }

    public function setChannelUsed(string $channelUsed): static
    {
        $this->channelUsed = $channelUsed;

        return $this;
    }

    public function getTemplateIdUsed(): ?string
    {
        return $this->templateIdUsed;
    }

    public function setTemplateIdUsed(?string $templateIdUsed): static
    {
        $this->templateIdUsed = $templateIdUsed;

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

    public function getPayloadSnapshot(): ?array
    {
        return $this->payloadSnapshot;
    }

    public function setPayloadSnapshot(?array $payloadSnapshot): static
    {
        $this->payloadSnapshot = $payloadSnapshot;

        return $this;
    }

    public function getDispatchReference(): ?string
    {
        return $this->dispatchReference;
    }

    public function setDispatchReference(?string $dispatchReference): static
    {
        $this->dispatchReference = $dispatchReference;

        return $this;
    }
}
