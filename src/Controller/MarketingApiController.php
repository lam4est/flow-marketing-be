<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Contact;
use App\Entity\ContactList;
use App\Entity\ContentTemplate;
use App\Entity\User;
use App\Repository\ContactListRepository;
use App\Repository\ContactRepository;
use App\Repository\ContentTemplateRepository;
use App\Service\CurrentUserResolver;
use App\Service\Integration\CampaignWorkflowN8nListService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contact lists + content templates live under `/api/marketing/*` for historical layout.
 * n8n audience (same `/api/` prefix as workflows): **GET /api/contact** — optional `contact_list_id` (omit or `null` for all owned contacts).
 * Campaign workflow exports: **GET /api/campaign-workflows** (canonical). **GET /api/marketing/campaigns** is a legacy alias.
 */
final class MarketingApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurrentUserResolver $currentUserResolver,
        private readonly ContactListRepository $contactListRepository,
        private readonly ContactRepository $contactRepository,
        private readonly ContentTemplateRepository $contentTemplateRepository,
        private readonly CampaignWorkflowN8nListService $campaignWorkflowN8nListService,
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

        $subject = $this->normalizeTemplateSubject($data['subject'] ?? null, $name);

        $t = (new ContentTemplate())
            ->setOwner($user)
            ->setName($name)
            ->setChannel($channel)
            ->setSubject($subject)
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
            $t->setSubject($this->normalizeTemplateSubject($data['subject'], $t->getName()));
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

    /**
     * Campaign Workflow enrollments (WorkflowUser) — same domain as GET /api/workflows, enriched for n8n.
     *
     * Query: optional status (scheduled|active|inactive|…), channel (sms|email|rcs|…),
     * include=contacts,templates — {@see CampaignWorkflowN8nListService}.
     * With include=templates, every step with template_id gets content_template + message.
     * With include=contacts, the same applies only for sms, rcs, email, voice, voice_sms steps.
     */
    #[Route('/api/campaign-workflows', name: 'api_campaign_workflows_list', methods: ['GET'])]
    #[Route('/api/marketing/campaigns', name: 'api_marketing_campaigns_list', methods: ['GET'])]
    public function listCampaignWorkflowEnrollments(Request $request): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);

        return new JsonResponse([
            'items' => $this->campaignWorkflowN8nListService->buildItems($user, $request),
        ]);
    }

    /**
     * Contacts for n8n — optional filter by contact list (same `/api/` base as campaign-workflows).
     *
     * - No query (or empty / literal "null"): all contacts owned by the user.
     * - contact_list_id=<positive int>: contacts in that list (must belong to the user).
     */
    #[Route('/api/contact', name: 'api_contact_index', methods: ['GET'])]
    public function listContacts(Request $request): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $listId = $this->parseOptionalContactListIdQuery($request->query->get('contact_list_id'));

        if ($listId !== null) {
            $list = $this->requireOwnedContactList($user, $listId);
            $items = [];
            foreach ($this->contactRepository->findByContactList($list) as $contact) {
                $items[] = $this->serializeMarketingContact($contact);
            }

            return new JsonResponse([
                'contact_list' => $this->serializeContactList($list),
                'items' => $items,
            ]);
        }

        $items = [];
        foreach ($this->contactRepository->findForOwner($user) as $contact) {
            $items[] = $this->serializeMarketingContact($contact);
        }

        return new JsonResponse([
            'contact_list' => null,
            'items' => $items,
        ]);
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
        if (!\in_array($c, [ContentTemplate::CHANNEL_EMAIL, ContentTemplate::CHANNEL_SMS, ContentTemplate::CHANNEL_RCS], true)) {
            throw new BadRequestHttpException('channel must be "email", "sms", or "rcs".');
        }

        return $c;
    }

    /**
     * Subject is required and stored non-null; empty input falls back to template name.
     */
    private function normalizeTemplateSubject(mixed $subject, string $templateName): string
    {
        $s = trim((string) ($subject ?? ''));
        if ($s !== '') {
            return $s;
        }
        $fallback = trim($templateName);
        if ($fallback === '') {
            throw new BadRequestHttpException('subject is required (non-empty), or provide a non-empty template name.');
        }

        return $fallback;
    }

    private function requireTemplate(User $user, int $id): ContentTemplate
    {
        $t = $this->contentTemplateRepository->find($id);
        if (!$t instanceof ContentTemplate || $t->getOwner()->getId() !== $user->getId()) {
            throw new NotFoundHttpException('Template not found.');
        }

        return $t;
    }

    private function requireOwnedContactList(User $user, int $id): ContactList
    {
        $list = $this->contactListRepository->find($id);
        if (!$list instanceof ContactList || $list->getOwner()->getId() !== $user->getId()) {
            throw new NotFoundHttpException('Contact list not found.');
        }

        return $list;
    }

    /**
     * Missing / empty / literal "null" => no filter (all contacts). Otherwise positive integer.
     */
    private function parseOptionalContactListIdQuery(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (\is_string($raw)) {
            $t = strtolower(trim($raw));
            if ($t === '' || $t === 'null') {
                return null;
            }
        }

        $id = (int) $raw;
        if ($id < 1) {
            throw new BadRequestHttpException('contact_list_id must be a positive integer.');
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMarketingContact(Contact $contact): array
    {
        return [
            'id' => $contact->getId(),
            'contact_list_id' => $contact->getContactList()?->getId(),
            'display_name' => $contact->getDisplayName(),
            'email' => $contact->getEmail(),
            'phone' => $contact->getPhone(),
            'created_at' => $contact->getCreatedAt()?->format(DATE_ATOM),
            'updated_at' => $contact->getUpdatedAt()?->format(DATE_ATOM),
        ];
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
}
