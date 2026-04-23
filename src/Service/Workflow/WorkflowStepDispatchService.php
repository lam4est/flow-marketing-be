<?php

declare(strict_types=1);

namespace App\Service\Workflow;

use App\Entity\Workflow\WorkflowStepRun;
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
        #[Autowire('%env(WORKFLOW_MAIL_FROM)%')]
        private string $mailFrom,
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
        $toEmail = $user->getEmail();
        $workflow = $workflowUser->getWorkflow();
        $workflowName = $workflow->getWorkflowName();
        $templateId = (string) ($stepRun->getTemplateIdUsed() ?? '');
        $runId = (int) $stepRun->getWorkflowRun()->getId();
        $stepId = (int) $stepRun->getWorkflowStep()->getId();
        $stepRunId = (int) $stepRun->getId();

        // RFC msg-id form for IdentificationHeader (no angle brackets; Symfony adds them when rendering).
        $messageId = sprintf('workflow-step-run-%d@workflow.local', $stepRunId);

        return match ($channel) {
            'email' => $this->sendEmail(
                $toEmail,
                $messageId,
                $workflowName,
                $runId,
                $stepId,
                $templateId,
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
            ),
        };
    }

    private function sendEmail(
        string $toEmail,
        string $messageId,
        string $workflowName,
        int $runId,
        int $stepId,
        string $templateId,
        string $subjectSuffix = '',
    ): WorkflowDispatchResult {
        $subject = sprintf('[Workflow] %s — step %d, template %s%s', $workflowName, $stepId, $templateId, $subjectSuffix);
        $text = sprintf(
            "Workflow run #%d\nStep #%d\nTemplate: %s\n\nThis is an automated workflow message (email channel).",
            $runId,
            $stepId,
            $templateId,
        );

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
