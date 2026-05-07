<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\CurrentTimestampableEntityTrait;
use App\Repository\MarketingCampaignRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MarketingCampaignRepository::class)]
#[ORM\Table(name: 'marketing_campaign')]
#[ORM\HasLifecycleCallbacks]
class MarketingCampaign
{
    use CurrentTimestampableEntityTrait;

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENDING = 'sending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_PAUSED = 'paused';

    public const SCHEDULE_MANUAL = 'manual';
    public const SCHEDULE_ONCE = 'once';
    public const SCHEDULE_CRON = 'cron';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20)]
    private string $channel = self::CHANNEL_EMAIL;

    #[ORM\Column(length: 30)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\ManyToOne(targetEntity: ContactList::class)]
    #[ORM\JoinColumn(name: 'contact_list_id', nullable: true, onDelete: 'SET NULL')]
    private ?ContactList $contactList = null;

    #[ORM\ManyToOne(targetEntity: ContentTemplate::class)]
    #[ORM\JoinColumn(name: 'content_template_id', nullable: true, onDelete: 'SET NULL')]
    private ?ContentTemplate $contentTemplate = null;

    #[ORM\Column(name: 'schedule_mode', length: 20)]
    private string $scheduleMode = self::SCHEDULE_MANUAL;

    #[ORM\Column(name: 'scheduled_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $scheduledAt = null;

    #[ORM\Column(name: 'cron_expression', length: 120, nullable: true)]
    private ?string $cronExpression = null;

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getContactList(): ?ContactList
    {
        return $this->contactList;
    }

    public function setContactList(?ContactList $contactList): static
    {
        $this->contactList = $contactList;

        return $this;
    }

    public function getContentTemplate(): ?ContentTemplate
    {
        return $this->contentTemplate;
    }

    public function setContentTemplate(?ContentTemplate $contentTemplate): static
    {
        $this->contentTemplate = $contentTemplate;

        return $this;
    }

    public function getScheduleMode(): string
    {
        return $this->scheduleMode;
    }

    public function setScheduleMode(string $scheduleMode): static
    {
        $this->scheduleMode = $scheduleMode;

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

    public function getCronExpression(): ?string
    {
        return $this->cronExpression;
    }

    public function setCronExpression(?string $cronExpression): static
    {
        $this->cronExpression = $cronExpression;

        return $this;
    }
}
