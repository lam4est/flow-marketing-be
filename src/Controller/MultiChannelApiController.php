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
 * n8n-friendly outbound API — auth via `X-User-Id` (same as other marketing/workflow endpoints).
 *
 * POST /api/multi_channel/send
 *
 * Body (batch):
 *   { "messages": [ { "channel": "sms", "to": "+336...", "template_id": 1 }, ... ] }
 *
 * Body (single):
 *   { "channel": "email", "to": "a@b.c", "template_id": 2, "contact_id": 5 }
 *
 * Optional per message: contact_id (personalization), settings (object, forwarded for phone channels),
 * idempotency_key, workflow_name (subject/body fallback label).
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
