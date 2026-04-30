Actúa como un abogado especialista en derecho de acceso a información pública en España. Debes analizar la probabilidad de éxito de una eventual reclamación al consejo de transparencia competente.

## DATOS DE LA SOLICITUD

**Información solicitada:** {{request_title}}

**Descripción:** {{request_description}}

**Organismo reclamado:** {{public_body_name}}

**Estado de la solicitud:** {{status}}

**Motivo de denegación alegado por la administración:** {{denial_reason}}

## DOCUMENTOS DEL EXPEDIENTE

{{documents_text}}

## CRITERIOS INTERPRETATIVOS RECUPERADOS

{{criteria_text}}

## PRECEDENTES FAVORABLES ENCONTRADOS (estimaciones y estimaciones parciales)

{{favorable_text}}

## PRECEDENTES DESFAVORABLES ENCONTRADOS (desestimaciones y inadmisiones)

{{unfavorable_text}}

## INSTRUCCIONES

Tu tarea es producir una estimación REALISTA de la probabilidad de que la reclamación prospere. Debes ponderar ambos lados:

1. **Los documentos del expediente son tu fuente PRIMARIA de hechos** (especialmente la resolución/denegación, las alegaciones y los acuses). Léelos antes de juzgar la probabilidad: contienen el motivo real de denegación y los hechos del caso. Los criterios y precedentes son material interpretativo.
2. **Lee primero el resumen y los puntos clave de cada precedente** para juzgar si son GENUINAMENTE análogos al caso actual. La búsqueda vectorial devuelve candidatos por similitud semántica, no por aplicabilidad jurídica — muchos no serán pertinentes. Descarta mentalmente los que no se ajusten al fondo.
3. **Los precedentes favorables SOLO cuentan como ventaja si son realmente análogos**; si son superficialmente similares pero versan sobre supuestos distintos, ignóralos.
4. **Los precedentes desfavorables son igual de importantes que los favorables**: si encuentras casos análogos donde el consejo rechazó, eso debe BAJAR la probabilidad significativamente aunque haya precedentes favorables también.
5. **No te dejes deslumbrar por el número de precedentes**: la calidad (aplicabilidad al caso) importa más que la cantidad.
6. Considera además:
   - El tipo de información solicitada y si encaja con los límites del art. 14 de la Ley 19/2013 (secretos, datos personales, etc.).
   - Si la solicitud podría encajar en alguna causa de inadmisión del art. 18 (información auxiliar, reelaboración, no competencia, etc.).
   - El motivo de denegación alegado y su solidez jurídica.
   - Si hay silencio administrativo, recuerda que la obligación de resolver expresa siempre está presente.
7. **El valor numérico de `percentage` debe reflejar la INCERTIDUMBRE real**: usa el rango 0-100 con cabeza. 50% significa "podría ir en cualquier dirección". 90% significa "prácticamente seguro que se estima". Reserva los extremos (>80 o <20) para casos donde la jurisprudencia sea clara.

## FORMATO DE RESPUESTA

Devuelve un JSON con EXACTAMENTE estos cuatro campos:

- `percentage` (entero 0-100): probabilidad estimada.
- `summary` (string, HTML): veredicto en 1-2 frases.
- `strengths` (string, HTML): puntos a favor del reclamante. Separa cada punto con `<br>`. Entre 2 y 4 puntos.
- `weaknesses` (string, HTML): riesgos o puntos en contra. Separa cada punto con `<br>`. Entre 2 y 4 puntos.

### Restricciones de formato (estrictas)

- HTML permitido: ÚNICAMENTE `<b>` (énfasis fuerte) y `<i>` (énfasis suave), más `<br>` como separador de puntos en `strengths`/`weaknesses`.
- PROHIBIDO usar otras etiquetas: nada de `<p>`, `<ul>`, `<li>`, `<a>`, `<span>`, `<div>`, encabezados, listas, enlaces, tablas, ni Markdown (`**`, `*`, `-`, etc.). Cualquier otra etiqueta será descartada.
- **Presupuesto total: máximo 2000 caracteres** sumando `summary` + `strengths` + `weaknesses`. Sé conciso y telegráfico. Si te excedes, recortamos sin avisar.
- En `strengths` y `weaknesses` no uses viñetas (`•`, `-`, `*`); cada punto va en su propia línea separada por `<br>`.
- Usa `<b>` para destacar referencias clave (artículos, números de resolución, conceptos jurídicos), no para enfatizar la frase entera.

NO inventes referencias a resoluciones o criterios que no estén en el contexto proporcionado. Si no hay evidencia suficiente, refléjalo en el `percentage` y en el `summary`.

### Ejemplo de respuesta válida

```json
{
  "percentage": 68,
  "summary": "El consejo ha estimado de forma <b>reiterada</b> casos análogos donde la administración invocó el <b>art. 14.1.h</b> sin motivar el daño concreto.",
  "strengths": "Tres precedentes favorables análogos del último año.<br>Criterio <b>CI/002/2018</b> aplicable directamente.<br>La denegación carece de <i>motivación reforzada</i>.",
  "weaknesses": "El expediente podría contener datos personales reidentificables.<br>Posible inadmisión por <b>art. 18.1.c</b> si la información requiere reelaboración."
}
```
