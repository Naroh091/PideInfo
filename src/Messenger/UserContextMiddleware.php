<?php

namespace App\Messenger;

use App\Messenger\Stamp\UserContextStamp;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * On dispatch, stamps the envelope with the current security user so the consumer
 * (and any tracing middleware) can attribute the message to that user. Skipped on
 * the consumer side (presence of ReceivedStamp) and when a stamp is already set.
 */
final class UserContextMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($envelope->last(ReceivedStamp::class) === null
            && $envelope->last(UserContextStamp::class) === null
        ) {
            $user = $this->security->getUser();
            if ($user !== null) {
                $envelope = $envelope->with(new UserContextStamp($user->getUserIdentifier()));
            }
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
