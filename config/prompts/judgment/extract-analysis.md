Actúa como un experto en derecho contencioso-administrativo español y en transparencia. Analiza la sentencia adjunta —dictada en un recurso contra una resolución de un consejo de transparencia (normalmente el CTBG)— y extrae la información requerida.

REGLA GLOBAL (IDIOMA): Si el texto original está en catalán, gallego, euskera u otro idioma, TODA tu respuesta DEBE ESTAR EN CASTELLANO.

## REGLA NÚMERO UNO: EL FALLO ESTÁ AL FINAL, Y ES LO ÚNICO QUE DECIDE

Antes de rellenar `outcome`, `resolution_effect` y `transparency_stance`, **ve al FALLO** (o "PARTE DISPOSITIVA"), que está al FINAL del documento, tras el último fundamento de derecho. Solo el fallo decide. Léelo entero antes de concluir nada.

**LA TRAMPA MÁS PELIGROSA DE ESTE DOCUMENTO:** los ANTECEDENTES DE HECHO y los primeros fundamentos **transcriben literalmente, a veces durante decenas de páginas, la sentencia RECURRIDA y los argumentos de las partes**. Ese texto suele decir lo CONTRARIO de lo que este tribunal acaba decidiendo. No lo confundas: lo que citan los antecedentes es la resolución de OTRO órgano, no el fallo de ESTA sentencia. Si el texto que se te entrega está recortado por el centro, el fallo sigue estando al final: búscalo ahí.

Fórmulas del fallo de casación y qué significan:
- «**Declarar haber lugar** al recurso de casación» = **ESTIMATORIA** (el recurrente GANA).
- «**No haber lugar**» / «Desestimar el recurso» = **DESESTIMATORIA** (el recurrente PIERDE).
- «Anulamos la resolución administrativa recurrida» = `resolution_effect` = **anula**, digan lo que digan los antecedentes.
- «Declaramos el derecho de X a acceder a…, condenando a la Administración a facilitárselo» = **pro_acceso**, sin discusión.

## CONTEXTO PROCESAL PARA NO INVERTIR EL SENTIDO

Quien recurre suele ser la ADMINISTRACIÓN (un ministerio, un organismo público) contra una resolución del consejo de transparencia que dio la razón al ciudadano. Por eso, una sentencia que DESESTIMA ese recurso normalmente CONFIRMA la resolución y FAVORECE el acceso; y una que lo ESTIMA normalmente ANULA la resolución y PERJUDICA el acceso.

**Pero no siempre**: a menudo quien recurre es el CIUDADANO cuya reclamación fue desestimada o solo estimada en parte. Entonces todo se invierte: estimar el recurso ANULA la resolución y FAVORECE el acceso.

Identifica SIEMPRE, en el encabezamiento, **quién interpone el recurso** antes de rellenar los campos de resultado. Es el dato que cambia el signo de todo.

[summary]
Resumen directo en texto plano (máximo 450 caracteres): 1) Quién recurre y contra qué resolución. 2) Qué información estaba en juego. 3) Qué decide el tribunal y su razón principal.

[keypoints]
De 3 a 7 frases completas con los razonamientos jurídicos clave de la sentencia (interpretación de límites del art. 14, causas de inadmisión del art. 18, ponderaciones, test de daño). Evita formalidades vacías.

[doctrine]
Lista de 1 a 5 objetos {quote, basis}: `quote` es una frase CITABLE de la sentencia (literal o casi literal, la que un abogado subrayaría), `basis` es el precepto o doctrina que interpreta (ej. "art. 18.1.c Ley 19/2013 — reelaboración"). Prioriza los fundamentos jurídicos que fijan doctrina general sobre los que resuelven el caso concreto. Si la sentencia no fija doctrina citable, devuelve lista vacía: NO inventes.

[interpreted_articles]
Preceptos que la sentencia interpreta, en formato "Ley 19/2013 art. 14.1.j", "Ley 19/2013 art. 18.1.b". Solo los realmente interpretados, no todos los citados.

[ecli]
Identificador ECLI si aparece (formato ECLI:ES:XX:AAAA:NNNN). Suele estar en el encabezado, junto al número de recurso, o en el sello del CENDOJ. Null si no aparece. NUNCA lo construyas tú.

[roj]
Número ROJ si aparece (ej. "SAN 3616/2017"). Null si no aparece.

[judgment_date]
Fecha en que se dictó la sentencia, formato ISO 8601 (YYYY-MM-DD). Suele estar en el encabezado ("En Madrid, a dieciséis de octubre de dos mil diecisiete"). Null solo si de verdad no aparece.

[chamber]
Sala y sección si constan (ej. "Sala de lo Contencioso-Administrativo, Sección 7ª"). Null si no constan.

[outcome]
Sentido del fallo RESPECTO DEL RECURSO. Uno de: "estimatoria" | "estimatoria_parcial" | "desestimatoria" | "inadmision" | "archivo" | "desistimiento". Se lee del FALLO, al final de la sentencia.

[resolution_effect]
Efecto SOBRE LA RESOLUCIÓN del consejo de transparencia. Uno de: "confirma" | "anula" | "anula_parcial" | "retrotrae" | null (si el fallo no afecta a la resolución, p. ej. inadmisión por extemporaneidad). OJO: este campo NO es un espejo de outcome — depende de quién recurría.

[transparency_stance]
Lo que la sentencia significa para el DERECHO DE ACCESO del ciudadano. Uno de:
- "pro_acceso": el resultado final favorece el acceso a la información (se confirma una resolución estimatoria, se anula una denegación, se ordena entregar información).
- "contra_acceso": el resultado final restringe el acceso (se anula una resolución que ordenaba entregar información, se confirma una denegación).
- "neutro": el fallo no se pronuncia sobre el fondo del acceso (inadmisiones procesales, desistimientos, retroacciones puramente formales).

Razona este campo con la tabla mental: ¿quién recurría? + ¿qué pasó con la resolución? + ¿la resolución ordenaba entregar o denegaba? El error más caro de este análisis es confundir una desestimación del recurso de un ministerio (que es PRO acceso) con una decisión contra el acceso.

[effective_outcome]
UNA sola frase, en voz activa y en lenguaje llano, que diga **qué decide el tribunal en la práctica** para quien pedía la información. No repitas el resumen ni cites artículos: di el resultado efectivo.

Ejemplos del registro que buscamos:
- «El Tribunal Supremo reconoce el derecho de la Fundación Civio a acceder al código fuente de BOSCO y condena a la Administración a entregárselo.»
- «El tribunal confirma la denegación: el organismo no está obligado a entregar la información solicitada.»
- «El tribunal ordena al organismo retrotraer el procedimiento y volver a resolver la solicitud de forma motivada.»
- «El tribunal no entra en el fondo: inadmite el recurso por extemporáneo, así que la resolución del consejo sigue en pie.»

Si la sentencia ANULA la resolución del consejo, esta frase tiene que dejar claro **en qué dirección**: si el tribunal fue MÁS favorable al acceso que el consejo (le da al ciudadano lo que el consejo le negó) o MENOS (le quita lo que el consejo le había concedido). Es el dato que más importa.

[keywords]
De 3 a 8 palabras clave temáticas en minúsculas (ej. "contratación pública", "retribuciones", "datos personales").

[topics]
De 1 a 3 materias generales (ej. "contratación", "personal", "medio ambiente").

Devuelve EXCLUSIVAMENTE un objeto JSON con las claves: summary, keypoints, doctrine, interpreted_articles, ecli, roj, judgment_date, chamber, outcome, resolution_effect, transparency_stance, effective_outcome, keywords, topics. Sin texto fuera del JSON.
