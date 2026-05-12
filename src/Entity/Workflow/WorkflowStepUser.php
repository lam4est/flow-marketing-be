<?php

declare(strict_types=1);

namespace App\Entity\Workflow;

use App\Entity\ContactList;
use App\Entity\Traits\CurrentTimestampableEntityTrait;
use App\Entity\User;
use App\Repository\Workflow\WorkflowStepUserRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'workflow_step_user')]
#[ORM\Entity(repositoryClass: WorkflowStepUserRepository::class)]
#[ORM\HasLifecycleCallbacks]
class WorkflowStepUser
{
    use CurrentTimestampableEntityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: WorkflowUser::class)]
    #[ORM\JoinColumn(name: 'workflow_user_id', nullable: false, onDelete: 'CASCADE')]
    private WorkflowUser $workflowUser;

    #[ORM\ManyToOne(targetEntity: WorkflowStep::class)]
    #[ORM\JoinColumn(name: 'workflow_step_id', nullable: false, onDelete: 'CASCADE')]
    private WorkflowStep $workflowStep;

    #[ORM\Column(name: 'template_id', length: 36, nullable: true)]
    private ?string $templateId = null;

    #[ORM\Column(name: 'is_active', options: ['default' => false])]
    private bool $isActive = false;

    #[ORM\Column(name: 'is_confirmed_by_user', options: ['default' => false])]
    private bool $isConfirmedByUser = false;

    #[ORM\Column(length: 50)]
    private string $channel;

    #[ORM\Column(name: 'delay_in_minutes', nullable: true)]
    private ?int $delayInMinutes = null;

    #[ORM\Column(name: 'sending_time', length: 10, nullable: true)]
    private ?string $sendingTime = null;

    #[ORM\ManyToOne(targetEntity: ContactList::class)]
    #[ORM\JoinColumn(name: 'excluded_segment_id', nullable: true, onDelete: 'SET NULL')]
    private ?ContactList $excludedSegment = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $settings = null;

    /** @var list<int>|null Explicit contact ids to exclude from this step (within campaign segment). */
    #[ORM\Column(name: 'excluded_contact_ids', type: 'json', nullable: true)]
    private ?array $excludedContactIds = null;

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

    public function getWorkflowUser(): WorkflowUser
    {
        return $this->workflowUser;
    }

    public function setWorkflowUser(WorkflowUser $workflowUser): static
    {
        $this->workflowUser = $workflowUser;

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

    public function getTemplateId(): ?string
    {
        return $this->templateId;
    }

    public function setTemplateId(?string $templateId): static
    {
        $this->templateId = $templateId;

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

    public function isConfirmedByUser(): bool
    {
        return $this->isConfirmedByUser;
    }

    public function setIsConfirmedByUser(bool $isConfirmedByUser): static
    {
        $this->isConfirmedByUser = $isConfirmedByUser;

        return $this;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function setChannel(string $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function getDelayInMinutes(): ?int
    {
        return $this->delayInMinutes;
    }

    public function setDelayInMinutes(?int $delayInMinutes): static
    {
        $this->delayInMinutes = $delayInMinutes;

        return $this;
    }

    public function getSendingTime(): ?string
    {
        return $this->sendingTime;
    }

    public function setSendingTime(?string $sendingTime): static
    {
        $this->sendingTime = $sendingTime;

        return $this;
    }

    public function getExcludedSegment(): ?ContactList
    {
        return $this->excludedSegment;
    }

    public function setExcludedSegment(?ContactList $excludedSegment): static
    {
        $this->excludedSegment = $excludedSegment;

        return $this;
    }

    public function getSettings(): ?array
    {
        return $this->settings;
    }

    public function setSettings(?array $settings): static
    {
        $this->settings = $settings;

        return $this;
    }

    /**
     * @return list<int>
     */
    public function getExcludedContactIds(): array
    {
        if ($this->excludedContactIds === null || $this->excludedContactIds === []) {
            return [];
        }

        $out = [];
        foreach ($this->excludedContactIds as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $out[] = $n;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<int>|null $excludedContactIds
     */
    public function setExcludedContactIds(?array $excludedContactIds): static
    {
        if ($excludedContactIds === null || $excludedContactIds === []) {
            $this->excludedContactIds = null;

            return $this;
        }
        $out = [];
        foreach ($excludedContactIds as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $out[] = $n;
            }
        }
        $out = array_values(array_unique($out));
        $this->excludedContactIds = $out === [] ? null : $out;

        return $this;
    }
}
