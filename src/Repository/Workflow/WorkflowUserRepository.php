<?php

declare(strict_types=1);

namespace App\Repository\Workflow;

use App\Entity\User;
use App\Entity\Workflow\Workflow;
use App\Entity\Workflow\WorkflowUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkflowUser>
 */
class WorkflowUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowUser::class);
    }

    /**
     * @return list<WorkflowUser>
     */
    public function findForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['id' => 'ASC']);
    }

    public function findOneByUserAndWorkflow(User $user, Workflow $workflow): ?WorkflowUser
    {
        return $this->findOneBy(['user' => $user, 'workflow' => $workflow]);
    }
}
