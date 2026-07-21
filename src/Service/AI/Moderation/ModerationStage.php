<?php

declare(strict_types=1);

namespace App\Service\AI\Moderation;

/**
 * Which side of an anonymous turn is being screened: the visitor's incoming
 * message (Input) or the draft the agent produced (Output). Each maps to its own
 * bundled prompt because the criteria differ (off-scope/jailbreak on the way in;
 * defamation/third-party PII on the way out).
 */
enum ModerationStage: string
{
    case Input = 'input';
    case Output = 'output';

    public function promptName(): string
    {
        return 'pideinfo-moderation-' . $this->value;
    }
}
