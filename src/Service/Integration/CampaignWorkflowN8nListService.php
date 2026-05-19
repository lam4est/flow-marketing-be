<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Entity\ContactList;
use App\Entity\ContentTemplate;
use App\Entity\User;
use App\Entity\Workflow\WorkflowRun;
use App\Entity\Workflow\WorkflowStep;
use App\Entity\Workflow\WorkflowStepRun;
use App\Entity\Workflow\WorkflowStepUser;
use App\Entity\Workflow\WorkflowUser;
use App\Repository\ContactRepository;
use App\Repository\ContentTemplateRepository;
use App\Repository\Workflow\WorkflowRunRepository;
use App\Repository\Workflow\WorkflowStepRepository;
use App\Repository\Workflow\WorkflowStepRunRepository;
use App\Repository\Workflow\WorkflowStepUserRepository;
use App\Repository\Workflow\WorkflowUserRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds n8n-oriented payloads for "campaign workflow" enrollments (WorkflowUser),
 * aligned with GET /api/workflows and /api/workflows/{id}/detail.
 */
final readonly class CampaignWorkflowN8nListService
{
    /** Channels for which include=contacts also embeds content_template + message (same idea as SMS/RCS originally). */
    private const CONTACT_INCLUDE_TEMPLATE_CHANNELS = [
        ContentTemplate::CHANNEL_SMS,
        ContentTemplate::CHANNEL_RCS,
        ContentTemplate::CHANNEL_EMAIL,
        'voice',
        'voice_sms',
    ];

    public function __construct(
        private WorkflowUserRepository $workflowUserRepository,
        private WorkflowStepRepository $workflowStepRepository,
        private WorkflowStepUserRepository $workflowStepUserRepository,
        private WorkflowRunRepository $workflowRunRepository,
        private WorkflowStepRunRepository $workflowStepRunRepository,
        private ContactRepository $contactRepository,
        private ContentTemplateRepository $contentTemplateRepository,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buildItems(User $user, Request $request): array
    {
        $statusFilter = $this->normalizeStatusFilter($request->query->get('status'));
        $channelFilter = $this->normalizeChannelFilter($request->query->get('channel'));
        $includes = $this->parseIncludes($request);

        $out = [];
        foreach ($this->workflowUserRepository->findForUser($user) as $wu) {
            if (!$this->matchesStatusFilter($wu, $statusFilter)) {
                continue;
            }
            if ($channelFilter !== '' && !$this->workflowHasStepChannel($wu, $channelFilter)) {
                continue;
            }
            $out[] = $this->serializeWorkflowEnrollment($wu, $includes, $user);
        }

        return $out;
    }

    private function normalizeStatusFilter(mixed $raw): string
    {
        if (!\is_string($raw)) {
            return '';
        }
        $s = strtolower(trim($raw));

        return match ($s) {
            'scheduled', 'active', 'running' => 'active',
            'inactive', 'draft', 'paused' => 'inactive',
            default => $s,
        };
    }

    private function normalizeChannelFilter(mixed $raw): string
    {
        if (!\is_string($raw)) {
            return '';
        }

        return strtolower(trim($raw));
    }

    /**
     * @param array<string, bool> $includes
     */
    private function serializeWorkflowEnrollment(WorkflowUser $wu, array $includes, User $owner): array
    {
        $workflow = $wu->getWorkflow();
        $segment = $wu->getSegment();
        $steps = $this->workflowStepRepository->findByWorkflowOrdered($workflow);
        $map = $this->workflowStepUserRepository->indexByWorkflowStepForWorkflowUser($wu);

        $stepRows = [];
        foreach ($steps as $step) {
            $su = $map[$step->getId()] ?? null;
            $stepRows[] = $this->serializeStepRow($step, $su, $includes, $owner);
        }

        $payload = [
            'source' => 'campaign_workflow',
            'workflow_user_id' => $wu->getId(),
            'workflow_id' => $workflow->getId(),
            'original_workflow_id' => $wu->getOriginalWorkflow()->getId(),
            'name' => $workflow->getWorkflowName(),
            'category' => $workflow->getCategory(),
            'description' => $workflow->getDescription(),
            'is_active' => $wu->isActive(),
            'segment_id' => $segment?->getId(),
            'contact_list' => $segment instanceof ContactList ? $this->serializeContactList($segment) : null,
            'steps' => $stepRows,
            'runs' => $this->serializeWorkflowRuns($wu),
        ];

        if (!empty($includes['contacts']) && $segment instanceof ContactList) {
            $contacts = $this->contactRepository->findByContactList($segment);
            $payload['contacts'] = array_map(fn ($c) => $this->serializeContact($c), $contacts);
            $payload['recipients'] = array_values(array_filter(array_map(
                static function ($contact): ?array {
                    $phone = $contact->getPhone();
                    if (trim($phone) === '') {
                        return null;
                    }

                    return [
                        'contact_id' => $contact->getId(),
                        'phone_number' => trim($phone),
                        'email' => $contact->getEmail(),
                        'display_name' => $contact->getDisplayName(),
                    ];
                },
                $contacts,
            )));
        }

        return $payload;
    }

    /**
     * @param array<string, bool> $includes
     *
     * @return array<string, mixed>
     */
    private function serializeStepRow(WorkflowStep $step, ?WorkflowStepUser $su, array $includes, User $owner): array
    {
        $minutes = $this->effectiveDelayMinutes($step, $su);
        $parts = $this->minutesToDelayParts($minutes);
        $excluded = [];
        if ($su !== null && $su->getExcludedSegment() !== null) {
            $excluded[] = $su->getExcludedSegment()->getId();
        }
        $tid = $su?->getTemplateId();
        $templateId = $tid !== null && is_numeric($tid) ? (int) $tid : $tid;

        $row = [
            'id' => $su?->getId(),
            'workflow_step_id' => $step->getId(),
            'step_order' => $step->getStepOrder(),
            'channel' => $su?->getChannel() ?? $step->getChannel(),
            'template_id' => $templateId,
            'delay_value' => $parts['value'],
            'delay_unit' => $parts['unit'],
            'delay_in_minutes' => $minutes,
            'is_active' => $su?->isActive() ?? false,
            'is_confirmed_by_user' => $su?->isConfirmedByUser() ?? false,
            'excluded_segment_id' => $excluded,
            'excluded_contact_ids' => $su !== null ? $su->getExcludedContactIds() : [],
            'settings' => $su?->getSettings() ?? [],
        ];

        $channelLower = strtolower((string) ($su?->getChannel() ?? $step->getChannel()));
        $includeTemplate =
            \is_int($templateId)
            && (
                !empty($includes['templates'])
                || (
                    !empty($includes['contacts'])
                    && \in_array($channelLower, self::CONTACT_INCLUDE_TEMPLATE_CHANNELS, true)
                )
            );

        if ($includeTemplate) {
            $tpl = $this->contentTemplateRepository->find($templateId);
            if ($tpl instanceof ContentTemplate && $tpl->getOwner()->getId() === $owner->getId()) {
                $row['content_template'] = $this->serializeTemplate($tpl);
                $row['message'] = [
                    'subject' => $tpl->getSubject(),
                    'body' => $tpl->getBody(),
                ];
            } else {
                $row['content_template'] = null;
                $row['message'] = ['subject' => null, 'body' => null];
            }
        }

        return $row;
    }

    private function matchesStatusFilter(WorkflowUser $wu, string $normalized): bool
    {
        if ($normalized === '') {
            return true;
        }
        if ($normalized === 'active') {
            return $wu->isActive();
        }
        if ($normalized === 'inactive') {
            return !$wu->isActive();
        }

        return true;
    }

    private function workflowHasStepChannel(WorkflowUser $wu, string $channel): bool
    {
        $workflow = $wu->getWorkflow();
        $steps = $this->workflowStepRepository->findByWorkflowOrdered($workflow);
        $map = $this->workflowStepUserRepository->indexByWorkflowStepForWorkflowUser($wu);
        foreach ($steps as $step) {
            $su = $map[$step->getId()] ?? null;
            $ch = strtolower((string) ($su?->getChannel() ?? $step->getChannel()));
            if ($ch === $channel) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, bool>
     */
    private function parseIncludes(Request $request): array
    {
        $raw = (string) ($request->query->get('include') ?? '');
        $tokens = array_filter(array_map('trim', explode(',', strtolower($raw))));
        $set = [];
        foreach ($tokens as $token) {
            if ($token !== '') {
                $set[$token] = true;
            }
        }

        return $set;
    }

    private function serializeContactList(ContactList $list): array
    {
        return [
            'id' => $list->getId(),
            'name' => $list->getName(),
            'contacts_count' => $list->getContactsCount(),
        ];
    }

    private function serializeContact(\App\Entity\Contact $contact): array
    {
        return [
            'id' => $contact->getId(),
            'display_name' => $contact->getDisplayName(),
            'email' => $contact->getEmail(),
            'phone' => $contact->getPhone(),
        ];
    }

    private function serializeTemplate(ContentTemplate $t): array
    {
        return [
            'id' => $t->getId(),
            'name' => $t->getName(),
            'channel' => $t->getChannel(),
            'subject' => $t->getSubject(),
            'body' => $t->getBody(),
            'created_at' => $t->getCreatedAt()?->format(DATE_ATOM),
            'updated_at' => $t->getUpdatedAt()?->format(DATE_ATOM),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
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
     *
     * @return array<string, mixed>
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
}
