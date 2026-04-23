<?php

declare(strict_types=1);

namespace App\Repository\Workflow;

use App\Entity\Workflow\Workflow;
use App\Entity\Workflow\WorkflowStep;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkflowStep>
 */
class WorkflowStepRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowStep::class);
    }

    /**
     * @return list<WorkflowStep>
     */
    public function findByWorkflowOrdered(Workflow $workflow): array
    {
        return $this->findBy(['workflow' => $workflow], ['stepOrder' => 'ASC']);
    }
}
