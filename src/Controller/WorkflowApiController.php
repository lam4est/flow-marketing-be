<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Contact;
use App\Entity\ContactList;
use App\Entity\User;
use App\Entity\Workflow\Workflow;
use App\Entity\Workflow\WorkflowRun;
use App\Entity\Workflow\WorkflowStep;
use App\Entity\Workflow\WorkflowStepRun;
use App\Entity\Workflow\WorkflowStepUser;
use App\Entity\Workflow\WorkflowUser;
use App\Message\WorkflowRunTriggeredMessage;
use App\Repository\ContactListRepository;
use App\Repository\ContactRepository;
use App\Repository\Workflow\WorkflowRepository;
use App\Repository\Workflow\WorkflowRunRepository;
use App\Repository\Workflow\WorkflowStepRepository;
use App\Repository\Workflow\WorkflowStepRunRepository;
use App\Repository\Workflow\WorkflowStepUserRepository;
use App\Repository\Workflow\WorkflowUserRepository;
use App\Service\CurrentUserResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class WorkflowApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurrentUserResolver $currentUserResolver,
        private readonly WorkflowUserRepository $workflowUserRepository,
        private readonly WorkflowRepository $workflowRepository,
        private readonly WorkflowRunRepository $workflowRunRepository,
        private readonly WorkflowStepRepository $workflowStepRepository,
        private readonly WorkflowStepRunRepository $workflowStepRunRepository,
        private readonly WorkflowStepUserRepository $workflowStepUserRepository,
        private readonly ContactListRepository $contactListRepository,
        private readonly ContactRepository $contactRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route('/api/workflows', name: 'api_workflows_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $items = [];
        foreach ($this->workflowUserRepository->findForUser($user) as $wu) {
            $items[] = $this->serializeWorkflowUserForList($wu);
        }

        return new JsonResponse($items);
    }

    #[Route('/api/workflow-catalog', name: 'api_workflow_catalog', methods: ['GET'])]
    public function catalog(Request $request): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $enrolled = [];
        foreach ($this->workflowUserRepository->findForUser($user) as $wu) {
            $wfId = $wu->getWorkflow()->getId();
            if ($wfId !== null) {
                $enrolled[$wfId] = $wu->getId();
            }
        }

        $workflows = $this->workflowRepository->findBy([], ['workflowName' => 'ASC']);
        $items = [];
        foreach ($workflows as $w) {
            $wid = $w->getId();
            if ($wid === null) {
                continue;
            }
            $items[] = [
                'id' => $wid,
                'name' => $w->getWorkflowName(),
                'category' => $w->getCategory(),
                'description' => $w->getDescription(),
                'workflow_user_id' => $enrolled[$wid] ?? null,
            ];
        }

        return new JsonResponse(['items' => $items]);
    }

    #[Route('/api/workflow-users', name: 'api_workflow_users_create', methods: ['POST'])]
    public function createWorkflowUser(Request $request): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $data = $this->decodeJson($request);
        $workflowId = isset($data['workflow_id']) ? (int) $data['workflow_id'] : 0;
        if ($workflowId < 1) {
            throw new BadRequestHttpException('workflow_id is required.');
        }

        $workflow = $this->workflowRepository->find($workflowId);
        if (!$workflow instanceof Workflow) {
            throw new NotFoundHttpException('Workflow template not found.');
        }

        if ($this->workflowUserRepository->findOneByUserAndWorkflow($user, $workflow) instanceof WorkflowUser) {
            throw new ConflictHttpException('This workflow is already in your list.');
        }

        $wu = (new WorkflowUser())
            ->setUser($user)
            ->setOriginalWorkflow($workflow)
            ->setWorkflow($workflow)
            ->setIsActive(false);
        $this->em->persist($wu);
        $this->em->flush();

        foreach ($this->workflowStepRepository->findByWorkflowOrdered($workflow) as $step) {
            $this->createWorkflowStepUser($user, $wu, $step);
        }
        $this->em->flush();

        return new JsonResponse($this->serializeWorkflowUserForList($wu), Response::HTTP_CREATED);
    }

    #[Route('/api/workflow-users/{id<\d+>}', name: 'api_workflow_users_delete', methods: ['DELETE'])]
    public function deleteWorkflowUser(Request $request, int $id): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $wu = $this->workflowUserRepository->find($id);
        if (!$wu instanceof WorkflowUser || $wu->getUser()->getId() !== $user->getId()) {
            throw new NotFoundHttpException('Workflow enrollment not found.');
        }

        $this->em->remove($wu);
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/workflows/{workflowId<\d+>}/detail', name: 'api_workflows_detail', methods: ['GET'])]
    public function detail(Request $request, int $workflowId): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $workflow = $this->workflowRepository->find($workflowId);
        if (!$workflow instanceof Workflow) {
            throw new NotFoundHttpException('Workflow not found.');
        }

        $workflowUserId = $request->query->get('workflow_user_id');
        $wu = null;
        if ($workflowUserId !== null && $workflowUserId !== '') {
            $wu = $this->workflowUserRepository->find((int) $workflowUserId);
            if (!$wu instanceof WorkflowUser || $wu->getUser()->getId() !== $user->getId()) {
                throw new NotFoundHttpException('Workflow user not found.');
            }
        } else {
            $wu = $this->workflowUserRepository->findOneByUserAndWorkflow($user, $workflow);
        }

        if (!$wu instanceof WorkflowUser) {
            throw new NotFoundHttpException('Workflow user not found.');
        }

        return new JsonResponse($this->serializeWorkflowDetail($wu));
    }

    #[Route('/api/workflows/{workflowId<\d+>}', name: 'api_workflows_update', methods: ['PUT'])]
    public function update(Request $request, int $workflowId): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $workflow = $this->workflowRepository->find($workflowId);
        if (!$workflow instanceof Workflow) {
            throw new NotFoundHttpException('Workflow not found.');
        }

        $wu = $this->workflowUserRepository->findOneByUserAndWorkflow($user, $workflow);
        if (!$wu instanceof WorkflowUser) {
            throw new NotFoundHttpException('Workflow user not found.');
        }

        $wasActive = $wu->isActive();
        $data = $this->decodeJson($request);

        if (\array_key_exists('is_active', $data)) {
            $wu->setIsActive((bool) $data['is_active']);
        }

        if (\array_key_exists('segment_id', $data)) {
            $wu->setSegment($this->resolveContactList($user, $data['segment_id']));
        }

        if (isset($data['steps']) && \is_array($data['steps'])) {
            $stepMap = $this->workflowStepUserRepository->indexByWorkflowStepForWorkflowUser($wu);
            foreach ($data['steps'] as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $stepId = isset($row['workflow_step_id']) ? (int) $row['workflow_step_id'] : null;
                if ($stepId === null) {
                    continue;
                }
                $step = $this->workflowStepRepository->find($stepId);
                if (!$step instanceof WorkflowStep || $step->getWorkflow()->getId() !== $workflow->getId()) {
                    continue;
                }
                $wsu = $stepMap[$stepId] ?? $this->createWorkflowStepUser($user, $wu, $step);
                $stepMap[$stepId] = $wsu;

                if (isset($row['channel'])) {
                    $wsu->setChannel((string) $row['channel']);
                }
                if (\array_key_exists('template_id', $row)) {
                    $tid = $row['template_id'];
                    $wsu->setTemplateId($tid === null || $tid === '' ? null : (string) $tid);
                }
                if (\array_key_exists('is_active', $row)) {
                    $wsu->setIsActive((bool) $row['is_active']);
                }
                if (\array_key_exists('delay_in_minutes', $row)) {
                    $dim = $row['delay_in_minutes'];
                    $wsu->setDelayInMinutes($dim === null ? null : (int) $dim);
                }
                if (\array_key_exists('is_confirmed_by_user', $row)) {
                    $wsu->setIsConfirmedByUser((bool) $row['is_confirmed_by_user']);
                }
                if (\array_key_exists('settings', $row)) {
                    $settings = $row['settings'];
                    $wsu->setSettings(\is_array($settings) ? $settings : null);
                }
                if (\array_key_exists('excluded_contact_ids', $row)) {
                    $wsu->setExcludedContactIds($this->resolveExcludedContactIds($user, $row['excluded_contact_ids']));
                }
                if (\array_key_exists('excluded_segment_ids', $row)) {
                    $ids = $row['excluded_segment_ids'];
                    $first = null;
                    if (\is_array($ids) && $ids !== []) {
                        $first = $this->resolveContactList($user, $ids[0]);
                    }
                    $wsu->setExcludedSegment($first);
                }
            }
        }

        $this->em->flush();

        if (!$wasActive && $wu->isActive()) {
            $this->enqueueWorkflowRunOnActivation($wu);
        }

        return new JsonResponse($this->serializeWorkflowUserForList($wu));
    }

    #[Route('/api/workflow-users/{id<\d+>}/deactivate', name: 'api_workflow_user_deactivate', methods: ['POST'])]
    public function deactivate(Request $request, int $id): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $wu = $this->workflowUserRepository->find($id);
        if (!$wu instanceof WorkflowUser || $wu->getUser()->getId() !== $user->getId()) {
            throw new NotFoundHttpException('Workflow user not found.');
        }
        $wu->setIsActive(false);
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/workflow-step-users/{id<\d+>}/confirm', name: 'api_workflow_step_user_confirm', methods: ['POST'])]
    public function confirmStep(Request $request, int $id): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $wsu = $this->workflowStepUserRepository->find($id);
        if (!$wsu instanceof WorkflowStepUser || $wsu->getUser()->getId() !== $user->getId()) {
            throw new NotFoundHttpException('Workflow step user not found.');
        }
        $wsu->setIsConfirmedByUser(true);
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/workflow-users/{id<\d+>}/trigger', name: 'api_workflow_user_trigger', methods: ['POST'])]
    public function triggerWorkflow(Request $request, int $id): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $wu = $this->workflowUserRepository->find($id);
        if (!$wu instanceof WorkflowUser || $wu->getUser()->getId() !== $user->getId()) {
            throw new NotFoundHttpException('Workflow user not found.');
        }

        $run = (new WorkflowRun())
            ->setWorkflowUser($wu)
            ->setStatus(WorkflowRun::STATUS_QUEUED)
            ->setTriggerSource('manual_api');
        $this->em->persist($run);
        $this->em->flush();

        $this->messageBus->dispatch(new WorkflowRunTriggeredMessage($run->getId()));

        return new JsonResponse([
            'ok' => true,
            'workflow_run' => $this->serializeWorkflowRun($run, []),
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Starts one workflow run when the user turns the Campaign Workflow toggle on (architecture A: Symfony orchestrates, n8n sends).
     */
    private function enqueueWorkflowRunOnActivation(WorkflowUser $wu): void
    {
        $run = (new WorkflowRun())
            ->setWorkflowUser($wu)
            ->setStatus(WorkflowRun::STATUS_QUEUED)
            ->setTriggerSource('workflow_activated');
        $this->em->persist($run);
        $this->em->flush();

        $this->messageBus->dispatch(new WorkflowRunTriggeredMessage($run->getId()));
    }

    #[Route('/api/workflow-users/{id<\d+>}/runs', name: 'api_workflow_user_runs', methods: ['GET'])]
    public function listRuns(Request $request, int $id): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $wu = $this->workflowUserRepository->find($id);
        if (!$wu instanceof WorkflowUser || $wu->getUser()->getId() !== $user->getId()) {
            throw new NotFoundHttpException('Workflow user not found.');
        }

        $items = [];
        foreach ($this->workflowRunRepository->findLatestByWorkflowUser($wu, 30) as $run) {
            $stepRuns = $this->workflowStepRunRepository->findByWorkflowRunOrdered($run);
            $items[] = $this->serializeWorkflowRun($run, $stepRuns);
        }

        return new JsonResponse(['items' => $items]);
    }

    private function decodeJson(Request $request): array
    {
        $raw = $request->getContent();
        if ($raw === '' || $raw === '0') {
            return [];
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Invalid JSON body.');
        }

        return \is_array($data) ? $data : [];
    }

    private function resolveContactList(User $user, mixed $id): ?ContactList
    {
        if ($id === null || $id === '') {
            return null;
        }
        $list = $this->contactListRepository->find((int) $id);
        if (!$list instanceof ContactList || $list->getOwner()->getId() !== $user->getId()) {
            return null;
        }

        return $list;
    }

    /** @return list<int> Normalized contact ids (empty array clears stored exclusions). */
    private function resolveExcludedContactIds(User $user, mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!\is_array($raw)) {
            throw new BadRequestHttpException('excluded_contact_ids must be an array of contact ids.');
        }
        $out = [];
        foreach ($raw as $item) {
            $cid = (int) $item;
            if ($cid < 1) {
                continue;
            }
            $contact = $this->contactRepository->findOwnedById($user, $cid);
            if (!$contact instanceof Contact) {
                throw new BadRequestHttpException(sprintf('Contact %d not found.', $cid));
            }
            $out[] = $cid;
        }

        return array_values(array_unique($out));
    }

    private function createWorkflowStepUser(User $user, WorkflowUser $wu, WorkflowStep $step): WorkflowStepUser
    {
        $wsu = (new WorkflowStepUser())
            ->setUser($user)
            ->setWorkflowUser($wu)
            ->setWorkflowStep($step)
            ->setChannel($step->getChannel())
            ->setIsActive(false)
            ->setDelayInMinutes($this->delayMinutesFromTemplate($step));

        $this->em->persist($wsu);

        return $wsu;
    }

    private function delayMinutesFromTemplate(WorkflowStep $step): int
    {
        $v = $step->getDelayValue();
        $u = $step->getDelayUnit();
        if ($v === null || $u === null) {
            return 0;
        }

        return $this->toMinutes((int) $v, (string) $u);
    }

    private function toMinutes(int $value, string $unit): int
    {
        return match ($unit) {
            'day' => $value * 1440,
            'hour' => $value * 60,
            default => $value,
        };
    }

    /**
     * @return array{value: int, unit: string}
     */
    private function minutesToDelayParts(int $minutes): array
    {
        $days = intdiv($minutes, 1440);
        $rem = $minutes % 1440;
        $hours = intdiv($rem, 60);
        $mins = $rem % 60;
        if ($days > 0) {
            return ['value' => $days, 'unit' => 'day'];
        }
        if ($hours > 0) {
            return ['value' => $hours, 'unit' => 'hour'];
        }

        return ['value' => $mins, 'unit' => 'minute'];
    }

    private function effectiveDelayMinutes(WorkflowStep $template, ?WorkflowStepUser $su): int
    {
        if ($su !== null && $su->getDelayInMinutes() !== null) {
            return $su->getDelayInMinutes();
        }

        return $this->delayMinutesFromTemplate($template);
    }

    private function serializeWorkflowUserForList(WorkflowUser $wu): array
    {
        $workflow = $wu->getWorkflow();
        $steps = $this->workflowStepRepository->findByWorkflowOrdered($workflow);
        $map = $this->workflowStepUserRepository->indexByWorkflowStepForWorkflowUser($wu);
        $out = [];
        foreach ($steps as $step) {
            $su = $map[$step->getId()] ?? null;
            $out[] = $this->serializeListStep($step, $su);
        }

        return [
            'id' => $wu->getId(),
            'workflow_id' => $workflow->getId(),
            'name' => $workflow->getWorkflowName(),
            'category' => $workflow->getCategory(),
            'description' => $workflow->getDescription(),
            'is_active' => $wu->isActive(),
            'metadata' => [],
            'steps' => $out,
        ];
    }

    private function serializeListStep(WorkflowStep $step, ?WorkflowStepUser $su): array
    {
        $minutes = $this->effectiveDelayMinutes($step, $su);
        $parts = $this->minutesToDelayParts($minutes);
        $excluded = [];
        if ($su !== null && $su->getExcludedSegment() !== null) {
            $excluded[] = $su->getExcludedSegment()->getId();
        }

        $tid = $su?->getTemplateId();
        $templateId = $tid !== null && is_numeric($tid) ? (int) $tid : $tid;

        return [
            'workflow_step_id' => $step->getId(),
            'step_order' => $step->getStepOrder(),
            'channel' => $su?->getChannel() ?? $step->getChannel(),
            'delay_value' => $parts['value'],
            'delay_unit' => $parts['unit'],
            'template_id' => $templateId,
            'template_name' => '',
            'is_active' => $su?->isActive() ?? false,
            'excluded_segment_id' => $excluded,
            'excluded_contact_ids' => $su !== null ? $su->getExcludedContactIds() : [],
            'settings' => $su?->getSettings(),
        ];
    }

    private function serializeWorkflowDetail(WorkflowUser $wu): array
    {
        $workflow = $wu->getWorkflow();
        $steps = $this->workflowStepRepository->findByWorkflowOrdered($workflow);
        $map = $this->workflowStepUserRepository->indexByWorkflowStepForWorkflowUser($wu);
        $out = [];
        foreach ($steps as $step) {
            $su = $map[$step->getId()] ?? null;
            $minutes = $this->effectiveDelayMinutes($step, $su);
            $parts = $this->minutesToDelayParts($minutes);
            $excluded = [];
            if ($su !== null && $su->getExcludedSegment() !== null) {
                $excluded[] = $su->getExcludedSegment()->getId();
            }
            $tid = $su?->getTemplateId();

            $out[] = [
                'id' => $su?->getId(),
                'workflow_step_id' => $step->getId(),
                'channel' => $su?->getChannel() ?? $step->getChannel(),
                'template_id' => $tid !== null && is_numeric($tid) ? (int) $tid : $tid,
                'delay_value' => $parts['value'],
                'delay_unit' => $parts['unit'],
                'delay_in_minutes' => $minutes,
                'is_active' => $su?->isActive() ?? false,
                'is_confirmed_by_user' => $su?->isConfirmedByUser() ?? false,
                'excluded_segment_id' => $excluded,
                'excluded_contact_ids' => $su !== null ? $su->getExcludedContactIds() : [],
                'settings' => $su?->getSettings() ?? [],
            ];
        }

        return [
            'workflow_user_id' => $wu->getId(),
            'original_workflow_id' => $wu->getOriginalWorkflow()->getId(),
            'name' => $workflow->getWorkflowName(),
            'description' => $workflow->getDescription(),
            'segment_id' => $wu->getSegment()?->getId(),
            'steps' => $out,
            'runs' => $this->serializeWorkflowRuns($wu),
        ];
    }

    private function serializeWorkflowRuns(WorkflowUser $workflowUser): array
    {
        $output = [];
        foreach ($this->workflowRunRepository->findLatestByWorkflowUser($workflowUser, 10) as $run) {
            $stepRuns = $this->workflowStepRunRepository->findByWorkflowRunOrdered($run);
            $output[] = $this->serializeWorkflowRun($run, $stepRuns);
        }

        return $output;
    }

    /**
     * @param list<WorkflowStepRun> $stepRuns
     */
    private function serializeWorkflowRun(WorkflowRun $run, array $stepRuns): array
    {
        $steps = [];
        foreach ($stepRuns as $stepRun) {
            $steps[] = [
                'id' => $stepRun->getId(),
                'workflow_step_id' => $stepRun->getWorkflowStep()->getId(),
                'status' => $stepRun->getStatus(),
                'channel_used' => $stepRun->getChannelUsed(),
                'template_id_used' => $stepRun->getTemplateIdUsed(),
                'scheduled_at' => $stepRun->getScheduledAt()?->format(DATE_ATOM),
                'started_at' => $stepRun->getStartedAt()?->format(DATE_ATOM),
                'finished_at' => $stepRun->getFinishedAt()?->format(DATE_ATOM),
                'error_message' => $stepRun->getErrorMessage(),
                'payload_snapshot' => $stepRun->getPayloadSnapshot(),
                'dispatch_reference' => $stepRun->getDispatchReference(),
            ];
        }

        return [
            'id' => $run->getId(),
            'status' => $run->getStatus(),
            'trigger_source' => $run->getTriggerSource(),
            'started_at' => $run->getStartedAt()?->format(DATE_ATOM),
            'finished_at' => $run->getFinishedAt()?->format(DATE_ATOM),
            'error_message' => $run->getErrorMessage(),
            'steps' => $steps,
        ];
    }
}
