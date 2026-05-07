<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MarketingCampaign;
use App\Entity\MarketingCampaignSend;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarketingCampaignSend>
 */
class MarketingCampaignSendRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketingCampaignSend::class);
    }

    /**
     * @return list<MarketingCampaignSend>
     */
    public function findRecentByCampaign(MarketingCampaign $campaign, int $limit = 20): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.marketingCampaign = :c')
            ->setParameter('c', $campaign)
            ->orderBy('s.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findLatestByCampaign(MarketingCampaign $campaign): ?MarketingCampaignSend
    {
        $r = $this->findRecentByCampaign($campaign, 1);

        return $r[0] ?? null;
    }
}
