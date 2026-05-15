<?php

declare(strict_types=1);

namespace App\Service\Submission;

use App\Entity\AccessRequest;
use App\Entity\RegDestination;
use App\Entity\User;

/**
 * Builds the AgentTask payload for the REG / RED SARA channel. Keeps the
 * channel-specific shape out of the controller so the dispatch flow can
 * stay readable.
 *
 * The shape mirrors what `portals/redsara_reg.py` consumes in the agent;
 * see docs/portals/reg.md for the full contract.
 */
final class RegPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(AccessRequest $accessRequest, User $user, RegDestination $destination): array
    {
        $body = $accessRequest->getRegBody();

        return [
            'access_request_id' => $accessRequest->getId()->toRfc4122(),
            'public_body_id' => $accessRequest->getPublicBody()->getId()->toRfc4122(),
            'public_body_name' => $accessRequest->getPublicBody()->getName(),
            'public_body_dir3' => $accessRequest->getPublicBody()->getDir3Code(),
            'destination' => [
                'unit_dir3' => $destination->getDir3Code(),
                'unit_name' => $destination->getName(),
                'intermediate_dir3' => $destination->getIntermediateOrganismDir3(),
                'intermediate_name' => $destination->getIntermediateOrganismName(),
            ],
            'solicitante' => [
                'first_name' => $user->getFirstName(),
                'last_name' => $user->getLastName(),
                'email' => $user->getEmail(),
                'address' => [
                    // Etiquetas tal y como aparecen en los desplegables del REG
                    // (no códigos INE) — el agente las inyecta directamente en
                    // los mat-select sin mapping intermedio.
                    'street_type' => $user->getAddressStreetType(),
                    'line' => $user->getAddressLine(),
                    'country' => $user->getAddressCountry() ?? 'ES',
                    'province' => $user->getAddressProvince(),
                    'municipality' => $user->getAddressMunicipality(),
                    'postal_code' => $user->getAddressPostalCode(),
                ],
                'phone' => $user->getContactPhone(),
            ],
            'request' => [
                'title' => $accessRequest->getTitle(),
                'expone' => $body['expone'],
                'solicita' => $body['solicita'],
                'applicable_law' => $accessRequest->getApplicableLaw()->getId()->toRfc4122(),
            ],
        ];
    }
}
