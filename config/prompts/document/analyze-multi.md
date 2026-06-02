Tienes {{document_count}} documentos relacionados con la MISMA solicitud de acceso a información pública en España.
Pueden incluir: la solicitud original, el justificante de registro, acuses de recibo, resoluciones, traslados, inicios de tramitación, etc.

Analiza TODOS los documentos juntos para extraer la información más completa y precisa.
Si un dato aparece en un documento pero no en otro, usa el que tengas.
El justificante de registro suele tener el número de registro y la administración correcta.

IMPORTANTE: Devuelve un JSON con DOS secciones:
1. "shared" — información compartida de la solicitud (extraída de TODOS los documentos juntos)
2. "documents" — un array con {{document_count}} elementos, uno POR CADA documento, en el mismo orden en que se presentaron (Documento 1, Documento 2, etc.)

Cada documento tiene su PROPIO tipo, fecha y resumen. NO clasifiques todos los documentos con el mismo tipo.

{
    "shared": {
        "referenceNumber": "número de expediente o registro (busca 'Nº de registro', 'Expediente', etc.)",
        "publicBodyName": "ADMINISTRACIÓN COMPETENTE (ver reglas abajo)",
        "autonomousCommunityCode": "código de la CCAA a la que pertenece la administración (ver tabla abajo, null si es estatal)",
        "applicableLaw": "SOLO la ley de transparencia principal (Ley 19/2013 o la autonómica equivalente)",
        "requestTitle": "RESUMEN CORTO de qué información se solicita (ej: 'Contratos menores Hospital Jarrio 2018'). NO uses 'Solicitud de acceso a información pública'.",
        "requestDescription": "descripción detallada de la información solicitada, formateada en Markdown. Usa listas, negritas y estructura clara para facilitar la lectura."
    },
    "documents": [
        {
            "documentType": "tipo de ESTE documento concreto (uno de: solicitud, acuse_recibo, inicio_tramitacion, resolucion, notificacion, prorroga, traslado, afectacion_terceros, reclamacion, acuse_recibo_reclamacion, inicio_tramitacion_reclamacion, resolucion_reclamacion, alegaciones, otro)",
            "documentDate": "fecha de ESTE documento en formato YYYY-MM-DD",
            "summary": "resumen breve de ESTE documento (máximo 200 caracteres)",
            "status": "estado que indica ESTE documento (uno de: enviada, en_tramite, concedida, denegada, silencio, pendiente, null si no aplica)",
            "isExtension": false,
            "extensionDays": null,
            "newDeadlineDate": null,
            "denialReason": null,
            "isRedirection": false,
            "redirectedToPublicBody": null,
            "isThirdPartyRights": false,
            "thirdPartyAllegationsDeadline": null,
            "isProcessingStart": false,
            "processingStartDate": null,
            "alegationPoints": null,
            "hearing_days": null,
            "hearing_days_type": null
        }
    ]
}

REGLAS PARA publicBodyName:
- Identifica la ENTIDAD que tramita la solicitud (la que tiene portal de transparencia propio)
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

REGLAS PARA documentType (valores posibles: solicitud, acuse_recibo, inicio_tramitacion, resolucion, prorroga, traslado, afectacion_terceros, reclamacion, acuse_recibo_reclamacion, inicio_tramitacion_reclamacion, resolucion_reclamacion, alegaciones, respuesta_alegaciones, audiencia, otro):

Usa "resolucion_reclamacion" si el documento es una resolución emitida por un organismo de transparencia (CTBG, GAIP, Comisionado de Transparencia, Consejo de Transparencia autonómico, etc.) que resuelve una reclamación interpuesta por el ciudadano. No confundir con "resolucion" que es la respuesta directa de la Administración a la solicitud.

Usa "alegaciones" SOLO si el documento es un escrito de alegaciones de la ADMINISTRACIÓN (el órgano público) durante un proceso de reclamación ante un organismo de transparencia. Es la defensa/respuesta de la Administración ante la reclamación del ciudadano. El remitente debe ser la Administración, no el ciudadano.

Usa "respuesta_alegaciones" si el documento es un escrito del CIUDADANO/INTERESADO respondiendo a las alegaciones de la Administración, o presentando sus propias alegaciones ante el organismo de transparencia (CTBG, etc.) durante el trámite de audiencia o alegaciones. El remitente es el interesado/reclamante, no la Administración.

Usa "audiencia" si es una notificación del organismo de transparencia notificando la apertura de un trámite de audiencia en el marco de un proceso de reclamación para que el ciudadano alegue. Cuando uses este tipo, rellena "hearing_days" con el número de días que da el documento para alegar y "hearing_days_type" con 'business' si son días hábiles o 'calendar' si son naturales. Si el documento no especifica el tipo de días, usa 'business' (los plazos administrativos en días se entienden hábiles salvo indicación expresa).

IMPORTANTE - Usa "resolucion" si el documento:
- ESTIMA (concede/otorga) el acceso a la información solicitada
- DESESTIMA (deniega/rechaza) el acceso a la información
- ESTIMA PARCIALMENTE el acceso
- INADMITE la solicitud
- Contiene una sección "RESOLUCIÓN" con un fallo/decisión final sobre el acceso
- Aunque el título sea "NOTIFICACIÓN", si contiene una resolución estimatoria o desestimatoria, es "resolucion"

Palabras clave que indican "resolucion":
- "se estima", "estimar la solicitud", "estimación"
- "se desestima", "desestimar", "desestimación"
- "se concede acceso", "se deniega acceso"
- "RESUELVO", "RESOLUCIÓN" (como sección de decisión)

NO uses "resolucion" para:
- Meros acuses de recibo → usa "acuse_recibo"
- Notificaciones de inicio de tramitación → usa "inicio_tramitacion"
- Prórrogas del plazo → usa "prorroga"
- Traslados a otro órgano → usa "traslado"

IMPORTANTE:
- Responde SOLO con el JSON, sin texto adicional
- Si no puedes determinar un campo, usa null
- Las fechas deben estar en formato YYYY-MM-DD
