<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Workflow\WorkflowStepRun;
use App\Message\WorkflowStepRunProcessMessage;
use App\Repository\Workflow\WorkflowStepRunRepository;
use App\Service\Workflow\WorkflowRunStatusService;
use App\Service\Workflow\WorkflowStepDispatchService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class WorkflowStepRunProcessMessageHandler
{
    public function __construct(
        private WorkflowStepRunRepository $workflowStepRunRepository,
        private WorkflowRunStatusService $workflowRunStatusService,
        private WorkflowStepDispatchService $workflowStepDispatchService,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(WorkflowStepRunProcessMessage $message): void
    {
        $stepRun = $this->workflowStepRunRepository->find($message->workflowStepRunId);
        if (!$stepRun instanceof WorkflowStepRun) {
            return;
        }

        $this->em->refresh($stepRun);

        $stepUser = $stepRun->getWorkflowStepUser();
        if ($stepUser !== null && !$stepUser->isActive()) {
            $this->finalizeSkipped($stepRun, null);

            return;
        }

        $status = $stepRun->getStatus();
        if (\in_array($status, [WorkflowStepRun::STATUS_SENT, WorkflowStepRun::STATUS_FAILED, WorkflowStepRun::STATUS_SKIPPED], true)) {
            return;
        }

        if ($stepRun->getTemplateIdUsed() === null || $stepRun->getTemplateIdUsed() === '') {
            $this->finalizeSkipped($stepRun, 'Template is missing, skip send.');

            return;
        }

        if ($status === WorkflowStepRun::STATUS_QUEUED) {
            if (!$this->workflowStepRunRepository->claimQueuedAsRunning($stepRun->getId())) {
                return;
            }
            $this->em->refresh($stepRun);
        } elseif ($status === WorkflowStepRun::STATUS_RUNNING) {
            if ($stepRun->getFinishedAt() !== null) {
                return;
            }
        } else {
            return;
        }

        try {
            $result = $this->workflowStepDispatchService->dispatch($stepRun);
            $payloadSnapshot = [
                'channel' => $stepRun->getChannelUsed(),
                'template_id' => $stepRun->getTemplateIdUsed(),
                'workflow_step_id' => $stepRun->getWorkflowStep()->getId(),
                'delivery_mode' => $result->deliveryMode,
            ];
            $stepRun->setStatus(WorkflowStepRun::STATUS_SENT);
            $stepRun->setPayloadSnapshot($payloadSnapshot);
            $stepRun->setDispatchReference($result->dispatchReference);
            $stepRun->setErrorMessage(null);
        } catch (\Throwable $e) {
            $stepRun->setStatus(WorkflowStepRun::STATUS_FAILED);
            $stepRun->setErrorMessage($e->getMessage());
        }

        $stepRun->setFinishedAt(new DateTimeImmutable());
        $this->em->flush();

        $this->workflowRunStatusService->refresh($stepRun->getWorkflowRun());
    }

    private function finalizeSkipped(WorkflowStepRun $stepRun, ?string $errorMessage): void
    {
        $stepRun->setStatus(WorkflowStepRun::STATUS_SKIPPED);
        $stepRun->setErrorMessage($errorMessage);
        $stepRun->setFinishedAt(new DateTimeImmutable());
        $this->em->flush();

        $this->workflowRunStatusService->refresh($stepRun->getWorkflowRun());
    }
}
