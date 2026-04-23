<?php

declare(strict_types=1);

namespace App\Repository\Workflow;

use App\Entity\Workflow\WorkflowRun;
use App\Entity\Workflow\WorkflowStepRun;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkflowStepRun>
 */
class WorkflowStepRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowStepRun::class);
    }

    /**
     * @return list<WorkflowStepRun>
     */
    public function findByWorkflowRunOrdered(WorkflowRun $workflowRun): array
    {
        return $this->findBy(['workflowRun' => $workflowRun], ['stepOrder' => 'ASC', 'id' => 'ASC']);
    }

    public function countByRunAndStatus(WorkflowRun $workflowRun, string $status): int
    {
        return (int) $this->createQueryBuilder('wsr')
            ->select('COUNT(wsr.id)')
            ->andWhere('wsr.workflowRun = :workflowRun')
            ->andWhere('wsr.status = :status')
            ->setParameter('workflowRun', $workflowRun)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Atomically move a step run from queued to running. Used for Messenger idempotency (single sender wins).
     */
    public function claimQueuedAsRunning(int $id): bool
    {
        $now = new DateTimeImmutable();
        $count = (int) $this->getEntityManager()->createQueryBuilder()
            ->update(WorkflowStepRun::class, 'wsr')
            ->set('wsr.status', ':running')
            ->set('wsr.startedAt', ':now')
            ->where('wsr.id = :id')
            ->andWhere('wsr.status = :queued')
            ->setParameter('running', WorkflowStepRun::STATUS_RUNNING)
            ->setParameter('now', $now)
            ->setParameter('id', $id)
            ->setParameter('queued', WorkflowStepRun::STATUS_QUEUED)
            ->getQuery()
            ->execute();

        return $count > 0;
    }
}
