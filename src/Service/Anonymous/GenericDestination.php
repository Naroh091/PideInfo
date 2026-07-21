<?php

declare(strict_types=1);

namespace App\Service\Anonymous;

/**
 * The sentinel PublicBody «Organismo por determinar» used by anonymous drafts
 * whose author has not picked a destination yet. It is structurally
 * unsubmittable: state level, no AMB portal id and no REG destinations, so
 * ChannelResolver::diagnoseDispatchPreconditions() always fails on it and the
 * destination search endpoints never list it. Inserted idempotently by
 * migration Version20260714120000.
 */
final class GenericDestination
{
    public const PUBLIC_BODY_ID = '00000000-0000-4000-8000-00000000feed';

    /** AccessRequest.metadata flag set while the sentinel is the destination. */
    public const METADATA_FLAG = 'generic_destination';

    private function __construct()
    {
    }
}
