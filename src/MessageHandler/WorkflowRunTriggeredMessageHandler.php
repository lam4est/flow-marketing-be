<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Workflow\WorkflowRun;
use App\Entity\Workflow\WorkflowStepRun;
use App\Message\WorkflowRunTriggeredMessage;
use App\Message\WorkflowStepRunProcessMessage;
use App\Repository\Workflow\WorkflowRunRepository;
use App\Repository\Workflow\WorkflowStepRepository;
use App\Repository\Workflow\WorkflowStepUserRepository;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
final readonly class WorkflowRunTriggeredMessageHandler
{
    public function __construct(
        private WorkflowRunRepository $workflowRunRepository,
        private WorkflowStepRepository $workflowStepRepository,
        private WorkflowStepUserRepository $workflowStepUserRepository,
        private EntityManagerInterface $em,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(WorkflowRunTriggeredMessage $message): void
    {
        $run = $this->workflowRunRepository->find($message->workflowRunId);
        if (!$run instanceof WorkflowRun) {
            return;
        }

        $workflowUser = $run->getWorkflowUser();
        if (!$workflowUser->isActive()) {
            $run->setStatus(WorkflowRun::STATUS_FAILED);
            $run->setErrorMessage('Workflow user is not active.');
            $run->setFinishedAt(new DateTimeImmutable());
            $this->em->flush();

            return;
        }

        $run->setStatus(WorkflowRun::STATUS_RUNNING);
        $run->setStartedAt(new DateTimeImmutable());
        $this->em->flush();

        $steps = $this->workflowStepRepository->findByWorkflowOrdered($workflowUser->getWorkflow());
        $stepMap = $this->workflowStepUserRepository->indexByWorkflowStepForWorkflowUser($workflowUser);
        $scheduledAt = $run->getStartedAt() ?? new DateTimeImmutable();

        foreach ($steps as $step) {
            $stepUser = $stepMap[$step->getId()] ?? null;
            $stepRun = (new WorkflowStepRun())
                ->setWorkflowRun($run)
                ->setWorkflowStep($step)
                ->setWorkflowStepUser($stepUser)
                ->setStepOrder($step->getStepOrder())
                ->setChannelUsed($stepUser?->getChannel() ?? $step->getChannel())
                ->setTemplateIdUsed($stepUser?->getTemplateId())
                ->setScheduledAt($scheduledAt);

            $this->em->persist($stepRun);
            $this->em->flush();

            $delayMs = max(0, $scheduledAt->getTimestamp() - time()) * 1000;
            if ($delayMs > 0) {
                $this->messageBus->dispatch(new WorkflowStepRunProcessMessage($stepRun->getId()), [new DelayStamp($delayMs)]);
            } else {
                $this->messageBus->dispatch(new WorkflowStepRunProcessMessage($stepRun->getId()));
            }

            $delayMinutes = $stepUser?->getDelayInMinutes() ?? 0;
            if ($delayMinutes > 0) {
                $scheduledAt = $scheduledAt->add(new DateInterval('PT'.$delayMinutes.'M'));
            }
        }
    }
}
