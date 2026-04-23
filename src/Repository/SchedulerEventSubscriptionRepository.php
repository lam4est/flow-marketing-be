<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SchedulerEventSubscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchedulerEventSubscription>
 */
class SchedulerEventSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchedulerEventSubscription::class);
    }
}
