<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\CurrentUserResolver;
use App\Service\Integration\MultiChannelSendService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * n8n-friendly outbound API — auth via `X-User-Id` (same as campaign-workflows / workflows).
 *
 * POST /api/multi_channel/send
 * Headers: `Content-Type: application/json`, `Accept: application/json`, `X-User-Id: <owner user id>`
 *
 * Response: `{ "ok": true|false, "results": [ { "index": 0, "ok": true, "channel": "sms", "mode": "sms_webhook", "reference": "+33..." }, ... ] }`
 *
 * Body — batch (recommended for HTTP Request node):
 *   `{ "messages": [ { "channel": "sms", "to": "+336...", "template_id": 1, "sender": "MyBrand", "message": "Hello {{first_name}}" }, ... ] }`
 *
 * Body — single message:
 *   `{ "channel": "email", "to": "user@example.com", "template_id": 2, "sender": "noreply@example.com", "message": "Hi {{name}}", "contact_id": 5 }`
 *
 * Channels: `sms`, `rcs`, `email`, `voice`, `voice_sms`. Template must match channel (email/sms/rcs templates in DB;
 * `voice` / `voice_sms` use an **rcs** content template, same as the workflow UI).
 *
 * Required per message: `channel`, `to` (E.164 or digits for phone; email address for email), `template_id` (integer, owned by user),
 * `sender` (non-empty; for `email`, use a valid mailbox address), `message` (non-empty body text; supports `{{name}}`, `{{first_name}}`, `{{display_name}}` when `contact_id` is set).
 *
 * Optional per message: `contact_id` (personalize `{{name}}`, `{{first_name}}`, `{{display_name}}`),
 * `subject` (override email subject), `message_id` (email Message-ID), `settings` (object; merged into SMS/RCS/voice webhook JSON),
 * `idempotency_key` (forwarded as `Idempotency-Key` header to webhooks), `workflow_name` (fallback text when template body is empty).
 *
 * Delivery: email uses `WORKFLOW_EMAIL_WEBHOOK_URL` if set (payload `from` = request `sender`), otherwise Symfony mailer with `sender` as From.
 * SMS, RCS, voice use `WORKFLOW_SMS_WEBHOOK_URL` (required for those channels).
 */
final class MultiChannelApiController
{
    public function __construct(
        private readonly CurrentUserResolver $currentUserResolver,
        private readonly MultiChannelSendService $multiChannelSendService,
    ) {
    }

    #[Route('/api/multi_channel/send', name: 'api_multi_channel_send', methods: ['POST'])]
    public function send(Request $request): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $data = $this->decodeJson($request);
        $messages = $this->normalizeMessages($data);

        if ($messages === []) {
            throw new BadRequestHttpException('No messages to send.');
        }

        $payload = $this->multiChannelSendService->sendBatch($user, $messages);

        return new JsonResponse($payload);
    }

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeMessages(array $data): array
    {
        if (isset($data['messages'])) {
            $list = $data['messages'];
            if (!\is_array($list)) {
                throw new BadRequestHttpException('"messages" must be an array.');
            }
            $out = [];
            foreach ($list as $item) {
                if (\is_array($item)) {
                    $out[] = $item;
                }
            }

            return $out;
        }

        if (isset($data['channel'], $data['to'])) {
            return [$data];
        }

        throw new BadRequestHttpException(
            'Provide a "messages" array, or a single object with "channel" and "to".',
        );
    }
}
