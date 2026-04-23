<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\CurrentTimestampableEntityTrait;
use App\Repository\SchedulerEventHistoryRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SchedulerEventHistoryRepository::class)]
#[ORM\Table(name: 'scheduler_event_history')]
#[ORM\HasLifecycleCallbacks]
class SchedulerEventHistory
{
    use CurrentTimestampableEntityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SchedulerEventSubscription::class)]
    #[ORM\JoinColumn(name: 'scheduler_event_subscription_id', nullable: false, onDelete: 'CASCADE')]
    private SchedulerEventSubscription $schedulerEventSubscription;

    #[ORM\Column(length: 50)]
    private string $status = 'pending';

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'cost_calculation', nullable: true)]
    private ?float $costCalculation = null;

    #[ORM\Column(name: 'executed_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $executedAt = null;

    #[ORM\Column(name: 'content_snapshot', type: 'json', nullable: true)]
    private ?array $contentSnapshot = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSchedulerEventSubscription(): SchedulerEventSubscription
    {
        return $this->schedulerEventSubscription;
    }

    public function setSchedulerEventSubscription(SchedulerEventSubscription $schedulerEventSubscription): static
    {
        $this->schedulerEventSubscription = $schedulerEventSubscription;

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
}
