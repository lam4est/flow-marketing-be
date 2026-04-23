<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SchedulerEventHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchedulerEventHistory>
 */
class SchedulerEventHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchedulerEventHistory::class);
    }
}
