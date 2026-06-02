Analiza este documento relacionado con una solicitud de acceso a información pública en España (Ley 19/2013 o leyes autonómicas de transparencia).

Extrae la siguiente información en formato JSON:

{
    "documentType": "tipo de documento (ver REGLAS PARA documentType abajo, incluyendo 'alegaciones')",
    "referenceNumber": "número de expediente o registro (busca 'Nº de registro', 'Expediente', etc.)",
    "publicBodyName": "ADMINISTRACIÓN COMPETENTE (ver reglas abajo)",
    "autonomousCommunityCode": "código de la CCAA a la que pertenece la administración (ver tabla abajo, null si es estatal)",
    "documentDate": "fecha del documento en formato YYYY-MM-DD",
    "applicableLaw": "SOLO la ley de transparencia principal (Ley 19/2013 o la autonómica equivalente)",
    "summary": "resumen breve del contenido (máximo 200 caracteres)",
    "status": "estado que indica el documento (uno de: enviada, en_tramite, concedida, concedida_completada, parcialmente_concedida, denegada, inadmitida, silencio, pendiente, null si no aplica)",
    "isExtension": "true si es una notificación de prórroga, false en caso contrario",
    "extensionDays": "número de días de prórroga (si aplica, null si no)",
    "newDeadlineDate": "nueva fecha límite si se menciona explícitamente en formato YYYY-MM-DD (null si no)",
    "denialReason": "motivo de denegación si el documento es una resolución denegatoria (null si no aplica)",
    "isRedirection": "true si el documento comunica que la solicitud se traslada/redirige a otro órgano porque la información no obra en poder del órgano original (art. 19.1 Ley 19/2013), false en caso contrario",
    "redirectedToPublicBody": "nombre COMPLETO del órgano al que se traslada, incluyendo el gobierno al que pertenece (ver reglas abajo)",
    "isThirdPartyRights": "true si el documento notifica que la solicitud afecta a derechos de terceros y se abre plazo de alegaciones (art. 19.3 Ley 19/2013), false en caso contrario",
    "thirdPartyAllegationsDeadline": "fecha límite para alegaciones de terceros en formato YYYY-MM-DD (si se menciona, null si no)",
    "isProcessingStart": "true si es un documento de comienzo/inicio de tramitación que notifica el inicio del plazo de 1 mes para resolver (art. 20.1 Ley 19/2013), false en caso contrario",
    "processingStartDate": "fecha a partir de la cual comienza el cómputo del plazo en formato YYYY-MM-DD (si isProcessingStart es true)",
    "requestTitle": "RESUMEN CORTO de qué información se solicita (ej: 'Contratos menores Hospital Jarrio 2018', 'Gastos publicidad Ayuntamiento 2023'). NO uses 'Solicitud de acceso a información pública'.",
    "requestDescription": "descripción detallada de la información solicitada, formateada en Markdown. Usa listas, negritas y estructura clara para facilitar la lectura.",
    "alegationPoints": "si el documento es un escrito de alegaciones de la Administración (durante proceso de reclamación ante CTBG u organismo equivalente), array con los principales argumentos/puntos de defensa de la Administración. null si no es un escrito de alegaciones",
    "keyPoints": "array con los puntos clave del documento. Obligatorio para: resoluciones (resolucion), reclamaciones (reclamacion), resoluciones de reclamación (resolucion_reclamacion) y respuestas a alegaciones (respuesta_alegaciones). Para resoluciones: los argumentos principales de la decisión (estimación, denegación, motivos). Para reclamaciones: los argumentos jurídicos principales del reclamante. Para resoluciones de reclamación: los fundamentos y el sentido del fallo del organismo de transparencia. Para respuestas a alegaciones: los contraargumentos principales. null para otros tipos de documento",
    "hearing_days": "los días que dura el trámite de audiencia para el ciudadano, si el documento abre uno (null si no aplica)",
    "hearing_days_type": "el tipo de días del trámite de audiencia: 'business' si son hábiles, 'calendar' si son naturales (null si no aplica)"
}

Si hay dudas en el formato de fechas que aparecen en el documento (por ejemplo 02/05/2026) asume siempre el formato DD/MM/YYYY.

REGLAS PARA publicBodyName:
- Identifica la ENTIDAD que tramita la solicitud (la que tiene portal de transparencia propio)
- Busca pistas en: registro electrónico, cabecera oficial, sello, pie de página
- USA TU CONOCIMIENTO de la administración española para elegir el nivel correcto

NIVEL CORRECTO - ni demasiado genérico ni demasiado específico:
- "Administración General del Estado" es DEMASIADO GENÉRICO → busca el organismo destinatario real (Adif, AENA, Ministerio concreto, etc.)
- "Consejería de X" sin más contexto es DEMASIADO GENÉRICO → usa el nombre de la CCAA (Principado de Asturias, Junta de Andalucía, etc.)
- Entidades con personalidad jurídica propia (Adif, RTVE, Canal de Isabel II, AENA, universidades) → usar su nombre directamente

En justificantes de registro electrónico:
- Si "Oficina de registro" es "Administración General del Estado", mira "Organismo destinatario" para el órgano real
- Si "Oficina de registro" es de una CCAA (ej: "Principado de Asturias"), usa el nombre de la CCAA

Ejemplos:
- Registro "Administración General del Estado" + Organismo destinatario "Adif" → "Adif"
- Registro "Administración General del Estado" + Organismo destinatario "Ministerio de Sanidad" → "Ministerio de Sanidad"
- Registro "Principado de Asturias" + Hospital Jarrio → "Principado de Asturias"
- "Canal de Isabel II" → "Canal de Isabel II" (entidad con portal propio)
- "RTVE" → "RTVE" (entidad con portal propio)
- "Universidad de Oviedo" → "Universidad de Oviedo" (entidad con portal propio)
- "Ayuntamiento de Madrid" → "Ayuntamiento de Madrid"

REGLAS PARA redirectedToPublicBody:
- Si el órgano es genérico (Consejería, Servicio, Dirección General, etc.), AÑADE el gobierno al que pertenece
- Usa el formato: "Nombre del órgano - Gobierno/Administración"
- Deduce el gobierno del contexto del documento (CCAA, ayuntamiento, ministerio, etc.)

Ejemplos:
- "Consejería de Agricultura" en documento de Castilla-La Mancha → "Consejería de Agricultura - Junta de Comunidades de Castilla-La Mancha"
- "Servicio de Salud" en documento de Castilla-La Mancha → "Servicio de Salud de Castilla-La Mancha (SESCAM)"
- "Consellería de Sanidade" en documento de Galicia → "Consellería de Sanidade - Xunta de Galicia"
- "Dirección General de Transparencia" en documento estatal → "Dirección General de Transparencia - Ministerio de la Presidencia"
- "Ayuntamiento de Toledo" → "Ayuntamiento de Toledo" (no necesita contexto adicional)

REGLAS PARA applicableLaw:
- SOLO incluye la ley de transparencia aplicable
- Para solicitudes estatales: "Ley 19/2013"
- Para Asturias: "Ley 8/2018 del Principado de Asturias"
- NO incluyas otras leyes mencionadas (contratos, procedimiento, etc.)

CÓDIGOS DE COMUNIDADES AUTÓNOMAS (autonomousCommunityCode):
- AND = Andalucía (Junta de Andalucía)
- ARA = Aragón (Gobierno de Aragón)
- AST = Principado de Asturias
- BAL = Illes Balears (Govern de les Illes Balears)
- CAN = Canarias (Gobierno de Canarias)
- CNT = Cantabria (Gobierno de Cantabria)
- CYL = Castilla y León (Junta de Castilla y León)
- CLM = Castilla-La Mancha (Junta de Comunidades de Castilla-La Mancha)
- CAT = Cataluña (Generalitat de Catalunya)
- CEU = Ceuta (Ciudad Autónoma de Ceuta)
- VAL = Comunitat Valenciana (Generalitat Valenciana)
- EXT = Extremadura (Junta de Extremadura)
- GAL = Galicia (Xunta de Galicia)
- MAD = Comunidad de Madrid
- MEL = Melilla (Ciudad Autónoma de Melilla)
- MUR = Región de Murcia
- NAV = Navarra (Gobierno de Navarra, Comunidad Foral)
- PVA = País Vasco (Gobierno Vasco, Eusko Jaurlaritza)
- RIO = La Rioja (Gobierno de La Rioja)
- null = Administración General del Estado (ministerios, organismos estatales)

REGLAS PARA autonomousCommunityCode:
- Identifica a qué comunidad autónoma pertenece la administración destinataria
- Para ministerios, organismos estatales (Adif, AENA, RTVE, etc.) → null
- Para ayuntamientos/diputaciones, usa el código de su CCAA
- Para universidades públicas, usa el código de la CCAA donde están ubicadas
- Para entidades autonómicas (Consejerías, SAS, SERGAS, etc.) → código de su CCAA

REGLAS PARA documentType (valores posibles: solicitud, acuse_recibo, inicio_tramitacion, resolucion, inadmitida, parcialmente_concedida, notificacion, prorroga, traslado, afectacion_terceros, reclamacion, acuse_recibo_reclamacion, inicio_tramitacion_reclamacion, resolucion_reclamacion, alegaciones, respuesta_alegaciones, audiencia, otro):

Usa "resolucion_reclamacion" si el documento es una resolución emitida por un organismo de transparencia (CTBG, GAIP, Comisionado de Transparencia, Consejo de Transparencia autonómico, etc.) que resuelve una reclamación interpuesta por el ciudadano. No confundir con "resolucion" que es la respuesta directa de la Administración a la solicitud.

Usa "alegaciones" SOLO si el documento es un escrito de alegaciones de la ADMINISTRACIÓN (el órgano público) durante un proceso de reclamación ante un organismo de transparencia. Es la defensa/respuesta de la Administración ante la reclamación del ciudadano. El remitente debe ser la Administración, no el ciudadano.

Usa "respuesta_alegaciones" si el documento es un escrito del CIUDADANO/INTERESADO respondiendo a las alegaciones de la Administración, o presentando sus propias alegaciones ante el organismo de transparencia (CTBG, etc.) durante el trámite de audiencia o alegaciones. El remitente es el interesado/reclamante, no la Administración.

Usa "audiencia" si es una notificación del organismo de transparencia notificando la apertura de un trámite de audiencia en el marco de un proceso de reclamación para que el ciudadano alegue. Cuando uses este tipo, rellena "hearing_days" con el número de días que da el documento para alegar y "hearing_days_type" con 'business' si son días hábiles o 'calendar' si son naturales. Si el documento no especifica el tipo de días, usa 'business' (los plazos administrativos en días se entienden hábiles salvo indicación expresa).

IMPORTANTE - Cuando el documento contiene una decisión sobre el acceso (concede / deniega / inadmite / parcial), usa el documentType específico según el sentido del fallo:

- **"resolucion"**: el órgano ESTIMA totalmente o DESESTIMA totalmente el acceso (denegación total).
  Palabras clave: "se estima la solicitud", "estimar", "se desestima", "desestimar", "se deniega el acceso", "RESUELVO conceder/denegar".

- **"inadmitida"**: el órgano INADMITE la solicitud a trámite (no entra a valorar el fondo).
  Palabras clave: "se inadmite", "INADMITIR", "no admitir a trámite", "inadmisión", "inadmisible". Suele citar art. 18 de la Ley 19/2013 (causas de inadmisión).

- **"parcialmente_concedida"**: el órgano ESTIMA PARCIALMENTE — concede acceso a parte de la información y deniega/limita el resto.
  Palabras clave: "estima parcialmente", "estimación parcial", "se concede acceso a parte", "acceso parcial", "se accede a la información solicitada con las siguientes limitaciones".

- **"notificacion"**: documento que NOTIFICA un acto administrativo (envío formal, fechas de comparecencia, código CSV) **pero NO contiene la resolución de fondo**. Si el cuerpo lleva la resolución estimatoria/desestimatoria/parcial/inadmisión adjunta o transcrita, usa el tipo correspondiente arriba. Solo cuando el documento es exclusivamente la portada de notificación (sin la decisión).

NO uses "resolucion"/"inadmitida"/"parcialmente_concedida" para:
- Meros acuses de recibo → usa "acuse_recibo"
- Notificaciones de inicio de tramitación → usa "inicio_tramitacion"
- Prórrogas del plazo → usa "prorroga"
- Traslados a otro órgano → usa "traslado"
- Notificaciones puras sin decisión → usa "notificacion"

IMPORTANTE:
- Responde SOLO con el JSON, sin texto adicional
- Si no puedes determinar un campo, usa null
- Para documentType usa exactamente uno de los valores indicados
- Las fechas deben estar en formato YYYY-MM-DD
