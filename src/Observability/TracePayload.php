<?php

declare(strict_types=1);

namespace App\Observability;

/**
 * Serialización de payloads grandes para las trazas.
 *
 * Existe por dos motivos:
 *
 * 1. **Los adjuntos no textuales matan una traza.** Un turno multipart lleva
 *    imágenes en base64; volcarlas tal cual multiplica el tamaño del evento sin
 *    aportar nada legible.
 * 2. **El truncado tiene que ser VISIBLE.** Los exportadores OTLP y la ingesta
 *    de Langfuse descartan atributos desmesurados, y lo hacen en silencio: te
 *    quedas sin el dato y sin saberlo. Aquí se corta con un tope generoso y se
 *    deja constancia de cuánto se ha omitido.
 */
final class TracePayload
{
    /**
     * Tope por atributo. Generoso a propósito: el contenido caro de estas trazas
     * (resoluciones recuperadas, documentos del expediente) es justo lo que hace
     * falta para reconstruir por qué el modelo escribió lo que escribió.
     */
    public const MAX_CHARS = 200_000;

    public static function text(string $value, int $max = self::MAX_CHARS): string
    {
        $length = mb_strlen($value);
        if ($length <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max) . sprintf("\n\n…[truncado: %d caracteres omitidos]", $length - $max);
    }

    public static function encode(mixed $value, int $max = self::MAX_CHARS): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return self::text($json === false ? '[no serializable]' : $json, $max);
    }

    /**
     * Aplana los turnos multipart (texto + adjuntos) a solo texto. El resto de
     * turnos pasa intacto, incluidos los `tool_calls` en formato OpenAI.
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    public static function sanitizeMessages(array $messages): array
    {
        return array_map(static function (array $message): array {
            if (!is_array($message['content'] ?? null)) {
                return $message;
            }

            $texts = [];
            foreach ($message['content'] as $part) {
                if (($part['type'] ?? '') === 'text' && is_string($part['text'] ?? null)) {
                    $texts[] = $part['text'];
                } else {
                    $texts[] = '[adjunto no textual omitido]';
                }
            }

            return ['role' => $message['role'], 'content' => implode("\n\n", $texts)];
        }, $messages);
    }
}
