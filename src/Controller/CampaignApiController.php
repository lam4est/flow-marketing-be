<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContactList;
use App\Entity\SchedulerEvent;
use App\Entity\SchedulerEventSubscription;
use App\Entity\User;
use App\Repository\ContactListRepository;
use App\Repository\SchedulerEventRepository;
use App\Repository\SchedulerEventSubscriptionRepository;
use App\Service\CurrentUserResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class CampaignApiController
{
    private const MONTH_NUM_TO_KEY = [
        1 => 'january',
        2 => 'february',
        3 => 'march',
        4 => 'april',
        5 => 'may',
        6 => 'june',
        7 => 'july',
        8 => 'august',
        9 => 'september',
        10 => 'october',
        11 => 'november',
        12 => 'december',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurrentUserResolver $currentUserResolver,
        private readonly SchedulerEventRepository $schedulerEventRepository,
        private readonly SchedulerEventSubscriptionRepository $subscriptionRepository,
        private readonly ContactListRepository $contactListRepository,
    ) {
    }

    #[Route('/api/campaign/scheduler-subscriptions', name: 'api_campaign_scheduler_list', methods: ['GET'])]
    public function listSubscriptions(Request $request): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $events = $this->schedulerEventRepository->findBy([], ['month' => 'ASC', 'day' => 'ASC']);

        $subsByEvent = [];
        foreach ($this->subscriptionRepository->findBy(['user' => $user]) as $sub) {
            $subsByEvent[$sub->getSchedulerEvent()->getId()] = $sub;
        }

        $byMonth = [];
        foreach ($events as $event) {
            $m = $event->getMonth();
            $byMonth[$m] ??= [];
            $byMonth[$m][] = $this->serializeEventForCalendar($event, $subsByEvent[$event->getId()] ?? null);
        }

        $calendar = [];
        ksort($byMonth);
        foreach ($byMonth as $monthNum => $items) {
            $key = self::MONTH_NUM_TO_KEY[$monthNum] ?? 'january';
            $calendar[] = [
                'month_key' => $key,
                'events' => $items,
            ];
        }

        return new JsonResponse(['items' => $calendar]);
    }

    #[Route('/api/campaign/scheduler-subscriptions', name: 'api_campaign_scheduler_put', methods: ['PUT'])]
    public function putSubscription(Request $request): JsonResponse
    {
        $user = $this->currentUserResolver->resolveUser($request);
        $data = $this->decodeJson($request);
        $eventId = isset($data['scheduler_event_id']) ? (int) $data['scheduler_event_id'] : null;
        if ($eventId === null || $eventId < 1) {
            throw new BadRequestHttpException('scheduler_event_id is required.');
        }

        $event = $this->schedulerEventRepository->find($eventId);
        if (!$event instanceof SchedulerEvent) {
            throw new NotFoundHttpException('Scheduler event not found.');
        }

        $sub = $this->subscriptionRepository->findOneBy([
            'user' => $user,
            'schedulerEvent' => $event,
        ]);

        if (!$sub instanceof SchedulerEventSubscription) {
            $sub = (new SchedulerEventSubscription())
                ->setUser($user)
                ->setSchedulerEvent($event)
                ->setChannel('sms');
            $this->em->persist($sub);
        }

        if (\array_key_exists('isActive', $data)) {
            $sub->setIsActive((bool) $data['isActive']);
        }
        if (isset($data['channel'])) {
            $sub->setChannel((string) $data['channel']);
        }
        if (\array_key_exists('templateId', $data)) {
            $tid = $data['templateId'];
            $sub->setTemplateId($tid === null || $tid === '' ? null : (string) $tid);
        }
        if (\array_key_exists('contactListId', $data)) {
            $sub->setContactList($this->resolveContactList($user, $data['contactListId']));
        }
        if (isset($data['hour'])) {
            $sub->setHour((int) $data['hour']);
        }
        if (isset($data['minute'])) {
            $sub->setMinute((int) $data['minute']);
        }
        if (isset($data['daysBefore'])) {
            $sub->setDaysBefore((int) $data['daysBefore']);
        }
        if (isset($data['costPerContact'])) {
            $sub->setCostPerContact((float) $data['costPerContact']);
        }
        if (isset($data['contactsCount'])) {
            $sub->setEstimatedNumberOfContacts((int) $data['contactsCount']);
        }

        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'subscriptionId' => $sub->getId(),
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

    private function resolveContactList(User $user, mixed $id): ?ContactList
    {
        if ($id === null || $id === '') {
            return null;
        }
        $list = $this->contactListRepository->find((int) $id);
        if (!$list instanceof ContactList || $list->getOwner()->getId() !== $user->getId()) {
            return null;
        }

        return $list;
    }

    private function serializeEventForCalendar(SchedulerEvent $event, ?SchedulerEventSubscription $sub): array
    {
        $defaults = [
            'id' => $event->getId(),
            'day' => $event->getDay(),
            'name_key' => $event->getNameKey(),
            'translation_key' => $event->getTranslationKey(),
            'isActive' => false,
            'channel' => 'sms',
            'contactListId' => null,
            'templateId' => null,
            'contactsCount' => 0,
            'hour' => 9,
            'minute' => 0,
            'daysBefore' => 0,
            'costPerContact' => 0.02,
            'subscriptionId' => null,
        ];

        if (!$sub instanceof SchedulerEventSubscription) {
            return $defaults;
        }

        $tid = $sub->getTemplateId();

        return [
            'id' => $event->getId(),
            'day' => $event->getDay(),
            'name_key' => $event->getNameKey(),
            'translation_key' => $event->getTranslationKey(),
            'isActive' => $sub->isActive(),
            'channel' => $sub->getChannel(),
            'contactListId' => $sub->getContactList()?->getId(),
            'templateId' => $tid !== null && is_numeric($tid) ? (int) $tid : $tid,
            'contactsCount' => $sub->getEstimatedNumberOfContacts(),
            'hour' => $sub->getHour() ?? 9,
            'minute' => $sub->getMinute() ?? 0,
            'daysBefore' => $sub->getDaysBefore(),
            'costPerContact' => $sub->getCostPerContact(),
            'subscriptionId' => $sub->getId(),
        ];
    }
}
