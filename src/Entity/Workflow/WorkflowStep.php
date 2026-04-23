<?php

declare(strict_types=1);

namespace App\Entity\Workflow;

use App\Entity\Traits\CurrentTimestampableEntityTrait;
use App\Repository\Workflow\WorkflowStepRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'workflow_step')]
#[ORM\Entity(repositoryClass: WorkflowStepRepository::class)]
#[ORM\HasLifecycleCallbacks]
class WorkflowStep
{
    use CurrentTimestampableEntityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Workflow::class)]
    #[ORM\JoinColumn(name: 'workflow_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Workflow $workflow;

    #[ORM\Column(type: 'smallint')]
    private int $status = 0;

    #[ORM\Column(name: 'step_order', type: 'smallint', options: ['unsigned' => true])]
    private int $stepOrder;

    #[ORM\Column(length: 50)]
    private string $channel;

    #[ORM\Column(name: 'delay_unit', length: 20, nullable: true)]
    private ?string $delayUnit = null;

    #[ORM\Column(name: 'delay_value', nullable: true)]
    private ?int $delayValue = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): static
    {
        $this->status = $status;

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

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function setChannel(string $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function getDelayUnit(): ?string
    {
        return $this->delayUnit;
    }

    public function setDelayUnit(?string $delayUnit): static
    {
        $this->delayUnit = $delayUnit;

        return $this;
    }

    public function getDelayValue(): ?int
    {
        return $this->delayValue;
    }

    public function setDelayValue(?int $delayValue): static
    {
        $this->delayValue = $delayValue;

        return $this;
    }
}
