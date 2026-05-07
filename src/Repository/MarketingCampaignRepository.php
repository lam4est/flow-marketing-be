<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MarketingCampaign;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarketingCampaign>
 */
class MarketingCampaignRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketingCampaign::class);
    }

    /**
     * @return list<MarketingCampaign>
     */
    public function findForOwner(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.owner = :owner')
            ->setParameter('owner', $user)
            ->orderBy('c.updatedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<MarketingCampaign>
     */
    public function findScheduledOnceDue(DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.scheduleMode = :mode')
            ->andWhere('c.status = :st')
            ->andWhere('c.scheduledAt IS NOT NULL')
            ->andWhere('c.scheduledAt <= :now')
            ->setParameter('mode', MarketingCampaign::SCHEDULE_ONCE)
            ->setParameter('st', MarketingCampaign::STATUS_SCHEDULED)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<MarketingCampaign>
     */
    public function findCronScheduledCampaigns(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.scheduleMode = :mode')
            ->andWhere('c.status = :st')
            ->andWhere('c.cronExpression IS NOT NULL')
            ->andWhere('c.cronExpression != \'\'')
            ->setParameter('mode', MarketingCampaign::SCHEDULE_CRON)
            ->setParameter('st', MarketingCampaign::STATUS_SCHEDULED)
            ->getQuery()
            ->getResult();
    }
}
