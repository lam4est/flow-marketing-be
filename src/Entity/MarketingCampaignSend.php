<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\CurrentTimestampableEntityTrait;
use App\Repository\MarketingCampaignSendRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MarketingCampaignSendRepository::class)]
#[ORM\Table(name: 'marketing_campaign_send')]
#[ORM\HasLifecycleCallbacks]
class MarketingCampaignSend
{
    use CurrentTimestampableEntityTrait;

    public const TRIGGER_MANUAL_API = 'manual_api';
    public const TRIGGER_SCHEDULER = 'scheduler';

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MarketingCampaign::class)]
    #[ORM\JoinColumn(name: 'marketing_campaign_id', nullable: false, onDelete: 'CASCADE')]
    private MarketingCampaign $marketingCampaign;

    #[ORM\Column(name: 'trigger_source', length: 30)]
    private string $triggerSource = self::TRIGGER_MANUAL_API;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_COMPLETED;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $summary = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMarketingCampaign(): MarketingCampaign
    {
        return $this->marketingCampaign;
    }

    public function setMarketingCampaign(MarketingCampaign $marketingCampaign): static
    {
        $this->marketingCampaign = $marketingCampaign;

        return $this;
    }

    public function getTriggerSource(): string
    {
        return $this->triggerSource;
    }

    public function setTriggerSource(string $triggerSource): static
    {
        $this->triggerSource = $triggerSource;

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

    /** @return array<string, mixed>|null */
    public function getSummary(): ?array
    {
        return $this->summary;
    }

    /** @param array<string, mixed>|null $summary */
    public function setSummary(?array $summary): static
    {
        $this->summary = $summary;

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
