<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MarketingCampaignSend;
use App\Repository\MarketingCampaignRepository;
use App\Service\Marketing\MarketingCampaignDispatchService;
use Cron\CronExpression;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:marketing:dispatch-due',
    description: 'Dispatch marketing campaigns scheduled for "once" (past scheduled_at) or matching cron (status=scheduled).',
)]
final class MarketingDispatchDueCommand extends Command
{
    public function __construct(
        private readonly MarketingCampaignRepository $marketingCampaignRepository,
        private readonly MarketingCampaignDispatchService $marketingCampaignDispatchService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new DateTimeImmutable();

        $byId = [];
        foreach ($this->marketingCampaignRepository->findScheduledOnceDue($now) as $c) {
            $byId[$c->getId()] = $c;
        }

        foreach ($this->marketingCampaignRepository->findCronScheduledCampaigns() as $c) {
            $expr = $c->getCronExpression();
            if ($expr === null || trim($expr) === '') {
                continue;
            }
            try {
                if (CronExpression::factory($expr)->isDue($now)) {
                    $byId[$c->getId()] = $c;
                }
            } catch (\Throwable $e) {
                $io->warning(sprintf('Campaign #%d: invalid cron "%s": %s', $c->getId(), $expr, $e->getMessage()));
            }
        }

        if ($byId === []) {
            $io->success('No due marketing campaigns.');

            return Command::SUCCESS;
        }

        foreach ($byId as $campaign) {
            try {
                $send = $this->marketingCampaignDispatchService->dispatch(
                    $campaign,
                    MarketingCampaignSend::TRIGGER_SCHEDULER,
                    false,
                );
                $summary = $send->getSummary();
                $io->writeln(sprintf(
                    'Campaign #%d "%s": sent=%d failed=%d skipped=%d',
                    $campaign->getId(),
                    $campaign->getName(),
                    (int) ($summary['sent'] ?? 0),
                    (int) ($summary['failed'] ?? 0),
                    (int) ($summary['skipped'] ?? 0),
                ));
            } catch (\Throwable $e) {
                $io->error(sprintf('Campaign #%d: %s', $campaign->getId(), $e->getMessage()));
            }
        }

        return Command::SUCCESS;
    }
}
