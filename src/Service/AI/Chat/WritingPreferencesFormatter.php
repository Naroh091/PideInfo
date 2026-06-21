<?php

declare(strict_types=1);

namespace App\Service\AI\Chat;

/**
 * Single source of truth for rendering a user's writing preferences
 * (tone / salutation / closing / notes, plus the assistant-learned style) as the
 * Markdown block injected into the drafting-assistant system prompt.
 *
 * Shared by both flow composers ({@see Composer\RequestPromptComposer},
 * {@see Composer\ComplaintPromptComposer}) and the `get_user_preferences` agent tool,
 * so the four labels and the learned-style section never drift between them.
 */
final class WritingPreferencesFormatter
{
    private const LABELS = [
        'tone'       => 'Tono',
        'salutation' => 'Saludo/encabezado',
        'closing'    => 'Cierre',
        'notes'      => 'Instrucciones adicionales',
    ];

    /**
     * @param array{tone?: string, salutation?: string, closing?: string, notes?: string, learned?: list<string>} $prefs
     * @return string empty string when there is nothing worth injecting
     */
    public static function format(array $prefs): string
    {
        $lines = [];
        foreach (self::LABELS as $key => $label) {
            if (!empty($prefs[$key])) {
                $lines[] = "- **{$label}:** {$prefs[$key]}";
            }
        }

        $learned = array_values(array_filter(
            (array) ($prefs['learned'] ?? []),
            static fn ($p): bool => is_string($p) && trim($p) !== '',
        ));

        if ($lines === [] && $learned === []) {
            return '';
        }

        $block = "## Preferencias de redacción del usuario\n\nAplica estas preferencias al redactar:";

        if ($lines !== []) {
            $block .= "\n\n" . implode("\n", $lines);
        }

        if ($learned !== []) {
            $learnedLines = array_map(static fn (string $p): string => "- {$p}", $learned);
            $block .= "\n\n### Estilo aprendido (aplícalo salvo que el usuario indique lo contrario)\n\n"
                . implode("\n", $learnedLines);
        }

        return $block;
    }

    /** True when there is any preference (manual or learned) worth injecting. */
    public static function hasAny(array $prefs): bool
    {
        return self::format($prefs) !== '';
    }
}
