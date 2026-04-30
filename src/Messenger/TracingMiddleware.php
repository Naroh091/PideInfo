<?php

namespace App\Messenger;

use App\Message\CheckCustomDeadlinesMessage;
use App\Messenger\Stamp\UserContextStamp;
use App\Observability\AttributeKeys;
use App\Observability\Tracer;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * Wraps every consumed message in a Langfuse trace. Producer-side dispatch is
 * skipped (no `ReceivedStamp`), so spans only appear once per actual handler run.
 *
 * Routine maintenance jobs that don't talk to an LLM (deadline checks, expired-
 * request sweeps) are excluded — they'd flood Langfuse with noise traces that
 * have no generations under them.
 */
final class TracingMiddleware implements MiddlewareInterface
{
    /**
     * Message classes whose execution we deliberately don't trace. These are
     * scheduled chores with no AI work inside; tracing them only adds noise to
     * the Langfuse trace list.
     *
     * @var array<int, class-string>
     */
    private const EXCLUDED_MESSAGES = [
        CheckCustomDeadlinesMessage::class,
        SendEmailMessage::class,
    ];

    public function __construct(
        private readonly Tracer $tracer,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($envelope->last(ReceivedStamp::class) === null) {
            return $stack->next()->handle($envelope, $stack);
        }

        $message = $envelope->getMessage();
        $messageClass = $message::class;

        if (in_array($messageClass, self::EXCLUDED_MESSAGES, true)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $shortName = substr($messageClass, strrpos($messageClass, '\\') + 1);

        $attrs = [
            AttributeKeys::LANGFUSE_TRACE_NAME => $shortName,
            'messaging.system' => 'symfony',
            'messaging.message.type' => $messageClass,
            'messaging.operation' => 'process',
        ];

        $bus = $envelope->last(BusNameStamp::class);
        if ($bus !== null) {
            $attrs['messaging.destination'] = $bus->getBusName();
        }

        $idStamp = $envelope->last(TransportMessageIdStamp::class);
        if ($idStamp !== null) {
            $attrs['messaging.message.id'] = (string) $idStamp->getId();
        }

        $userStamp = $envelope->last(UserContextStamp::class);
        if ($userStamp !== null) {
            $attrs[AttributeKeys::LANGFUSE_USER_ID] = $userStamp->userId;
        }

        // The trace input is the message payload itself — gives instant context
        // ("which user / request was this job about?") in the Langfuse Trace UI
        // without having to drill into individual generation observations.
        $traceInput = $this->serializeMessage($message);

        return $this->tracer->traceRoot(
            name: $shortName,
            attributes: $attrs,
            fn: fn () => $stack->next()->handle($envelope, $stack),
            traceInput: $traceInput,
        );
    }

    private function serializeMessage(object $message): string
    {
        try {
            // get_object_vars only captures public/visible state, which is what we
            // want — no internal services or readonly handler dependencies.
            return json_encode(get_object_vars($message), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        } catch (\Throwable) {
            return '';
        }
    }
}
