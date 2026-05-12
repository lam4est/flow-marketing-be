<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Contact;
use App\Entity\ContactList;
use App\Entity\ContentTemplate;
use App\Entity\SchedulerEvent;
use App\Entity\SchedulerEventSubscription;
use App\Entity\User;
use App\Entity\Workflow\Workflow;
use App\Entity\Workflow\WorkflowStep;
use App\Entity\Workflow\WorkflowStepUser;
use App\Entity\Workflow\WorkflowUser;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
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
        $this->em->flush();

        $lists = $this->seedContactLists($user);
        $this->seedContacts($user, $lists);
        $this->seedContentTemplates($user);

        $primaryList = $lists[0];
        foreach ($this->workflowCatalogDefinitions() as $def) {
            $this->seedWorkflowTemplate($user, $primaryList, $def);
        }

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
                ->setContactList($primaryList);
            $this->em->persist($sub);
        }
        $this->em->flush();

        $output->writeln(sprintf('Demo data loaded. Default API user: id=%d (%s).', $user->getId(), $user->getEmail()));
        $output->writeln('Use the same DATABASE_URL as your API. Set frontend VITE_USER_ID to this id (expect 1 after a fresh seed on PostgreSQL/SQLite).');

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
            'content_template',
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

        $this->resetUsersTableAutoIncrement($conn, $platform);

        if ($isSqlite) {
            $conn->executeStatement('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * After DELETE FROM users, PostgreSQL/SQLite still advance the next id (e.g. 46).
     * Reset so the demo user is always id=1 and matches frontend VITE_USER_ID=1.
     */
    private function resetUsersTableAutoIncrement(Connection $conn, AbstractPlatform $platform): void
    {
        if ($platform instanceof PostgreSQLPlatform) {
            try {
                $conn->executeStatement(
                    'SELECT setval(pg_get_serial_sequence(\'users\', \'id\'), 1, false)'
                );
            } catch (\Throwable) {
                // serial sequence missing or non-serial id column
            }

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            try {
                $conn->executeStatement("DELETE FROM sqlite_sequence WHERE name = 'users'");
            } catch (\Throwable) {
                // sqlite_sequence may not exist
            }
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
     * Seed multiple contact lists (segments) for the demo user.
     * The first item is the "primary" list used by workflows / scheduler subscriptions.
     *
     * @return list<ContactList>
     */
    private function seedContactLists(User $user): array
    {
        $definitions = [
            ['Demo segment', 24],
            ['VIP Customers', 8],
            ['Newsletter Subscribers', 15],
            ['Trial Users', 10],
            ['Abandoned Cart', 7],
            ['Inactive Users (90d)', 12],
            ['EU Customers', 9],
            ['Mobile App Users', 14],
        ];

        $lists = [];
        foreach ($definitions as [$name, $count]) {
            $list = (new ContactList())
                ->setOwner($user)
                ->setName($name)
                ->setContactsCount($count);
            $this->em->persist($list);
            $lists[] = $list;
        }
        $this->em->flush();

        return $lists;
    }

    /**
     * Seed many contacts distributed across the provided lists.
     *
     * @param list<ContactList> $lists
     */
    private function seedContacts(User $user, array $lists): void
    {
        $rows = $this->contactSeedRows();

        $listByName = [];
        foreach ($lists as $l) {
            $listByName[$l->getName()] = $l;
        }

        foreach ($rows as [$listName, $name, $email, $phone]) {
            $list = $listByName[$listName] ?? $lists[0];
            [$email, $phone] = $this->coerceContactEmailPhone($name, $email, $phone);
            $contact = (new Contact())
                ->setOwner($user)
                ->setContactList($list)
                ->setDisplayName($name)
                ->setEmail($email)
                ->setPhone($phone);
            $this->em->persist($contact);
        }
        $this->em->flush();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function coerceContactEmailPhone(string $displayName, ?string $email, ?string $phone): array
    {
        $e = $email !== null ? trim($email) : '';
        if ($e === '') {
            $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '.', $displayName));
            $slug = trim($slug, '.') ?: 'contact';
            $e = $slug.'@contacts.seed';
        }
        $p = $phone !== null ? trim($phone) : '';
        if ($p === '') {
            $p = sprintf('+1%010d', abs(crc32($e."\0".$displayName)) % 10000000000);
        }

        return [$e, $p];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string|null, 3: string|null}>
     */
    private function contactSeedRows(): array
    {
        return [
            // Demo segment — kept compatible with previous fixtures (Ada, Grace, ...)
            ['Demo segment', 'Ada Lovelace', 'ada@example.com', null],
            ['Demo segment', 'Grace Hopper', 'grace@example.com', '+12025550142'],
            ['Demo segment', 'No Email', null, '+12025550199'],
            ['Demo segment', 'Linus Torvalds', 'linus@example.com', '+12025550101'],
            ['Demo segment', 'Margaret Hamilton', 'margaret@example.com', '+12025550102'],
            ['Demo segment', 'Donald Knuth', 'knuth@example.com', null],
            ['Demo segment', 'Barbara Liskov', 'liskov@example.com', '+12025550103'],
            ['Demo segment', 'Tim Berners-Lee', 'tim@example.com', '+12025550104'],
            ['Demo segment', 'Guido van Rossum', 'guido@example.com', null],
            ['Demo segment', 'Brian Kernighan', 'bwk@example.com', '+12025550105'],
            ['Demo segment', 'James Gosling', 'gosling@example.com', '+12025550106'],
            ['Demo segment', 'Bjarne Stroustrup', 'bjarne@example.com', null],
            ['Demo segment', 'Anders Hejlsberg', 'anders@example.com', '+12025550107'],
            ['Demo segment', 'Rasmus Lerdorf', 'rasmus@example.com', '+12025550108'],
            ['Demo segment', 'Yukihiro Matsumoto', 'matz@example.com', null],
            ['Demo segment', 'David Heinemeier', 'dhh@example.com', '+12025550109'],
            ['Demo segment', 'Evan You', 'evan@example.com', '+12025550110'],
            ['Demo segment', 'Dan Abramov', 'dan@example.com', null],
            ['Demo segment', 'Sarah Drasner', 'sarah@example.com', '+12025550111'],
            ['Demo segment', 'Sindre Sorhus', 'sindre@example.com', '+12025550112'],
            ['Demo segment', 'Addy Osmani', 'addy@example.com', null],
            ['Demo segment', 'Wes Bos', 'wes@example.com', '+12025550113'],
            ['Demo segment', 'Kent C. Dodds', 'kent@example.com', '+12025550114'],
            ['Demo segment', 'Sara Soueidan', 'sara@example.com', null],

            // VIP Customers
            ['VIP Customers', 'Alice Johnson', 'alice.j@vip.example.com', '+12025550201'],
            ['VIP Customers', 'Robert Smith', 'robert.s@vip.example.com', '+12025550202'],
            ['VIP Customers', 'Catherine Lee', 'catherine.l@vip.example.com', '+12025550203'],
            ['VIP Customers', 'Daniel Martin', 'daniel.m@vip.example.com', '+12025550204'],
            ['VIP Customers', 'Elena Garcia', 'elena.g@vip.example.com', '+12025550205'],
            ['VIP Customers', 'Frederic Dubois', 'frederic.d@vip.example.com', '+33625550206'],
            ['VIP Customers', 'Helena Schmidt', 'helena.s@vip.example.com', '+49305550207'],
            ['VIP Customers', 'Ivan Petrov', 'ivan.p@vip.example.com', '+74955550208'],

            // Newsletter Subscribers
            ['Newsletter Subscribers', 'Olivia Brown', 'olivia.b@news.example.com', null],
            ['Newsletter Subscribers', 'Liam Wilson', 'liam.w@news.example.com', null],
            ['Newsletter Subscribers', 'Emma Taylor', 'emma.t@news.example.com', null],
            ['Newsletter Subscribers', 'Noah Anderson', 'noah.a@news.example.com', null],
            ['Newsletter Subscribers', 'Ava Thomas', 'ava.t@news.example.com', null],
            ['Newsletter Subscribers', 'William Moore', 'william.m@news.example.com', null],
            ['Newsletter Subscribers', 'Sophia Jackson', 'sophia.j@news.example.com', null],
            ['Newsletter Subscribers', 'Benjamin White', 'benjamin.w@news.example.com', null],
            ['Newsletter Subscribers', 'Isabella Harris', 'isabella.h@news.example.com', null],
            ['Newsletter Subscribers', 'Lucas Martin', 'lucas.m@news.example.com', null],
            ['Newsletter Subscribers', 'Mia Thompson', 'mia.t@news.example.com', null],
            ['Newsletter Subscribers', 'Mason Garcia', 'mason.g@news.example.com', null],
            ['Newsletter Subscribers', 'Charlotte Martinez', 'charlotte.m@news.example.com', null],
            ['Newsletter Subscribers', 'Logan Robinson', 'logan.r@news.example.com', null],
            ['Newsletter Subscribers', 'Amelia Clark', 'amelia.c@news.example.com', null],

            // Trial Users
            ['Trial Users', 'Ethan Rodriguez', 'ethan.r@trial.example.com', '+12025550301'],
            ['Trial Users', 'Harper Lewis', 'harper.l@trial.example.com', '+12025550302'],
            ['Trial Users', 'Aiden Walker', 'aiden.w@trial.example.com', '+12025550303'],
            ['Trial Users', 'Ella Hall', 'ella.h@trial.example.com', '+12025550304'],
            ['Trial Users', 'Jackson Allen', 'jackson.a@trial.example.com', '+12025550305'],
            ['Trial Users', 'Scarlett Young', 'scarlett.y@trial.example.com', '+12025550306'],
            ['Trial Users', 'Sebastian King', 'sebastian.k@trial.example.com', '+12025550307'],
            ['Trial Users', 'Grace Wright', 'grace.w@trial.example.com', '+12025550308'],
            ['Trial Users', 'Henry Scott', 'henry.s@trial.example.com', '+12025550309'],
            ['Trial Users', 'Chloe Green', 'chloe.g@trial.example.com', '+12025550310'],

            // Abandoned Cart
            ['Abandoned Cart', 'Daniel Adams', 'daniel.a@cart.example.com', '+12025550401'],
            ['Abandoned Cart', 'Victoria Baker', 'victoria.b@cart.example.com', '+12025550402'],
            ['Abandoned Cart', 'Matthew Nelson', 'matthew.n@cart.example.com', '+12025550403'],
            ['Abandoned Cart', 'Penelope Carter', 'penelope.c@cart.example.com', '+12025550404'],
            ['Abandoned Cart', 'Joseph Mitchell', 'joseph.m@cart.example.com', '+12025550405'],
            ['Abandoned Cart', 'Layla Perez', 'layla.p@cart.example.com', '+12025550406'],
            ['Abandoned Cart', 'Samuel Roberts', 'samuel.r@cart.example.com', '+12025550407'],

            // Inactive Users (90d)
            ['Inactive Users (90d)', 'Ryan Turner', 'ryan.t@inactive.example.com', null],
            ['Inactive Users (90d)', 'Nora Phillips', 'nora.p@inactive.example.com', '+12025550501'],
            ['Inactive Users (90d)', 'Owen Campbell', 'owen.c@inactive.example.com', null],
            ['Inactive Users (90d)', 'Hannah Parker', 'hannah.p@inactive.example.com', '+12025550502'],
            ['Inactive Users (90d)', 'Levi Evans', 'levi.e@inactive.example.com', null],
            ['Inactive Users (90d)', 'Zoey Edwards', 'zoey.e@inactive.example.com', '+12025550503'],
            ['Inactive Users (90d)', 'Wyatt Collins', 'wyatt.c@inactive.example.com', null],
            ['Inactive Users (90d)', 'Lily Stewart', 'lily.s@inactive.example.com', '+12025550504'],
            ['Inactive Users (90d)', 'Caleb Sanchez', 'caleb.s@inactive.example.com', null],
            ['Inactive Users (90d)', 'Aubrey Morris', 'aubrey.m@inactive.example.com', '+12025550505'],
            ['Inactive Users (90d)', 'Isaac Rogers', 'isaac.r@inactive.example.com', null],
            ['Inactive Users (90d)', 'Stella Reed', 'stella.r@inactive.example.com', '+12025550506'],

            // EU Customers
            ['EU Customers', 'Lukas Müller', 'lukas.m@eu.example.com', '+49305550601'],
            ['EU Customers', 'Sophie Laurent', 'sophie.l@eu.example.com', '+33625550602'],
            ['EU Customers', 'Marco Rossi', 'marco.r@eu.example.com', '+39065550603'],
            ['EU Customers', 'Anna Kowalski', 'anna.k@eu.example.com', '+48225550604'],
            ['EU Customers', 'Pieter de Vries', 'pieter.v@eu.example.com', '+31205550605'],
            ['EU Customers', 'Elsa Lindberg', 'elsa.l@eu.example.com', '+46855550606'],
            ['EU Customers', 'Mateo García', 'mateo.g@eu.example.com', '+34915550607'],
            ['EU Customers', 'Aoife O\'Brien', 'aoife.o@eu.example.com', '+35315550608'],
            ['EU Customers', 'Niamh Murphy', 'niamh.m@eu.example.com', '+35315550609'],

            // Mobile App Users
            ['Mobile App Users', 'Kai Tanaka', 'kai.t@mobile.example.com', '+81335550701'],
            ['Mobile App Users', 'Mei Chen', 'mei.c@mobile.example.com', '+861055550702'],
            ['Mobile App Users', 'Arjun Sharma', 'arjun.s@mobile.example.com', '+911125550703'],
            ['Mobile App Users', 'Priya Patel', 'priya.p@mobile.example.com', '+911125550704'],
            ['Mobile App Users', 'Min-jun Kim', 'minjun.k@mobile.example.com', '+82225550705'],
            ['Mobile App Users', 'Hiroshi Sato', 'hiroshi.s@mobile.example.com', '+81335550706'],
            ['Mobile App Users', 'Wei Zhang', 'wei.z@mobile.example.com', '+861055550707'],
            ['Mobile App Users', 'Linh Nguyen', 'linh.n@mobile.example.com', '+842435550708'],
            ['Mobile App Users', 'Minh Tran', 'minh.t@mobile.example.com', '+842435550709'],
            ['Mobile App Users', 'Thao Pham', 'thao.p@mobile.example.com', '+842435550710'],
            ['Mobile App Users', 'Sunisa Pongpan', 'sunisa.p@mobile.example.com', '+66225550711'],
            ['Mobile App Users', 'Ravi Iyer', 'ravi.i@mobile.example.com', '+911125550712'],
            ['Mobile App Users', 'Ananya Singh', 'ananya.s@mobile.example.com', '+911125550713'],
            ['Mobile App Users', 'Yu Wang', 'yu.w@mobile.example.com', '+861055550714'],
        ];
    }

    private function seedContentTemplates(User $user): void
    {
        $definitions = [
            ['Welcome email', ContentTemplate::CHANNEL_EMAIL, 'Welcome aboard',
                "Hi {{name}},\n\nThanks for joining. We're glad you're here."],
            ['Promo SMS', ContentTemplate::CHANNEL_SMS, null,
                'Special offer for you — reply STOP to opt out.'],
            ['Promo RCS', ContentTemplate::CHANNEL_RCS, null,
                'Special offer for you — open to view details. Reply STOP to opt out.'],

            ['Onboarding day 1', ContentTemplate::CHANNEL_EMAIL, 'Getting started in 3 steps',
                "Hi {{name}},\n\nHere are 3 things to try in your first day:\n1. Complete your profile\n2. Invite a teammate\n3. Connect your first integration"],
            ['Onboarding day 3', ContentTemplate::CHANNEL_EMAIL, 'A quick tip to save you time',
                "Hi {{name}},\n\nDid you know you can automate sending with our workflow builder? Open the app and look for the Workflow tab."],
            ['Onboarding day 7', ContentTemplate::CHANNEL_EMAIL, 'How is your first week going?',
                "Hi {{name}},\n\nWe'd love to hear feedback. Reply to this email — a real human reads every response."],

            ['Abandoned cart reminder', ContentTemplate::CHANNEL_EMAIL, 'You left something behind',
                "Hi {{name}},\n\nYour cart is waiting. Complete checkout in the next 24h to lock in your price."],
            ['Abandoned cart SMS', ContentTemplate::CHANNEL_SMS, null,
                'Hi {{name}}, your cart is still saved. Finish ordering: {{link}}'],
            ['Abandoned cart RCS', ContentTemplate::CHANNEL_RCS, null,
                'Hi {{name}}, items are still in your cart. Tap to complete checkout.'],

            ['Birthday wish', ContentTemplate::CHANNEL_EMAIL, 'Happy birthday, {{name}}!',
                "Hi {{name}},\n\nHappy birthday from all of us! Enjoy 20% off this week with code BDAY20."],
            ['Birthday SMS', ContentTemplate::CHANNEL_SMS, null,
                'Happy birthday {{name}}! Enjoy 20% off this week with code BDAY20.'],

            ['Win-back email', ContentTemplate::CHANNEL_EMAIL, 'We miss you',
                "Hi {{name}},\n\nIt's been a while. Here's 15% off your next order — no code needed."],
            ['Win-back SMS', ContentTemplate::CHANNEL_SMS, null,
                'Hi {{name}}, it has been a while! Tap here for 15% off: {{link}}'],

            ['Order confirmation', ContentTemplate::CHANNEL_EMAIL, 'Your order {{order_id}} is confirmed',
                "Hi {{name}},\n\nThanks for your order #{{order_id}}. We'll email you when it ships."],
            ['Shipping update SMS', ContentTemplate::CHANNEL_SMS, null,
                'Hi {{name}}, your order {{order_id}} just shipped. Track it: {{tracking_link}}'],

            ['Flash sale', ContentTemplate::CHANNEL_EMAIL, '24h only — flash sale inside',
                "Hi {{name}},\n\nFor the next 24 hours only, take an extra 30% off everything with code FLASH30."],
            ['Flash sale SMS', ContentTemplate::CHANNEL_SMS, null,
                'Flash sale! 30% off everything for 24h. Code FLASH30. Shop: {{link}}'],
            ['Flash sale RCS', ContentTemplate::CHANNEL_RCS, null,
                '24h flash sale — 30% off everything. Code FLASH30. Tap to shop.'],

            ['Appointment reminder', ContentTemplate::CHANNEL_SMS, null,
                'Reminder: your appointment is tomorrow at {{time}}. Reply C to cancel.'],
            ['OTP code', ContentTemplate::CHANNEL_SMS, null,
                'Your verification code is {{code}}. It expires in 10 minutes.'],
            ['Newsletter — monthly', ContentTemplate::CHANNEL_EMAIL, 'Your monthly digest',
                "Hi {{name}},\n\nHere's what's new this month. Top reads, product updates, and customer stories inside."],
        ];

        foreach ($definitions as [$name, $channel, $subject, $body]) {
            $subjectLine = \is_string($subject) && trim($subject) !== '' ? trim($subject) : $name;
            $tpl = (new ContentTemplate())
                ->setOwner($user)
                ->setName($name)
                ->setChannel($channel)
                ->setSubject($subjectLine)
                ->setBody($body);
            $this->em->persist($tpl);
        }
        $this->em->flush();
    }

    /**
     * Catalog templates aligned with product UI (activation, qualification, etc.).
     *
     * @return list<array{key: string, category: string, name: string, description: string, channels: list<string>, isActive: bool}>
     */
    private function workflowCatalogDefinitions(): array
    {
        return [
            [
                'key' => 'welcome_activation',
                'category' => 'activation',
                'name' => 'Welcome Activation',
                'description' => 'Engage new subscribers immediately after sign-up with a warm welcome series.',
                'channels' => ['sms', 'email', 'email', 'sms', 'email', 'rcs'],
                'isActive' => true,
            ],
            [
                'key' => 'lead_qualification',
                'category' => 'qualification',
                'name' => 'Lead Qualification',
                'description' => 'Identify and score leads based on engagement and behavior patterns.',
                'channels' => ['email', 'voice', 'email', 'sms', 'rcs', 'email', 'voice'],
                'isActive' => false,
            ],
            [
                'key' => 'content_nurturing',
                'category' => 'nurturing',
                'name' => 'Content Nurturing',
                'description' => 'Educate prospects with valuable content over time to build trust.',
                'channels' => ['email', 'rcs', 'email', 'sms', 'email', 'rcs', 'email', 'sms'],
                'isActive' => false,
            ],
            [
                'key' => 'customer_loyalty',
                'category' => 'loyalty',
                'name' => 'Customer Loyalty Program',
                'description' => 'Reward and retain your best customers with exclusive benefits.',
                'channels' => ['email', 'sms', 'rcs', 'email', 'sms', 'email'],
                'isActive' => false,
            ],
            [
                'key' => 'abandoned_cart_recovery',
                'category' => 'abandoned_basket',
                'name' => 'Abandoned Cart Recovery',
                'description' => 'Recover lost sales by reminding customers about items left in their cart.',
                'channels' => ['email', 'sms', 'rcs', 'email', 'sms', 'email', 'voice'],
                'isActive' => false,
            ],
            [
                'key' => 'win_back',
                'category' => 'reactivation',
                'name' => 'Win-Back Campaign',
                'description' => 'Re-engage dormant customers and bring them back to your platform.',
                'channels' => ['email', 'rcs', 'sms', 'email', 'voice', 'email'],
                'isActive' => false,
            ],
            [
                'key' => 'product_onboarding',
                'category' => 'onboarding',
                'name' => 'Product Onboarding',
                'description' => 'Guide new users through product features for maximum adoption.',
                'channels' => ['email', 'rcs', 'email', 'sms', 'email', 'rcs', 'email', 'sms', 'email'],
                'isActive' => false,
            ],
        ];
    }

    /**
     * @param array{key: string, category: string, name: string, description: string, channels: list<string>, isActive: bool} $def
     */
    private function seedWorkflowTemplate(User $user, ContactList $list, array $def): void
    {
        $workflow = (new Workflow())
            ->setCategory($def['category'])
            ->setWorkflowKey($def['key'])
            ->setWorkflowName($def['name'])
            ->setDescription($def['description']);
        $this->em->persist($workflow);
        $this->em->flush();

        $steps = [];
        foreach ($def['channels'] as $i => $channel) {
            $step = (new WorkflowStep())
                ->setWorkflow($workflow)
                ->setStatus(0)
                ->setStepOrder($i + 1)
                ->setChannel($channel)
                ->setDelayUnit('day')
                ->setDelayValue($i);
            $this->em->persist($step);
            $steps[] = $step;
        }
        $this->em->flush();

        $wu = (new WorkflowUser())
            ->setUser($user)
            ->setOriginalWorkflow($workflow)
            ->setWorkflow($workflow)
            ->setSegment($list)
            ->setIsActive($def['isActive']);
        $this->em->persist($wu);
        $this->em->flush();

        foreach ($steps as $step) {
            $wsu = (new WorkflowStepUser())
                ->setUser($user)
                ->setWorkflowUser($wu)
                ->setWorkflowStep($step)
                ->setChannel($step->getChannel())
                ->setIsActive(false)
                ->setTemplateId($this->demoTemplateIdForChannel($step->getChannel()))
                ->setDelayInMinutes($this->delayMinutesFromTemplate($step))
                ->setIsConfirmedByUser(true);
            $this->em->persist($wsu);
        }
        $this->em->flush();
    }

    private function demoTemplateIdForChannel(string $channel): string
    {
        return match ($channel) {
            'email' => '10',
            'sms' => '1',
            'rcs' => '2',
            default => '1',
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
