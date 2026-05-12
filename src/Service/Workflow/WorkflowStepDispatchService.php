<?php

declare(strict_types=1);

namespace App\Service\Workflow;

use App\Entity\Contact;
use App\Entity\ContactList;
use App\Entity\ContentTemplate;
use App\Entity\User;
use App\Entity\Workflow\WorkflowStepRun;
use App\Entity\Workflow\WorkflowUser;
use App\Repository\ContactRepository;
use App\Repository\ContentTemplateRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface as MailerTransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class WorkflowStepDispatchService
{
    public function __construct(
        private MailerInterface $mailer,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private ContactRepository $contactRepository,
        private ContentTemplateRepository $contentTemplateRepository,
        #[Autowire('%env(WORKFLOW_MAIL_FROM)%')]
        private string $mailFrom,
        #[Autowire('%env(WORKFLOW_EMAIL_WEBHOOK_URL)%')]
        private string $emailWebhookUrl,
        #[Autowire('%env(WORKFLOW_SMS_WEBHOOK_URL)%')]
        private string $smsWebhookUrl,
        #[Autowire('%env(WORKFLOW_SMS_DEFAULT_TO)%')]
        private string $smsDefaultTo,
    ) {
    }

    public function dispatch(WorkflowStepRun $stepRun): WorkflowDispatchResult
    {
        $channel = strtolower(trim($stepRun->getChannelUsed()));
        $workflowUser = $stepRun->getWorkflowRun()->getWorkflowUser();
        $user = $workflowUser->getUser();
        $segment = $workflowUser->getSegment();

        if ($segment !== null && $segment->getOwner()->getId() === $user->getId()) {
            return $this->dispatchSegmentStep($stepRun, $workflowUser, $user, $segment, $channel);
        }

        $toEmail = $user->getEmail();
        $workflow = $workflowUser->getWorkflow();
        $workflowName = $workflow->getWorkflowName();
        $templateId = (string) ($stepRun->getTemplateIdUsed() ?? '');
        $runId = (int) $stepRun->getWorkflowRun()->getId();
        $stepId = (int) $stepRun->getWorkflowStep()->getId();
        $stepRunId = (int) $stepRun->getId();

        $messageId = sprintf('workflow-step-run-%d@workflow.local', $stepRunId);

        return match ($channel) {
            'email' => $this->sendEmail(
                $toEmail,
                $messageId,
                $workflowName,
                $runId,
                $stepId,
                $templateId,
                '',
                $stepRunId,
            ),
            'sms', 'rcs' => $this->sendSmsLike(
                $channel,
                $toEmail,
                $messageId,
                $workflowName,
                $runId,
                $stepId,
                $templateId,
                $stepRunId,
            ),
            default => $this->sendEmail(
                $toEmail,
                $messageId,
                $workflowName,
                $runId,
                $stepId,
                $templateId,
                sprintf(' (channel %s treated as email)', $channel),
                $stepRunId,
            ),
        };
    }

    /**
     * When the workflow enrollment has a contact list (segment), send this step to each contact
     * (minus optional per-step excluded list) via webhook or mailer — n8n receives one POST per recipient.
     */
    private function dispatchSegmentStep(
        WorkflowStepRun $stepRun,
        WorkflowUser $workflowUser,
        User $user,
        ContactList $segment,
        string $channel,
    ): WorkflowDispatchResult {
        $templateIdRaw = $stepRun->getTemplateIdUsed();
        if ($templateIdRaw === null || $templateIdRaw === '' || !ctype_digit((string) $templateIdRaw)) {
            throw new \RuntimeException('A numeric content template id is required for segment workflow sends.');
        }

        $template = $this->contentTemplateRepository->findOwnedById($user, (int) $templateIdRaw);
        if (!$template instanceof ContentTemplate) {
            throw new \RuntimeException('Content template not found or not owned by user.');
        }

        $expectedChannel = match ($channel) {
            'sms' => ContentTemplate::CHANNEL_SMS,
            'rcs' => ContentTemplate::CHANNEL_RCS,
            default => ContentTemplate::CHANNEL_EMAIL,
        };
        if ($template->getChannel() !== $expectedChannel) {
            throw new \RuntimeException('Template channel does not match workflow step channel.');
        }

        $stepUser = $stepRun->getWorkflowStepUser();
        $excludedIds = [];
        if ($stepUser !== null) {
            $ex = $stepUser->getExcludedSegment();
            if ($ex !== null && $ex->getOwner()->getId() === $user->getId()) {
                foreach ($this->contactRepository->findByContactList($ex) as $xc) {
                    $id = $xc->getId();
                    if ($id !== null) {
                        $excludedIds[$id] = true;
                    }
                }
            }
            foreach ($stepUser->getExcludedContactIds() as $xcid) {
                $excludedIds[$xcid] = true;
            }
        }

        $contacts = array_values(array_filter(
            $this->contactRepository->findByContactList($segment),
            static fn (Contact $c) => ($c->getId() !== null) && !isset($excludedIds[$c->getId()]),
        ));

        $workflow = $workflowUser->getWorkflow();
        $workflowName = $workflow->getWorkflowName();
        $runId = (int) $stepRun->getWorkflowRun()->getId();
        $stepId = (int) $stepRun->getWorkflowStep()->getId();
        $stepRunId = (int) $stepRun->getId();
        $templateIdStr = (string) $templateIdRaw;

        if ($contacts === []) {
            $this->logger->info('Workflow segment step: no recipients after exclusions.', [
                'workflow_step_run_id' => $stepRunId,
            ]);

            return new WorkflowDispatchResult('segment_skipped_empty', 'segment:'.$stepRunId.':0');
        }

        $sent = 0;
        if (\in_array($channel, ['sms', 'rcs'], true)) {
            $webhook = trim($this->smsWebhookUrl);
            if ($webhook === '') {
                throw new \RuntimeException('WORKFLOW_SMS_WEBHOOK_URL must be set for segment SMS/RCS workflow steps.');
            }
            $smsStepSettings = $stepUser?->getSettings();
            $smsStepSettings = \is_array($smsStepSettings) ? $smsStepSettings : [];
            foreach ($contacts as $contact) {
                $to = $this->normalizePhone($contact->getPhone());
                if ($to === null || $to === '') {
                    continue;
                }
                $body = $this->buildSegmentBody($template, $contact, $workflowName);
                $payload = [
                    'channel' => $channel,
                    'to' => $to,
                    'body' => $body,
                    'template_id' => $templateIdStr,
                    'workflow_run_id' => $runId,
                    'workflow_step_id' => $stepId,
                    'workflow_step_run_id' => $stepRunId,
                    'contact_id' => $contact->getId(),
                    'settings' => $smsStepSettings,
                ];
                $this->postJsonWebhook(
                    $webhook,
                    $payload,
                    sprintf('workflow-seg-%d-c-%d', $stepRunId, $contact->getId()),
                );
                ++$sent;
            }
            if ($sent === 0) {
                throw new \RuntimeException('No segment contacts had a valid phone number for SMS/RCS.');
            }

            return new WorkflowDispatchResult('segment_sms_webhook', 'segment:'.$stepRunId.':'.$sent);
        }

        if ($channel === 'email') {
            foreach ($contacts as $contact) {
                $to = $this->normalizeEmail($contact->getEmail());
                if ($to === null || $to === '') {
                    continue;
                }
                $subject = $template->getSubject();
                if (trim($subject) === '') {
                    $subject = $workflowName;
                }
                $subject = $this->personalize($subject, $contact);
                $body = $this->buildSegmentBody($template, $contact, $workflowName);
                $mid = sprintf('workflow-seg-%d-contact-%d@workflow.local', $stepRunId, $contact->getId());

                $ewh = trim($this->emailWebhookUrl);
                if ($ewh !== '') {
                    $this->postJsonWebhook($ewh, [
                        'channel' => 'email',
                        'from' => $this->mailFrom,
                        'to' => $to,
                        'subject' => $subject,
                        'body' => $body,
                        'message_id' => $mid,
                        'template_id' => $templateIdStr,
                        'workflow_run_id' => $runId,
                        'workflow_step_id' => $stepId,
                        'workflow_step_run_id' => $stepRunId,
                        'contact_id' => $contact->getId(),
                    ], sprintf('workflow-seg-email-%d-c-%d', $stepRunId, $contact->getId()));
                } else {
                    $email = (new Email())
                        ->from($this->mailFrom)
                        ->to($to)
                        ->subject($subject)
                        ->text($body);
                    $email->getHeaders()->addIdHeader('Message-ID', $mid);
                    try {
                        $this->mailer->send($email);
                    } catch (MailerTransportExceptionInterface $e) {
                        $this->logger->error('Workflow segment email failed.', ['exception' => $e]);

                        throw $e;
                    }
                }
                ++$sent;
            }
            if ($sent === 0) {
                throw new \RuntimeException('No segment contacts had a valid email address.');
            }

            $mode = trim($this->emailWebhookUrl) !== '' ? 'segment_email_webhook' : 'segment_email';

            return new WorkflowDispatchResult($mode, 'segment:'.$stepRunId.':'.$sent);
        }

        throw new \RuntimeException(sprintf('Segment dispatch is not implemented for channel "%s".', $channel));
    }

    private function buildSegmentBody(ContentTemplate $template, Contact $contact, string $workflowName): string
    {
        $body = $this->personalize((string) ($template->getBody() ?? ''), $contact);
        if (trim($body) === '') {
            $body = $workflowName;
        }

        return $body;
    }

    private function personalize(string $raw, Contact $contact): string
    {
        $name = $contact->getDisplayName() ?? 'there';
        $parts = preg_split('/\s+/', trim($name), 2, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = $parts[0] ?? $name;

        return str_replace(
            ['{{name}}', '{{first_name}}', '{{display_name}}'],
            [$name, $first, $name],
            $raw,
        );
    }

    private function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        $e = trim($email);

        return $e === '' ? null : $e;
    }

    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
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
                'timeout' => 15,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException(sprintf('Webhook returned HTTP %d.', $status));
            }
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Workflow webhook request failed.', ['exception' => $e]);

            throw $e;
        }
    }

    private function sendEmail(
        string $toEmail,
        string $messageId,
        string $workflowName,
        int $runId,
        int $stepId,
        string $templateId,
        string $subjectSuffix = '',
        int $stepRunId = 0,
    ): WorkflowDispatchResult {
        $subject = sprintf('[Workflow] %s — step %d, template %s%s', $workflowName, $stepId, $templateId, $subjectSuffix);
        $text = sprintf(
            "Workflow run #%d\nStep #%d\nTemplate: %s\n\nThis is an automated workflow message (email channel).",
            $runId,
            $stepId,
            $templateId,
        );

        $webhook = trim($this->emailWebhookUrl);
        if ($webhook !== '') {
            $payload = [
                'channel' => 'email',
                'from' => $this->mailFrom,
                'to' => $toEmail,
                'subject' => $subject,
                'body' => $text,
                'message_id' => $messageId,
                'template_id' => $templateId,
                'workflow_run_id' => $runId,
                'workflow_step_id' => $stepId,
                'workflow_step_run_id' => $stepRunId,
            ];

            try {
                $response = $this->httpClient->request('POST', $webhook, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Idempotency-Key' => 'workflow-step-run-email-'.$stepRunId,
                    ],
                    'json' => $payload,
                    'timeout' => 15,
                ]);
                $status = $response->getStatusCode();
                if ($status < 200 || $status >= 300) {
                    throw new \RuntimeException(sprintf('Email webhook returned HTTP %d.', $status));
                }
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('Workflow email webhook request failed.', ['exception' => $e]);

                throw $e;
            }

            $this->logger->info('Workflow step email webhook dispatched.', [
                'webhook' => $webhook,
                'to' => $toEmail,
            ]);

            return new WorkflowDispatchResult('email_webhook', 'webhook:'.$stepRunId);
        }

        $email = (new Email())
            ->from($this->mailFrom)
            ->to($toEmail)
            ->subject($subject)
            ->text($text);
        $email->getHeaders()->addIdHeader('Message-ID', $messageId);

        try {
            $this->mailer->send($email);
        } catch (MailerTransportExceptionInterface $e) {
            $this->logger->error('Workflow email dispatch failed.', ['exception' => $e]);

            throw $e;
        }

        $this->logger->info('Workflow step email dispatched.', [
            'message_id' => $messageId,
            'to' => $toEmail,
        ]);

        return new WorkflowDispatchResult('email', $messageId);
    }

    private function sendSmsLike(
        string $channel,
        string $ownerEmail,
        string $messageId,
        string $workflowName,
        int $runId,
        int $stepId,
        string $templateId,
        int $stepRunId,
    ): WorkflowDispatchResult {
        $webhook = trim($this->smsWebhookUrl);
        if ($webhook !== '') {
            $to = trim($this->smsDefaultTo);
            if ($to === '') {
                throw new \RuntimeException('WORKFLOW_SMS_DEFAULT_TO must be set when WORKFLOW_SMS_WEBHOOK_URL is used.');
            }

            $body = sprintf(
                '[%s] %s | run=%d step=%d template=%s',
                strtoupper($channel),
                $workflowName,
                $runId,
                $stepId,
                $templateId,
            );

            $payload = [
                'channel' => $channel,
                'to' => $to,
                'body' => $body,
                'template_id' => $templateId,
                'workflow_run_id' => $runId,
                'workflow_step_id' => $stepId,
                'workflow_step_run_id' => $stepRunId,
            ];

            try {
                $response = $this->httpClient->request('POST', $webhook, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Idempotency-Key' => 'workflow-step-run-'.$stepRunId,
                    ],
                    'json' => $payload,
                    'timeout' => 15,
                ]);
                $status = $response->getStatusCode();
                if ($status < 200 || $status >= 300) {
                    throw new \RuntimeException(sprintf('SMS webhook returned HTTP %d.', $status));
                }
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('Workflow SMS webhook request failed.', ['exception' => $e]);

                throw $e;
            }

            $this->logger->info('Workflow step SMS/RCS webhook dispatched.', [
                'channel' => $channel,
                'webhook' => $webhook,
            ]);

            return new WorkflowDispatchResult('sms_webhook', 'webhook:'.$stepRunId);
        }

        $subject = sprintf('[Workflow %s preview] %s — run %d step %d', strtoupper($channel), $workflowName, $runId, $stepId);
        $text = sprintf(
            "No WORKFLOW_SMS_WEBHOOK_URL is configured; sending owner email preview instead.\n\n".
            "Channel: %s\nWorkflow: %s\nRun: %d\nStep: %d\nTemplate: %s\n\n".
            "To send real SMS/RCS, set WORKFLOW_SMS_WEBHOOK_URL and WORKFLOW_SMS_DEFAULT_TO (E.164).",
            $channel,
            $workflowName,
            $runId,
            $stepId,
            $templateId,
        );

        $email = (new Email())
            ->from($this->mailFrom)
            ->to($ownerEmail)
            ->subject($subject)
            ->text($text);
        $previewMessageId = sprintf('workflow-step-run-%d-preview@workflow.local', $stepRunId);
        $email->getHeaders()->addIdHeader('Message-ID', $previewMessageId);

        try {
            $this->mailer->send($email);
        } catch (MailerTransportExceptionInterface $e) {
            $this->logger->error('Workflow SMS preview email failed.', ['exception' => $e]);

            throw $e;
        }

        return new WorkflowDispatchResult('email_sms_preview', $previewMessageId);
    }
}
