<?php

declare(strict_types=1);

namespace App\Service\AI\Chat;

use App\Entity\AccessRequest;

/**
 * Applies a model-produced request draft onto an AccessRequest, and snapshots
 * the current draft for "Ver cambios" diffs. Extracted from AssistantChatController
 * so the web SSE flow and the MCP draft tool normalize identically.
 *
 * Channel shape: REG drafts carry `expone`/`solicita` (each ≤4000 chars, joined
 * into the description); other channels carry a single `body_text` (≤3000).
 * Does NOT flush — the caller owns the transaction boundary.
 */
final class RequestDraftApplier
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(AccessRequest $ar): array
    {
        if ($ar->getRegDestination() !== null) {
            return [
                'title' => (string) $ar->getTitle(),
                'expone' => (string) ($ar->getExpone() ?? ''),
                'solicita' => (string) ($ar->getSolicita() ?? ''),
            ];
        }

        return [
            'title' => (string) $ar->getTitle(),
            'body_text' => (string) ($ar->getDescription() ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $draft
     *
     * @return array<string, mixed> the normalized draft as written
     */
    public function apply(AccessRequest $ar, array $draft): array
    {
        $title = mb_substr(trim((string) ($draft['title'] ?? '')), 0, 255);
        $ar->setTitle($title);

        if ($ar->getRegDestination() !== null) {
            $expone = mb_substr($this->plain((string) ($draft['expone'] ?? '')), 0, 4000);
            $solicita = mb_substr($this->plain((string) ($draft['solicita'] ?? '')), 0, 4000);
            $ar->setExpone($expone);
            $ar->setSolicita($solicita);
            $ar->setDescription(mb_substr(
                trim("EXPONE:\n" . $expone . "\n\nSOLICITA:\n" . $solicita),
                0,
                8500,
            ));

            return ['title' => $title, 'expone' => $expone, 'solicita' => $solicita];
        }

        $body = mb_substr($this->plain((string) ($draft['body_text'] ?? '')), 0, 3000);
        $ar->setDescription($body);

        return ['title' => $title, 'body_text' => $body];
    }

    private function plain(string $text): string
    {
        return trim(strip_tags($text));
    }
}
