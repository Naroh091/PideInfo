<?php

namespace App\Messenger;

use App\Messenger\Stamp\UserContextStamp;
use App\Observability\AttributeKeys;
use App\Observability\Tracer;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * Wraps every consumed message in a Langfuse trace. Producer-side dispatch is
 * skipped (no `ReceivedStamp`), so spans only appear once per actual handler run.
 */
final class TracingMiddleware implements MiddlewareInterface
{
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
