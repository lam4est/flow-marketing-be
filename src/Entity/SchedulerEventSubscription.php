<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\CurrentTimestampableEntityTrait;
use App\Repository\SchedulerEventSubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SchedulerEventSubscriptionRepository::class)]
#[ORM\Table(name: 'scheduler_event_subscription')]
#[ORM\Index(columns: ['user_id'], name: 'idx_user_id')]
#[ORM\Index(columns: ['scheduler_event_id'], name: 'idx_scheduler_event_id')]
#[ORM\Index(columns: ['channel'], name: 'idx_channel')]
#[ORM\HasLifecycleCallbacks]
class SchedulerEventSubscription
{
    use CurrentTimestampableEntityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: SchedulerEvent::class)]
    #[ORM\JoinColumn(name: 'scheduler_event_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SchedulerEvent $schedulerEvent;

    #[ORM\Column(name: 'template_id', length: 255, nullable: true)]
    private ?string $templateId = null;

    #[ORM\Column(length: 50)]
    private string $channel;

    #[ORM\ManyToOne(targetEntity: ContactList::class)]
    #[ORM\JoinColumn(name: 'contact_list_id', nullable: true, onDelete: 'SET NULL')]
    private ?ContactList $contactList = null;

    #[ORM\Column(name: 'estimated_number_of_contacts', options: ['default' => 0])]
    private int $estimatedNumberOfContacts = 0;

    #[ORM\Column(name: 'cost_per_contact', options: ['default' => 0])]
    private float $costPerContact = 0.0;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $hour = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $minute = null;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'days_before', options: ['default' => 0])]
    private int $daysBefore = 0;

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

    public function getSchedulerEvent(): SchedulerEvent
    {
        return $this->schedulerEvent;
    }

    public function setSchedulerEvent(SchedulerEvent $schedulerEvent): static
    {
        $this->schedulerEvent = $schedulerEvent;

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

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function setChannel(string $channel): static
    {
        $this->channel = $channel;

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

    public function getEstimatedNumberOfContacts(): int
    {
        return $this->estimatedNumberOfContacts;
    }

    public function setEstimatedNumberOfContacts(int $estimatedNumberOfContacts): static
    {
        $this->estimatedNumberOfContacts = $estimatedNumberOfContacts;

        return $this;
    }

    public function getCostPerContact(): float
    {
        return $this->costPerContact;
    }

    public function setCostPerContact(float $costPerContact): static
    {
        $this->costPerContact = $costPerContact;

        return $this;
    }

    public function getHour(): ?int
    {
        return $this->hour;
    }

    public function setHour(?int $hour): static
    {
        $this->hour = $hour;

        return $this;
    }

    public function getMinute(): ?int
    {
        return $this->minute;
    }

    public function setMinute(?int $minute): static
    {
        $this->minute = $minute;

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

    public function getDaysBefore(): int
    {
        return $this->daysBefore;
    }

    public function setDaysBefore(int $daysBefore): static
    {
        $this->daysBefore = $daysBefore;

        return $this;
    }
}
