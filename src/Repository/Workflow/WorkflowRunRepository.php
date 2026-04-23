<?php

declare(strict_types=1);

namespace App\Repository\Workflow;

use App\Entity\Workflow\WorkflowRun;
use App\Entity\Workflow\WorkflowUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkflowRun>
 */
class WorkflowRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowRun::class);
    }

    /**
     * @return list<WorkflowRun>
     */
    public function findLatestByWorkflowUser(WorkflowUser $workflowUser, int $limit = 20): array
    {
        return $this->createQueryBuilder('wr')
            ->andWhere('wr.workflowUser = :workflowUser')
            ->setParameter('workflowUser', $workflowUser)
            ->orderBy('wr.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
