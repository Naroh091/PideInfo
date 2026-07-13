<?php

declare(strict_types=1);

namespace App\Service\Submission;

use App\Entity\AccessRequest;

/**
 * Which capacity applies to THIS request.
 *
 * Precedence: what the request says → what the profile says → citizen. A user who is normally
 * a concejal may perfectly well file a request as a private citizen, and the per-request
 * override is how they say so.
 */
final class RequesterCapacityResolver
{
    public const METADATA_KEY = 'requester_capacity';
    public const METADATA_DETAIL_KEY = 'requester_capacity_detail';

    /**
     * @return array{capacity: string, detail: string|null, source: 'request'|'profile'|'default'}
     */
    public function for(AccessRequest $request): array
    {
        $fromRequest = $request->getMetadataValue(self::METADATA_KEY);

        if (is_string($fromRequest) && RequesterCapacity::isValid($fromRequest)) {
            $detail = $request->getMetadataValue(self::METADATA_DETAIL_KEY);

            return [
                'capacity' => $fromRequest,
                'detail' => is_string($detail) && trim($detail) !== '' ? trim($detail) : null,
                'source' => 'request',
            ];
        }

        $user = $request->getUser();
        $fromProfile = $user?->getRequesterCapacity();

        // A corrupt or stale value must degrade to "citizen", never blow up a drafting session.
        if (is_string($fromProfile) && RequesterCapacity::isValid($fromProfile)) {
            return [
                'capacity' => $fromProfile,
                'detail' => $user?->getRequesterCapacityDetail(),
                'source' => 'profile',
            ];
        }

        return [
            'capacity' => RequesterCapacity::DEFAULT,
            'detail' => null,
            'source' => 'default',
        ];
    }
}
