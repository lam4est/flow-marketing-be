<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Entity\Contact;
use App\Entity\ContentTemplate;
use App\Entity\User;
use App\Repository\ContactRepository;
use App\Repository\ContentTemplateRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface as MailerTransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Standalone outbound sends for n8n — same plumbing as workflow segment dispatch (webhooks / mailer).
 * Each request must include explicit `sender` and `message`; template is still validated for channel/ownership and referenced in payloads.
 *
 * @phpstan-type TMessage array<string, mixed>
 * @phpstan-type TResult array{ok: bool, channel?: string, mode?: string, reference?: string, error?: string}
 */
final readonly class MultiChannelSendService
{
    private const ALLOWED_CHANNELS = ['sms', 'rcs', 'email', 'voice', 'voice_sms'];

    public function __construct(
        private MailerInterface $mailer,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private ContactRepository $contactRepository,
        private ContentTemplateRepository $contentTemplateRepository,
        #[Autowire('%env(WORKFLOW_EMAIL_WEBHOOK_URL)%')]
        private string $emailWebhookUrl,
        #[Autowire('%env(WORKFLOW_SMS_WEBHOOK_URL)%')]
        private string $smsWebhookUrl,
    ) {
    }

    /**
     * @param list<TMessage> $messages
     *
     * @return array{ok: bool, results: list<array<string, mixed>>}
     */
    public function sendBatch(User $user, array $messages): array
    {
        $results = [];
        foreach ($messages as $index => $msg) {
            if (!\is_array($msg)) {
                $results[] = ['index' => $index, 'ok' => false, 'error' => 'Each message must be a JSON object.'];
                continue;
            }
            try {
                $one = $this->sendOne($user, $msg);
                $results[] = array_merge(['index' => $index], $one);
            } catch (\Throwable $e) {
                $this->logger->error('Multi-channel send failed.', ['exception' => $e, 'index' => $index]);
                $results[] = [
                    'index' => $index,
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $allOk = true;
        foreach ($results as $row) {
            if (($row['ok'] ?? false) !== true) {
                $allOk = false;
                break;
            }
        }

        return ['ok' => $allOk, 'results' => $results];
    }

    /**
     * @param TMessage $msg
     *
     * @return TResult
     */
    private function sendOne(User $user, array $msg): array
    {
        $channel = strtolower(trim((string) ($msg['channel'] ?? '')));
        if ($channel === '') {
            return ['ok' => false, 'error' => 'channel is required.'];
        }
        if (!\in_array($channel, self::ALLOWED_CHANNELS, true)) {
            return ['ok' => false, 'error' => sprintf(
                'channel must be one of: %s.',
                implode(', ', self::ALLOWED_CHANNELS),
            )];
        }

        $toRaw = trim((string) ($msg['to'] ?? ''));
        if ($toRaw === '') {
            return ['ok' => false, 'error' => 'to is required.'];
        }

        $tid = $msg['template_id'] ?? null;
        if ($tid === null || (!\is_int($tid) && !(is_string($tid) && ctype_digit($tid)))) {
            return ['ok' => false, 'error' => 'template_id must be a positive integer.'];
        }
        $templateId = (int) $tid;
        if ($templateId < 1) {
            return ['ok' => false, 'error' => 'template_id must be a positive integer.'];
        }

        $template = $this->contentTemplateRepository->findOwnedById($user, $templateId);
        if (!$template instanceof ContentTemplate) {
            return ['ok' => false, 'error' => 'Content template not found or not owned by user.'];
        }

        $expectedChannel = $this->expectedTemplateChannel($channel);
        if ($template->getChannel() !== $expectedChannel) {
            return ['ok' => false, 'error' => sprintf(
                'Template channel "%s" does not match message channel "%s" (expects template channel "%s").',
                $template->getChannel(),
                $channel,
                $expectedChannel,
            )];
        }

        if (\array_key_exists('contact_id', $msg)) {
            $cidRaw = $msg['contact_id'];
            if ($cidRaw !== null && $cidRaw !== ''
                && !\is_int($cidRaw)
                && !(\is_string($cidRaw) && ctype_digit((string) $cidRaw))) {
                return ['ok' => false, 'error' => 'contact_id must be a positive integer.'];
            }
        }

        $contact = $this->resolveContact($user, $msg);
        if ($contact === null && \array_key_exists('contact_id', $msg) && ($msg['contact_id'] ?? null) !== null && $msg['contact_id'] !== '') {
            return ['ok' => false, 'error' => 'contact_id not found or not owned by user.'];
        }

        $senderRaw = trim((string) ($msg['sender'] ?? ''));
        if ($senderRaw === '') {
            return ['ok' => false, 'error' => 'sender is required.'];
        }

        if (!isset($msg['message']) || !\is_string($msg['message'])) {
            return ['ok' => false, 'error' => 'message must be a string.'];
        }
        if (trim($msg['message']) === '') {
            return ['ok' => false, 'error' => 'message is required.'];
        }

        if ($channel === 'email' && !$this->isValidEmailSender($senderRaw)) {
            return ['ok' => false, 'error' => 'sender must be a valid email address (or "Name <email@...>") for channel email.'];
        }

        $fallbackLabel = trim((string) ($msg['workflow_name'] ?? $template->getName()));
        $settings = $msg['settings'] ?? [];
        $settings = \is_array($settings) ? $settings : [];

        $bodyText = $this->personalize(trim($msg['message']), $contact);

        if ($channel === 'email') {
            return $this->dispatchEmail(
                $template,
                $toRaw,
                $contact,
                $msg,
                $fallbackLabel,
                $senderRaw,
                $bodyText,
            );
        }

        if (\in_array($channel, ['sms', 'rcs', 'voice', 'voice_sms'], true)) {
            return $this->dispatchSmsLike(
                $channel,
                $template,
                $toRaw,
                $contact,
                $settings,
                $msg,
                $senderRaw,
                $bodyText,
            );
        }

        return ['ok' => false, 'error' => sprintf('Unsupported channel "%s".', $channel)];
    }

    private function expectedTemplateChannel(string $channel): string
    {
        return match ($channel) {
            'sms' => ContentTemplate::CHANNEL_SMS,
            'rcs', 'voice', 'voice_sms' => ContentTemplate::CHANNEL_RCS,
            'email' => ContentTemplate::CHANNEL_EMAIL,
            default => ContentTemplate::CHANNEL_EMAIL,
        };
    }

    /**
     * @param TMessage $msg
     */
    private function resolveContact(User $user, array $msg): ?Contact
    {
        $cid = $msg['contact_id'] ?? null;
        if ($cid === null || $cid === '') {
            return null;
        }
        if (!\is_int($cid) && !(is_string($cid) && ctype_digit((string) $cid))) {
            return null;
        }

        return $this->contactRepository->findOwnedById($user, (int) $cid);
    }

    /**
     * @param TMessage $msg
     *
     * @return TResult
     */
    private function dispatchEmail(
        ContentTemplate $template,
        string $to,
        ?Contact $contact,
        array $msg,
        string $fallbackLabel,
        string $sender,
        string $body,
    ): array {
        $toAddr = $this->normalizeEmail($to);
        if ($toAddr === null) {
            return ['ok' => false, 'error' => 'Invalid email in "to".'];
        }

        $subject = isset($msg['subject']) && \is_string($msg['subject']) && trim($msg['subject']) !== ''
            ? trim($msg['subject'])
            : $template->getSubject();
        if ($contact instanceof Contact) {
            $subject = $this->personalize($subject, $contact);
        }
        if (trim($subject) === '') {
            $subject = $fallbackLabel !== '' ? $fallbackLabel : $template->getName();
        }

        $mid = isset($msg['message_id']) && \is_string($msg['message_id']) && $msg['message_id'] !== ''
            ? $msg['message_id']
            : sprintf('multi-channel-email-%s@api.local', bin2hex(random_bytes(8)));

        $webhook = trim($this->emailWebhookUrl);
        if ($webhook !== '') {
            $payload = [
                'channel' => 'email',
                'from' => $sender,
                'to' => $toAddr,
                'subject' => $subject,
                'body' => $body,
                'message_id' => $mid,
                'template_id' => (string) $template->getId(),
                'contact_id' => $contact?->getId(),
                'source' => 'multi_channel_api',
            ];
            $this->postJsonWebhook($webhook, $payload, $this->idempotencyKey($msg, 'email', $toAddr));

            return ['ok' => true, 'channel' => 'email', 'mode' => 'email_webhook', 'reference' => $mid];
        }

        $email = (new Email())
            ->from($sender)
            ->to($toAddr)
            ->subject($subject)
            ->text($body);
        $email->getHeaders()->addIdHeader('Message-ID', $mid);

        try {
            $this->mailer->send($email);
        } catch (MailerTransportExceptionInterface $e) {
            $this->logger->error('Multi-channel email failed.', ['exception' => $e]);

            throw $e;
        }

        return ['ok' => true, 'channel' => 'email', 'mode' => 'email', 'reference' => $mid];
    }

    /**
     * SMS / RCS / Voice use WORKFLOW_SMS_WEBHOOK_URL; body is the API `message` (personalized).
     *
     * @param TMessage $msg
     *
     * @return TResult
     */
    private function dispatchSmsLike(
        string $channel,
        ContentTemplate $template,
        string $to,
        ?Contact $contact,
        array $stepSettings,
        array $msg,
        string $sender,
        string $body,
    ): array {
        $webhook = trim($this->smsWebhookUrl);
        if ($webhook === '') {
            return ['ok' => false, 'error' => 'WORKFLOW_SMS_WEBHOOK_URL must be set for SMS, RCS, and Voice sends.'];
        }

        $toPhone = $this->normalizePhone($to);
        if ($toPhone === null || $toPhone === '') {
            return ['ok' => false, 'error' => 'Invalid phone in "to" (use E.164 or digits).'];
        }

        $payload = [
            'channel' => $channel,
            'sender' => $sender,
            'to' => $toPhone,
            'body' => $body,
            'template_id' => (string) $template->getId(),
            'contact_id' => $contact?->getId(),
            'settings' => $stepSettings,
            'source' => 'multi_channel_api',
        ];

        $this->postJsonWebhook($webhook, $payload, $this->idempotencyKey($msg, $channel, $toPhone));

        return ['ok' => true, 'channel' => $channel, 'mode' => 'sms_webhook', 'reference' => $toPhone];
    }

    /**
     * @param TMessage $msg
     */
    private function idempotencyKey(array $msg, string $channel, string $dest): string
    {
        $custom = $msg['idempotency_key'] ?? null;
        if (\is_string($custom) && $custom !== '') {
            return $custom;
        }

        return 'mc-'.hash('sha256', $channel.'|'.$dest.'|'.((string) ($msg['template_id'] ?? '')).'|'.microtime(true));
    }

    private function personalize(string $raw, ?Contact $contact): string
    {
        if (!$contact instanceof Contact) {
            return $raw;
        }

        $name = $contact->getDisplayName() ?? 'there';
        $parts = preg_split('/\s+/', trim($name), 2, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = $parts[0] ?? $name;

        return str_replace(
            ['{{name}}', '{{first_name}}', '{{display_name}}'],
            [$name, $first, $name],
            $raw,
        );
    }

    private function isValidEmailSender(string $sender): bool
    {
        if (filter_var($sender, FILTER_VALIDATE_EMAIL)) {
            return true;
        }
        if (preg_match('/<\s*([^>]+)\s*>/', $sender, $m) === 1) {
            $inner = trim($m[1]);

            return filter_var($inner, FILTER_VALIDATE_EMAIL) !== false;
        }

        return false;
    }

    private function normalizeEmail(string $email): ?string
    {
        $e = trim($email);

        return $e === '' ? null : $e;
    }

    private function normalizePhone(string $phone): ?string
    {
        $p = preg_replace('/\s+/', '', trim($phone));

        return $p === '' ? null : $p;
    }

    private function postJsonWebhook(string $url, array $payload, string $idempotencyKey): void
    {
        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Idempotency-Key' => $idempotencyKey,
                ],
                'json' => $payload,
                'timeout' => 30,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException(sprintf('Webhook returned HTTP %d.', $status));
            }
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Multi-channel webhook request failed.', ['exception' => $e]);

            throw $e;
        }
    }
}
