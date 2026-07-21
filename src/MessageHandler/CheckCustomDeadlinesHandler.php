<?php

namespace App\MessageHandler;

use App\Message\CheckCustomDeadlinesMessage;
use App\Repository\CustomDeadlineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
final class CheckCustomDeadlinesHandler
{
    public function __construct(
        private readonly CustomDeadlineRepository $customDeadlineRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%mail_from_address%')] private readonly string $mailFromAddress,
        #[Autowire('%mail_from_name%')] private readonly string $mailFromName,
    ) {
    }

    public function __invoke(CheckCustomDeadlinesMessage $message): void
    {
        $currentHour = (int) (new \DateTimeImmutable())->format('H');

        // Process day-before notifications
        $dayBeforeDeadlines = $this->customDeadlineRepository->findPendingDayBeforeNotifications($currentHour);
        $this->logger->info('Checking day-before custom deadlines', [
            'count' => count($dayBeforeDeadlines),
            'currentHour' => $currentHour,
        ]);

        foreach ($dayBeforeDeadlines as $deadline) {
            $this->sendNotification($deadline, true);
        }

        // Process same-day notifications
        $sameDayDeadlines = $this->customDeadlineRepository->findPendingSameDayNotifications($currentHour);
        $this->logger->info('Checking same-day custom deadlines', [
            'count' => count($sameDayDeadlines),
            'currentHour' => $currentHour,
        ]);

        foreach ($sameDayDeadlines as $deadline) {
            $this->sendNotification($deadline, false);
        }
    }

    private function sendNotification($deadline, bool $isDayBefore): void
    {
        try {
            $accessRequest = $deadline->getAccessRequest();
            $user = $accessRequest->getUser();
            if ($user === null) {
                return; // anonymous draft: nobody to notify
            }

            $subjectPrefix = $isDayBefore ? 'Recordatorio (mañana): ' : 'Recordatorio (hoy): ';

            $email = (new TemplatedEmail())
                ->from(new Address($this->mailFromAddress, $this->mailFromName))
                ->to(new Address($user->getEmail(), $user->getFirstName() . ' ' . $user->getLastName()))
                ->subject($subjectPrefix . $accessRequest->getTitle())
                ->htmlTemplate('email/custom_deadline_notification.html.twig')
                ->context([
                    'user' => $user,
                    'accessRequest' => $accessRequest,
                    'deadline' => $deadline,
                    'isDayBefore' => $isDayBefore,
                ]);

            $this->mailer->send($email);

            if ($isDayBefore) {
                $deadline->setNotifiedDayBeforeAt(new \DateTimeImmutable());
            } else {
                $deadline->setNotifiedAt(new \DateTimeImmutable());
            }
            $this->entityManager->flush();

            $this->logger->info('Custom deadline notification sent', [
                'deadlineId' => (string) $deadline->getId(),
                'accessRequestId' => (string) $accessRequest->getId(),
                'userEmail' => $user->getEmail(),
                'isDayBefore' => $isDayBefore,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send custom deadline notification', [
                'deadlineId' => (string) $deadline->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
