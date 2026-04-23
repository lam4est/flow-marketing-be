<?php

declare(strict_types=1);

namespace App\Repository\Workflow;

use App\Entity\Workflow\WorkflowStep;
use App\Entity\Workflow\WorkflowStepUser;
use App\Entity\Workflow\WorkflowUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkflowStepUser>
 */
class WorkflowStepUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowStepUser::class);
    }

    /**
     * @return array<int, WorkflowStepUser> keyed by workflow step id
     */
    public function indexByWorkflowStepForWorkflowUser(WorkflowUser $workflowUser): array
    {
        $rows = $this->findBy(['workflowUser' => $workflowUser]);
        $map = [];
        foreach ($rows as $row) {
            $map[$row->getWorkflowStep()->getId()] = $row;
        }

        return $map;
    }

    public function findOneByWorkflowUserAndStep(WorkflowUser $workflowUser, WorkflowStep $step): ?WorkflowStepUser
    {
        return $this->findOneBy(['workflowUser' => $workflowUser, 'workflowStep' => $step]);
    }
}
