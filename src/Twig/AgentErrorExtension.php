<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Traduce los códigos de error técnicos que reporta el agente
 * (`AgentTask.errorMessage`) a un mensaje legible en español para la UI.
 *
 * El agente reporta cadenas como `submission_uncertain:step3_firma_sin_confirmar: …`
 * o `pipeline_crashed:step2_validation: …` — útiles para depurar pero opacas
 * para el usuario. El filtro `agent_error` las convierte en una frase clara;
 * el texto crudo se sigue mostrando aparte como detalle técnico.
 */
final class AgentErrorExtension extends AbstractExtension
{
    /**
     * Conjunto curado de subcadena → mensaje legible. Se evalúa en orden y
     * gana la primera que case, así que las más específicas van primero.
     */
    private const MAP = [
        'submission_uncertain' => 'La firma se envió pero el agente no pudo confirmar el registro: es posible que la solicitud ya esté presentada en el portal. Compruébalo antes de reenviar.',
        'step3_stuck_on_firma' => 'La firma no llegó a confirmarse en el portal a tiempo. Puede que la solicitud quedara como borrador o que se registrara más tarde.',
        'step2_validation' => 'El portal rechazó los datos del formulario: algún campo no tiene el formato esperado.',
        'step2_portal_timeout' => 'El portal tardó demasiado en procesar el envío y no llegó a responder.',
        'step2_did_not_advance' => 'El portal no avanzó al paso de firma tras enviar el formulario.',
        'parent.lock' => 'El navegador del agente estaba bloqueado por otra ventana abierta. Cierra el agente y vuelve a intentarlo.',
        'Error de autenticación' => 'El agente no pudo iniciar sesión con tu certificado en el portal.',
        'Permission denied' => 'El agente no pudo acceder a un archivo de su perfil por un problema de permisos. Reinícialo y vuelve a intentarlo.',
        'SessionExpired' => 'La sesión con el portal caducó durante el envío.',
        'missing_payload' => 'Faltaban datos necesarios para el envío; la solicitud no llegó a presentarse.',
        'missing_pdf' => 'Faltaban documentos PDF obligatorios para presentar la reclamación.',
        'pdf_download_failed' => 'No se pudieron descargar los documentos necesarios para el envío.',
        'wizard_did_not_render' => 'El formulario del portal no llegó a cargarse.',
        'no_handler' => 'El agente no reconoció el tipo de tarea (error interno).',
    ];

    public function getFilters(): array
    {
        return [
            new TwigFilter('agent_error', [$this, 'humanize']),
        ];
    }

    /**
     * Devuelve una frase legible para un `errorMessage` del agente. Si nada
     * casa con el conjunto curado, cae a un mensaje genérico.
     */
    public function humanize(?string $errorMessage): string
    {
        if ($errorMessage === null || trim($errorMessage) === '') {
            return 'El envío no se completó (sin detalle del error).';
        }

        foreach (self::MAP as $needle => $human) {
            if (stripos($errorMessage, $needle) !== false) {
                return $human;
            }
        }

        return 'El envío falló por un problema técnico. Revisa el detalle técnico o vuelve a intentar el envío.';
    }
}
