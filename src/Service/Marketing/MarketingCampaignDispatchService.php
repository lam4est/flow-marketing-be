<?php

declare(strict_types=1);

namespace App\Service\Marketing;

use App\Entity\Contact;
use App\Entity\ContentTemplate;
use App\Entity\MarketingCampaign;
use App\Entity\MarketingCampaignSend;
use App\Repository\ContactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface as MailerTransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class MarketingCampaignDispatchService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ContactRepository $contactRepository,
        private MailerInterface $mailer,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        #[Autowire('%env(WORKFLOW_MAIL_FROM)%')]
        private string $mailFrom,
        #[Autowire('%env(WORKFLOW_EMAIL_WEBHOOK_URL)%')]
        private string $emailWebhookUrl,
        #[Autowire('%env(WORKFLOW_SMS_WEBHOOK_URL)%')]
        private string $smsWebhookUrl,
    ) {
    }

    /**
     * Sends campaign messages to contacts in the segment (email or SMS). Persists a {@see MarketingCampaignSend} row.
     *
     * @param non-empty-string $trigger MarketingCampaignSend::TRIGGER_*
     */
    public function dispatch(MarketingCampaign $campaign, string $trigger, bool $dryRun = false): MarketingCampaignSend
    {
        if ($campaign->getStatus() === MarketingCampaign::STATUS_CANCELLED) {
            throw new BadRequestHttpException('Campaign is cancelled.');
        }

        $template = $campaign->getContentTemplate();
        if (!$template instanceof ContentTemplate) {
            throw new BadRequestHttpException('Campaign has no content template.');
        }

        $list = $campaign->getContactList();
        if (!$list instanceof \App\Entity\ContactList) {
            throw new BadRequestHttpException('Campaign has no contact list.');
        }

        if ($list->getOwner()->getId() !== $campaign->getOwner()->getId()) {
            throw new BadRequestHttpException('Contact list does not belong to the campaign owner.');
        }

        if ($template->getChannel() !== $campaign->getChannel()) {
            throw new BadRequestHttpException('Template channel does not match campaign.');
        }

        if (!$dryRun && $campaign->getStatus() === MarketingCampaign::STATUS_SENDING) {
            throw new ConflictHttpException('Campaign is already being dispatched.');
        }

        $previousStatus = $campaign->getStatus();
        if (!$dryRun) {
            $campaign->setStatus(MarketingCampaign::STATUS_SENDING);
            $this->em->flush();
        }

        $channel = $campaign->getChannel();
        $contacts = $this->contactRepository->findByContactList($list);

        $errors = [];
        $sent = 0;
        $failed = 0;
        $skipped = 0;

        try {
            foreach ($contacts as $contact) {
                $addr = $channel === MarketingCampaign::CHANNEL_EMAIL
                    ? $this->normalizeEmail($contact->getEmail())
                    : $this->normalizePhone($contact->getPhone());

                if ($addr === null || $addr === '') {
                    ++$skipped;
                    continue;
                }

                if ($dryRun) {
                    ++$sent;

                    continue;
                }

                try {
                    if ($channel === MarketingCampaign::CHANNEL_EMAIL) {
                        $this->sendEmailToContact($campaign, $template, $contact, $addr);
                    } else {
                        $this->sendSmsToContact($campaign, $template, $contact, $addr);
                    }
                    ++$sent;
                } catch (\Throwable $e) {
                    ++$failed;
                    $errors[] = [
                        'contact_id' => $contact->getId(),
                        'error' => $e->getMessage(),
                    ];
                    $this->logger->warning('Marketing campaign recipient failed.', [
                        'campaign_id' => $campaign->getId(),
                        'contact_id' => $contact->getId(),
                        'exception' => $e,
                    ]);
                }
            }

            $summary = [
                'channel' => $channel,
                'dry_run' => $dryRun,
                'recipients_in_list' => \count($contacts),
                'sent' => $sent,
                'failed' => $failed,
                'skipped' => $skipped,
                'errors' => $errors,
            ];

            $send = (new MarketingCampaignSend())
                ->setMarketingCampaign($campaign)
                ->setTriggerSource($trigger)
                ->setStatus($failed > 0 && $sent === 0 ? MarketingCampaignSend::STATUS_FAILED : MarketingCampaignSend::STATUS_COMPLETED)
                ->setSummary($summary);

            $this->em->persist($send);

            if (!$dryRun) {
                $this->applyCampaignStatusAfterDispatch($campaign);
            }

            $this->em->flush();

            return $send;
        } catch (\Throwable $e) {
            if (!$dryRun) {
                $campaign->setStatus($previousStatus);
                $this->em->flush();
            }

            throw $e;
        }
    }

    private function applyCampaignStatusAfterDispatch(MarketingCampaign $campaign): void
    {
        if ($campaign->getScheduleMode() === MarketingCampaign::SCHEDULE_CRON) {
            $campaign->setStatus(MarketingCampaign::STATUS_SCHEDULED);
        } else {
            $campaign->setStatus(MarketingCampaign::STATUS_COMPLETED);
        }
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

    private function personalize(?string $text, Contact $contact): string
    {
        $raw = $text ?? '';
        $name = $contact->getDisplayName() ?? 'there';
        $parts = preg_split('/\s+/', trim($name), 2, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = $parts[0] ?? $name;

        return str_replace(
            ['{{name}}', '{{first_name}}', '{{display_name}}'],
            [$name, $first, $name],
            $raw,
        );
    }

    private function sendEmailToContact(
        MarketingCampaign $campaign,
        ContentTemplate $template,
        Contact $contact,
        string $toEmail,
    ): void {
        $subject = $template->getSubject();
        if ($subject === null || trim($subject) === '') {
            $subject = $campaign->getName();
        }
        $subject = $this->personalize($subject, $contact);
        $body = $this->personalize($template->getBody(), $contact);
        if (trim($body) === '') {
            $body = $subject;
        }

        $cid = (int) $contact->getId();
        $mid = sprintf('marketing-campaign-%d-contact-%d@marketing.local', $campaign->getId(), $cid);

        $webhook = trim($this->emailWebhookUrl);
        if ($webhook !== '') {
            $payload = [
                'channel' => 'email',
                'from' => $this->mailFrom,
                'to' => $toEmail,
                'subject' => $subject,
                'body' => $body,
                'message_id' => $mid,
                'marketing_campaign_id' => $campaign->getId(),
                'contact_id' => $contact->getId(),
            ];

            try {
                $response = $this->httpClient->request('POST', $webhook, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Idempotency-Key' => sprintf('mc-email-%d-c-%d', $campaign->getId(), $contact->getId()),
                    ],
                    'json' => $payload,
                    'timeout' => 15,
                ]);
                $status = $response->getStatusCode();
                if ($status < 200 || $status >= 300) {
                    throw new \RuntimeException(sprintf('Email webhook returned HTTP %d.', $status));
                }
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('Marketing email webhook failed.', ['exception' => $e]);

                throw $e;
            }

            return;
        }

        $email = (new Email())
            ->from($this->mailFrom)
            ->to($toEmail)
            ->subject($subject)
            ->text($body);
        $email->getHeaders()->addIdHeader('Message-ID', $mid);

        try {
            $this->mailer->send($email);
        } catch (MailerTransportExceptionInterface $e) {
            $this->logger->error('Marketing campaign email failed.', ['exception' => $e]);

            throw $e;
        }
    }

    private function sendSmsToContact(
        MarketingCampaign $campaign,
        ContentTemplate $template,
        Contact $contact,
        string $toE164,
    ): void {
        $body = $this->personalize($template->getBody(), $contact);
        if (trim($body) === '') {
            $body = $campaign->getName();
        }

        $webhook = trim($this->smsWebhookUrl);
        if ($webhook !== '') {
            $payload = [
                'channel' => 'sms',
                'to' => $toE164,
                'body' => $body,
                'marketing_campaign_id' => $campaign->getId(),
                'contact_id' => $contact->getId(),
            ];

            try {
                $response = $this->httpClient->request('POST', $webhook, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Idempotency-Key' => sprintf('mc-%d-c-%d', $campaign->getId(), $contact->getId()),
                    ],
                    'json' => $payload,
                    'timeout' => 15,
                ]);
                $status = $response->getStatusCode();
                if ($status < 200 || $status >= 300) {
                    throw new \RuntimeException(sprintf('SMS webhook returned HTTP %d.', $status));
                }
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('Marketing SMS webhook failed.', ['exception' => $e]);

                throw $e;
            }

            return;
        }

        $ownerEmail = $campaign->getOwner()->getEmail();
        $preview = sprintf(
            "WORKFLOW_SMS_WEBHOOK_URL is not set; SMS preview to account owner.\n\n".
            "Would send SMS to: %s\nCampaign: %s\nContact: %s (id %s)\n\nBody:\n%s",
            $toE164,
            $campaign->getName(),
            $contact->getDisplayName() ?? '',
            (string) $contact->getId(),
            $body,
        );

        $email = (new Email())
            ->from($this->mailFrom)
            ->to($ownerEmail)
            ->subject(sprintf('[SMS preview] %s', $campaign->getName()))
            ->text($preview);

        try {
            $this->mailer->send($email);
        } catch (MailerTransportExceptionInterface $e) {
            $this->logger->error('Marketing SMS preview email failed.', ['exception' => $e]);

            throw $e;
        }
    }
}
