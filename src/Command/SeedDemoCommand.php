<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ContactList;
use App\Entity\SchedulerEvent;
use App\Entity\SchedulerEventSubscription;
use App\Entity\User;
use App\Entity\Workflow\Workflow;
use App\Entity\Workflow\WorkflowStep;
use App\Entity\Workflow\WorkflowStepUser;
use App\Entity\Workflow\WorkflowUser;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:seed-demo', description: 'Load demo user, workflows, scheduler events (dev).')]
final class SeedDemoCommand extends Command
{
    private const MONTH_MAP = [
        'january' => 1,
        'february' => 2,
        'march' => 3,
        'april' => 4,
        'may' => 5,
        'june' => 6,
        'july' => 7,
        'august' => 8,
        'september' => 9,
        'october' => 10,
        'november' => 11,
        'december' => 12,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->truncateAppTables();
        $this->em->clear();

        $user = (new User())->setEmail('demo@example.com');
        $this->em->persist($user);

        $list = (new ContactList())
            ->setOwner($user)
            ->setName('Demo segment')
            ->setContactsCount(42);
        $this->em->persist($list);
        $this->em->flush();

        $wf1 = (new Workflow())
            ->setCategory('activation')
            ->setWorkflowKey('welcome')
            ->setWorkflowName('Welcome flow')
            ->setDescription('Welcome new contacts');
        $wf2 = (new Workflow())
            ->setCategory('retention')
            ->setWorkflowKey('reactivation')
            ->setWorkflowName('Reactivation flow')
            ->setDescription('Bring users back');
        $this->em->persist($wf1);
        $this->em->persist($wf2);
        $this->em->flush();

        $s1 = (new WorkflowStep())
            ->setWorkflow($wf1)
            ->setStatus(0)
            ->setStepOrder(1)
            ->setChannel('sms')
            ->setDelayUnit('minute')
            ->setDelayValue(0);
        $s2 = (new WorkflowStep())
            ->setWorkflow($wf1)
            ->setStatus(0)
            ->setStepOrder(2)
            ->setChannel('email')
            ->setDelayUnit('day')
            ->setDelayValue(1);
        $s3 = (new WorkflowStep())
            ->setWorkflow($wf2)
            ->setStatus(0)
            ->setStepOrder(1)
            ->setChannel('rcs')
            ->setDelayUnit('day')
            ->setDelayValue(2);
        foreach ([$s1, $s2, $s3] as $s) {
            $this->em->persist($s);
        }
        $this->em->flush();

        $wu1 = (new WorkflowUser())
            ->setUser($user)
            ->setOriginalWorkflow($wf1)
            ->setWorkflow($wf1)
            ->setIsActive(true);
        $wu2 = (new WorkflowUser())
            ->setUser($user)
            ->setOriginalWorkflow($wf2)
            ->setWorkflow($wf2)
            ->setIsActive(false);
        $this->em->persist($wu1);
        $this->em->persist($wu2);
        $this->em->flush();

        foreach ([$s1, $s2] as $step) {
            $wsu = (new WorkflowStepUser())
                ->setUser($user)
                ->setWorkflowUser($wu1)
                ->setWorkflowStep($step)
                ->setChannel($step->getChannel())
                ->setIsActive(true)
                ->setTemplateId($step === $s1 ? '1' : '10')
                ->setDelayInMinutes($this->delayMinutesFromTemplate($step))
                ->setIsConfirmedByUser(true);
            $this->em->persist($wsu);
        }

        $wsuRcs = (new WorkflowStepUser())
            ->setUser($user)
            ->setWorkflowUser($wu2)
            ->setWorkflowStep($s3)
            ->setChannel('rcs')
            ->setIsActive(true)
            ->setTemplateId('2')
            ->setDelayInMinutes($this->delayMinutesFromTemplate($s3))
            ->setIsConfirmedByUser(true);
        $this->em->persist($wsuRcs);
        $this->em->flush();

        foreach ($this->calendarRows() as $row) {
            [$monthKey, $id, $day, $nameKey, $transKey] = $row;
            $month = self::MONTH_MAP[$monthKey];
            $ev = (new SchedulerEvent())
                ->setId($id)
                ->setName(ucfirst(str_replace('_', ' ', $nameKey)))
                ->setDay($day)
                ->setMonth($month)
                ->setNameKey($nameKey)
                ->setTranslationKey($transKey)
                ->setLanguage('en');
            $this->em->persist($ev);
        }
        $this->em->flush();

        // Seed a few active scheduler subscriptions for the demo user
        // so the Auto Schedule UI can show "saved" state from DB.
        foreach ([
            // scheduler_event_id, channel, isActive, hour, minute, daysBefore, contactsCount, costPerContact, templateId
            [1, 'sms', true, 9, 0, 0, 1200, 0.02, '1'],
            [5, 'sms', true, 10, 15, 2, 800, 0.02, '2'],
            [10, 'email', true, 8, 30, 1, 350, 0.01, '10'],
        ] as [$eventId, $channel, $isActive, $hour, $minute, $daysBefore, $contactsCount, $costPerContact, $templateId]) {
            $event = $this->em->find(SchedulerEvent::class, (int) $eventId);
            if (!$event instanceof SchedulerEvent) {
                continue;
            }
            $sub = (new SchedulerEventSubscription())
                ->setUser($user)
                ->setSchedulerEvent($event)
                ->setChannel((string) $channel)
                ->setIsActive((bool) $isActive)
                ->setHour((int) $hour)
                ->setMinute((int) $minute)
                ->setDaysBefore((int) $daysBefore)
                ->setEstimatedNumberOfContacts((int) $contactsCount)
                ->setCostPerContact((float) $costPerContact)
                ->setTemplateId((string) $templateId)
                ->setContactList($list);
            $this->em->persist($sub);
        }
        $this->em->flush();

        $output->writeln(sprintf('Demo data loaded. Default API user: id=%d (%s).', $user->getId(), $user->getEmail()));

        return Command::SUCCESS;
    }

    private function truncateAppTables(): void
    {
        $conn = $this->em->getConnection();
        $platform = $conn->getDatabasePlatform();
        $isSqlite = $platform instanceof SQLitePlatform;
        if ($isSqlite) {
            $conn->executeStatement('PRAGMA foreign_keys = OFF');
        }

        foreach ([
            'workflow_step_run',
            'workflow_run',
            'scheduler_event_history',
            'scheduler_event_subscription',
            'workflow_step_user',
            'workflow_user',
            'workflow_step',
            'workflows',
            'scheduler_event',
            'contact',
            'contact_list',
            'users',
        ] as $table) {
            try {
                $conn->executeStatement(sprintf('DELETE FROM %s', $table));
            } catch (\Throwable) {
                // table may not exist yet
            }
        }

        if ($isSqlite) {
            $conn->executeStatement('PRAGMA foreign_keys = ON');
        }
    }

    private function delayMinutesFromTemplate(WorkflowStep $step): int
    {
        $v = $step->getDelayValue() ?? 0;
        $u = $step->getDelayUnit() ?? 'minute';

        return match ($u) {
            'day' => $v * 1440,
            'hour' => $v * 60,
            default => $v,
        };
    }

    /**
     * @return list<array{0: string, 1: int, 2: int, 3: string, 4: string}>
     */
    private function calendarRows(): array
    {
        return [
            ['january', 1, 1, 'new_year', 'new_year'],
            ['january', 2, 10, 'winter_sales', 'winter_sales'],
            ['january', 3, 20, 'winter_sales_2', 'winter_sales'],
            ['february', 4, 13, 'mardi_gras', 'mardi_gras'],
            ['february', 5, 14, 'valentine', 'valentine'],
            ['february', 6, 8, 'womens_day', 'womens_day'],
            ['march', 7, 17, 'st_patrick', 'st_patrick'],
            ['march', 8, 20, 'spring', 'spring'],
            ['march', 9, 31, 'easter_monday', 'easter_monday'],
            ['april', 10, 1, 'april_fools', 'april_fools'],
            ['april', 11, 15, 'spring_sales', 'spring_sales'],
            ['april', 12, 22, 'earth_day', 'earth_day'],
            ['may', 13, 1, 'labor_day', 'labor_day'],
            ['may', 14, 8, 'mothers_day', 'mothers_day'],
            ['may', 15, 25, 'spring_clearance', 'spring_clearance'],
            ['june', 16, 1, 'summer_kickoff', 'summer_kickoff'],
            ['june', 17, 15, 'fathers_day', 'fathers_day'],
            ['june', 18, 21, 'summer_solstice', 'summer_solstice'],
            ['july', 19, 4, 'summer_sales', 'summer_sales'],
            ['july', 20, 14, 'bastille', 'bastille'],
            ['july', 21, 30, 'mid_summer', 'mid_summer'],
            ['august', 22, 15, 'summer_promo', 'summer_promo'],
            ['august', 23, 20, 'back_to_school', 'back_to_school'],
            ['august', 24, 31, 'end_summer', 'end_summer'],
            ['september', 25, 1, 'fall_launch', 'fall_launch'],
            ['september', 26, 15, 'mid_autumn', 'mid_autumn'],
            ['september', 27, 22, 'autumn_equinox', 'autumn_equinox'],
            ['october', 28, 1, 'halloween_prep', 'halloween_prep'],
            ['october', 29, 31, 'halloween', 'halloween'],
            ['october', 30, 20, 'fall_sales', 'fall_sales'],
            ['november', 31, 1, 'black_friday_prep', 'black_friday_prep'],
            ['november', 32, 28, 'black_friday', 'black_friday'],
            ['november', 33, 30, 'cyber_monday', 'cyber_monday'],
            ['december', 34, 6, 'st_nicholas', 'st_nicholas'],
            ['december', 35, 24, 'christmas_eve', 'christmas_eve'],
            ['december', 36, 31, 'new_year_eve', 'new_year_eve'],
        ];
    }
}
