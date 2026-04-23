<?php

declare(strict_types=1);

namespace App\Service\Workflow;

use App\Entity\Workflow\WorkflowRun;
use App\Entity\Workflow\WorkflowStepRun;
use App\Repository\Workflow\WorkflowStepRunRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class WorkflowRunStatusService
{
    public function __construct(
        private EntityManagerInterface $em,
        private WorkflowStepRunRepository $workflowStepRunRepository,
    ) {
    }

    public function refresh(WorkflowRun $workflowRun): void
    {
        $failed = $this->workflowStepRunRepository->countByRunAndStatus($workflowRun, WorkflowStepRun::STATUS_FAILED);
        $pending = $this->workflowStepRunRepository->countByRunAndStatus($workflowRun, WorkflowStepRun::STATUS_QUEUED);
        $running = $this->workflowStepRunRepository->countByRunAndStatus($workflowRun, WorkflowStepRun::STATUS_RUNNING);

        if ($failed > 0) {
            $workflowRun->setStatus(WorkflowRun::STATUS_FAILED);
            $workflowRun->setFinishedAt(new DateTimeImmutable());
            $this->em->flush();

            return;
        }

        if ($pending > 0 || $running > 0) {
            $workflowRun->setStatus(WorkflowRun::STATUS_RUNNING);
            $workflowRun->setFinishedAt(null);
            $this->em->flush();

            return;
        }

        $workflowRun->setStatus(WorkflowRun::STATUS_COMPLETED);
        $workflowRun->setFinishedAt(new DateTimeImmutable());
        $this->em->flush();
    }
}
