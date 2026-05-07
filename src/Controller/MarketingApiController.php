<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContactList;
use App\Entity\ContentTemplate;
use App\Entity\MarketingCampaign;
use App\Entity\MarketingCampaignSend;
use App\Entity\User;
use App\Repository\ContactListRepository;
use App\Repository\ContentTemplateRepository;
use App\Repository\MarketingCampaignRepository;
use App\Repository\MarketingCampaignSendRepository;
use App\Service\CurrentUserResolver;
use App\Service\Marketing\MarketingCampaignDispatchService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class MarketingApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurrentUserResolver $currentUserResolver,
        private readonly ContactListRepository $contactListRepository,
        private readonly ContentTemplateRepository $contentTemplateRepository,
        private readonly MarketingCampaignRepository $marketingCampaignRepository,
        private readonly MarketingCampaignSendRepository $marketingCampaignSendRepository,
        private readonly MarketingCampaignDispatchService $marketingCampaignDispatchService,
    ) {
    }

    #[Route('/api/marketing/contact-lists', name: 'api_marketing_contact_lists', methods: ['GET'])]
    public function listContactLists(Request $request): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $items = [];
        foreach ($this->contactListRepository->findForOwner($user) as $list) {
            $items[] = $this->serializeContactList($list);
        }

        return new JsonResponse(['items' => $items]);
    }

    #[Route('/api/marketing/content-templates', name: 'api_marketing_templates_list', methods: ['GET'])]
    public function listTemplates(Request $request): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $channel = $request->query->get('channel');
        $items = [];
        foreach ($this->contentTemplateRepository->findForOwner($user) as $t) {
            if (\is_string($channel) && $channel !== '' && $t->getChannel() !== $channel) {
                continue;
            }
            $items[] = $this->serializeTemplate($t);
        }

        return new JsonResponse(['items' => $items]);
    }

    #[Route('/api/marketing/content-templates', name: 'api_marketing_templates_create', methods: ['POST'])]
    public function createTemplate(Request $request): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $data = $this->decodeJson($request);
        $name = isset($data['name']) ? trim((string) $data['name']) : '';
        if ($name === '') {
            throw new BadRequestHttpException('name is required.');
        }
        $channel = $this->normalizeChannel($data['channel'] ?? ContentTemplate::CHANNEL_EMAIL);

        $t = (new ContentTemplate())
            ->setOwner($user)
            ->setName($name)
            ->setChannel($channel)
            ->setSubject(isset($data['subject']) ? (string) $data['subject'] : null)
            ->setBody(isset($data['body']) ? (string) $data['body'] : null);
        $this->em->persist($t);
        $this->em->flush();

        return new JsonResponse($this->serializeTemplate($t), Response::HTTP_CREATED);
    }

    #[Route('/api/marketing/content-templates/{id<\d+>}', name: 'api_marketing_templates_update', methods: ['PUT'])]
    public function updateTemplate(Request $request, int $id): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $t = $this->requireTemplate($user, $id);
        $data = $this->decodeJson($request);

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new BadRequestHttpException('name cannot be empty.');
            }
            $t->setName($name);
        }
        if (isset($data['channel'])) {
            $t->setChannel($this->normalizeChannel($data['channel']));
        }
        if (\array_key_exists('subject', $data)) {
            $t->setSubject($data['subject'] === null || $data['subject'] === '' ? null : (string) $data['subject']);
        }
        if (\array_key_exists('body', $data)) {
            $t->setBody($data['body'] === null || $data['body'] === '' ? null : (string) $data['body']);
        }

        $this->em->flush();

        return new JsonResponse($this->serializeTemplate($t));
    }

    #[Route('/api/marketing/content-templates/{id<\d+>}', name: 'api_marketing_templates_delete', methods: ['DELETE'])]
    public function deleteTemplate(Request $request, int $id): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $t = $this->requireTemplate($user, $id);
        $this->em->remove($t);
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/marketing/campaigns', name: 'api_marketing_campaigns_list', methods: ['GET'])]
    public function listCampaigns(Request $request): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $statusFilter = $request->query->get('status');
        $statusFilter = \is_string($statusFilter) ? trim($statusFilter) : '';
        $channelFilter = $request->query->get('channel');
        $channelFilter = \is_string($channelFilter) ? trim(strtolower($channelFilter)) : '';

        $items = [];
        foreach ($this->marketingCampaignRepository->findForOwner($user) as $c) {
            if ($statusFilter !== '' && $c->getStatus() !== $statusFilter) {
                continue;
            }
            if ($channelFilter !== '' && $c->getChannel() !== $channelFilter) {
                continue;
            }
            $items[] = $this->serializeCampaign($c);
        }

        return new JsonResponse(['items' => $items]);
    }

    #[Route('/api/marketing/campaigns', name: 'api_marketing_campaigns_create', methods: ['POST'])]
    public function createCampaign(Request $request): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $data = $this->decodeJson($request);
        $name = isset($data['name']) ? trim((string) $data['name']) : '';
        if ($name === '') {
            throw new BadRequestHttpException('name is required.');
        }

        $c = (new MarketingCampaign())
            ->setOwner($user)
            ->setName($name)
            ->setDescription(isset($data['description']) ? (string) $data['description'] : null)
            ->setChannel($this->normalizeChannel($data['channel'] ?? MarketingCampaign::CHANNEL_EMAIL))
            ->setStatus($this->normalizeStatus($data['status'] ?? MarketingCampaign::STATUS_DRAFT))
            ->setScheduleMode($this->normalizeScheduleMode($data['schedule_mode'] ?? MarketingCampaign::SCHEDULE_MANUAL));

        if (\array_key_exists('scheduled_at', $data)) {
            $c->setScheduledAt($this->parseOptionalDateTime($data['scheduled_at']));
        }
        if (\array_key_exists('cron_expression', $data)) {
            $ce = $data['cron_expression'];
            $c->setCronExpression($ce === null || $ce === '' ? null : (string) $ce);
        }

        $this->applyCampaignRelations($user, $c, $data);
        $this->assertTemplateMatchesChannel($c);
        $this->em->persist($c);
        $this->em->flush();

        return new JsonResponse($this->serializeCampaign($c), Response::HTTP_CREATED);
    }

    #[Route('/api/marketing/campaigns/{id<\d+>}', name: 'api_marketing_campaigns_get', methods: ['GET'])]
    public function getCampaign(Request $request, int $id): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $c = $this->requireCampaign($user, $id);

        return new JsonResponse($this->serializeCampaign($c));
    }

    #[Route('/api/marketing/campaigns/{id<\d+>}', name: 'api_marketing_campaigns_update', methods: ['PUT'])]
    public function updateCampaign(Request $request, int $id): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $c = $this->requireCampaign($user, $id);
        $data = $this->decodeJson($request);

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new BadRequestHttpException('name cannot be empty.');
            }
            $c->setName($name);
        }
        if (\array_key_exists('description', $data)) {
            $c->setDescription($data['description'] === null || $data['description'] === '' ? null : (string) $data['description']);
        }
        if (isset($data['channel'])) {
            $c->setChannel($this->normalizeChannel($data['channel']));
        }
        if (isset($data['status'])) {
            $c->setStatus($this->normalizeStatus($data['status']));
        }
        if (isset($data['schedule_mode'])) {
            $c->setScheduleMode($this->normalizeScheduleMode($data['schedule_mode']));
        }
        if (\array_key_exists('scheduled_at', $data)) {
            $c->setScheduledAt($this->parseOptionalDateTime($data['scheduled_at']));
        }
        if (\array_key_exists('cron_expression', $data)) {
            $ce = $data['cron_expression'];
            $c->setCronExpression($ce === null || $ce === '' ? null : (string) $ce);
        }

        $this->applyCampaignRelations($user, $c, $data);
        $this->assertTemplateMatchesChannel($c);
        $this->em->flush();

        return new JsonResponse($this->serializeCampaign($c));
    }

    #[Route('/api/marketing/campaigns/{id<\d+>}', name: 'api_marketing_campaigns_delete', methods: ['DELETE'])]
    public function deleteCampaign(Request $request, int $id): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $c = $this->requireCampaign($user, $id);
        $this->em->remove($c);
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/marketing/campaigns/{id<\d+>}/dispatch', name: 'api_marketing_campaigns_dispatch', methods: ['POST'])]
    public function dispatchCampaign(Request $request, int $id): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $c = $this->requireCampaign($user, $id);
        $data = $this->decodeJson($request);
        $dryRun = !empty($data['dry_run']);

        $send = $this->marketingCampaignDispatchService->dispatch(
            $c,
            MarketingCampaignSend::TRIGGER_MANUAL_API,
            $dryRun,
        );
        $this->em->refresh($c);

        return new JsonResponse([
            'campaign' => $this->serializeCampaign($c),
            'send' => $this->serializeSend($send),
        ]);
    }

    #[Route('/api/marketing/campaigns/{id<\d+>}/sends', name: 'api_marketing_campaigns_sends', methods: ['GET'])]
    public function listCampaignSends(Request $request, int $id): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $c = $this->requireCampaign($user, $id);
        $items = [];
        foreach ($this->marketingCampaignSendRepository->findRecentByCampaign($c, 30) as $s) {
            $items[] = $this->serializeSend($s);
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

    private function normalizeChannel(mixed $channel): string
    {
        $c = strtolower(trim((string) $channel));
        if (!\in_array($c, [ContentTemplate::CHANNEL_EMAIL, ContentTemplate::CHANNEL_SMS], true)) {
            throw new BadRequestHttpException('channel must be "email" or "sms".');
        }

        return $c;
    }

    private function normalizeStatus(mixed $status): string
    {
        $s = strtolower(trim((string) $status));
        $allowed = [
            MarketingCampaign::STATUS_DRAFT,
            MarketingCampaign::STATUS_SCHEDULED,
            MarketingCampaign::STATUS_SENDING,
            MarketingCampaign::STATUS_COMPLETED,
            MarketingCampaign::STATUS_CANCELLED,
            MarketingCampaign::STATUS_PAUSED,
        ];
        if (!\in_array($s, $allowed, true)) {
            throw new BadRequestHttpException('Invalid status.');
        }

        return $s;
    }

    private function normalizeScheduleMode(mixed $mode): string
    {
        $m = strtolower(trim((string) $mode));
        $allowed = [
            MarketingCampaign::SCHEDULE_MANUAL,
            MarketingCampaign::SCHEDULE_ONCE,
            MarketingCampaign::SCHEDULE_CRON,
        ];
        if (!\in_array($m, $allowed, true)) {
            throw new BadRequestHttpException('schedule_mode must be manual, once, or cron.');
        }

        return $m;
    }

    private function parseOptionalDateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!\is_string($value)) {
            throw new BadRequestHttpException('scheduled_at must be an ISO 8601 string or null.');
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            throw new BadRequestHttpException('scheduled_at is not a valid date.');
        }
    }

    private function assertTemplateMatchesChannel(MarketingCampaign $c): void
    {
        $t = $c->getContentTemplate();
        if ($t !== null && $t->getChannel() !== $c->getChannel()) {
            throw new BadRequestHttpException('Content template channel must match campaign channel.');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyCampaignRelations(User $user, MarketingCampaign $c, array $data): void
    {
        if (\array_key_exists('contact_list_id', $data)) {
            $c->setContactList($this->resolveContactList($user, $data['contact_list_id']));
        }
        if (\array_key_exists('content_template_id', $data)) {
            $tid = $data['content_template_id'];
            if ($tid === null || $tid === '') {
                $c->setContentTemplate(null);
            } else {
                $t = $this->contentTemplateRepository->find((int) $tid);
                if (!$t instanceof ContentTemplate || $t->getOwner()->getId() !== $user->getId()) {
                    throw new BadRequestHttpException('Invalid content_template_id.');
                }
                if ($t->getChannel() !== $c->getChannel()) {
                    throw new BadRequestHttpException('Template channel must match campaign channel.');
                }
                $c->setContentTemplate($t);
            }
        }
    }

    private function resolveContactList(User $user, mixed $id): ?ContactList
    {
        if ($id === null || $id === '') {
            return null;
        }
        $list = $this->contactListRepository->find((int) $id);
        if (!$list instanceof ContactList || $list->getOwner()->getId() !== $user->getId()) {
            throw new BadRequestHttpException('Invalid contact_list_id.');
        }

        return $list;
    }

    private function requireTemplate(User $user, int $id): ContentTemplate
    {
        $t = $this->contentTemplateRepository->find($id);
        if (!$t instanceof ContentTemplate || $t->getOwner()->getId() !== $user->getId()) {
            throw new NotFoundHttpException('Template not found.');
        }

        return $t;
    }

    private function requireCampaign(User $user, int $id): MarketingCampaign
    {
        $c = $this->marketingCampaignRepository->find($id);
        if (!$c instanceof MarketingCampaign || $c->getOwner()->getId() !== $user->getId()) {
            throw new NotFoundHttpException('Campaign not found.');
        }

        return $c;
    }

    private function serializeContactList(ContactList $list): array
    {
        return [
            'id' => $list->getId(),
            'name' => $list->getName(),
            'contacts_count' => $list->getContactsCount(),
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

    private function serializeCampaign(MarketingCampaign $c): array
    {
        $last = $this->marketingCampaignSendRepository->findLatestByCampaign($c);

        return [
            'id' => $c->getId(),
            'name' => $c->getName(),
            'description' => $c->getDescription(),
            'channel' => $c->getChannel(),
            'status' => $c->getStatus(),
            'contact_list_id' => $c->getContactList()?->getId(),
            'content_template_id' => $c->getContentTemplate()?->getId(),
            'schedule_mode' => $c->getScheduleMode(),
            'scheduled_at' => $c->getScheduledAt()?->format(DATE_ATOM),
            'cron_expression' => $c->getCronExpression(),
            'created_at' => $c->getCreatedAt()?->format(DATE_ATOM),
            'updated_at' => $c->getUpdatedAt()?->format(DATE_ATOM),
            'last_dispatch' => $last instanceof MarketingCampaignSend ? $this->serializeSendSummary($last) : null,
        ];
    }

    private function serializeSend(MarketingCampaignSend $s): array
    {
        return [
            'id' => $s->getId(),
            'trigger_source' => $s->getTriggerSource(),
            'status' => $s->getStatus(),
            'summary' => $s->getSummary(),
            'error_message' => $s->getErrorMessage(),
            'created_at' => $s->getCreatedAt()?->format(DATE_ATOM),
            'updated_at' => $s->getUpdatedAt()?->format(DATE_ATOM),
        ];
    }

    private function serializeSendSummary(MarketingCampaignSend $s): array
    {
        return [
            'id' => $s->getId(),
            'status' => $s->getStatus(),
            'trigger_source' => $s->getTriggerSource(),
            'summary' => $s->getSummary(),
            'created_at' => $s->getCreatedAt()?->format(DATE_ATOM),
        ];
    }
}
