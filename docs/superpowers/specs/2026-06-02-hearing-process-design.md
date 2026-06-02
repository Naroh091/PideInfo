# Diseño: Trámite de audiencia con plazo (HearingProcess)

**Fecha:** 2026-06-02
**Estado:** aprobado (pendiente de plan de implementación)

## Objetivo

Cuando un documento notifica la apertura de un trámite de audiencia en una reclamación
(el organismo de transparencia da al reclamante N días para presentar alegaciones),
PideInfo debe registrar ese plazo y hacerlo visible: en el timeline, en la zona superior
del detalle de la solicitud (dossier-notice), en la zona "Plazos" del detalle
(`RequestStatusSidebar`) y en la box de Plazos de la home (`DeadlineAlerts`).

## Decisiones de diseño

| Decisión | Resolución |
|---|---|
| ¿Tipo de documento nuevo? | **No.** Se extiende el tipo existente `audiencia` (`DocumentType::Audiencia`); no se crea `apertura_tramite_audiencia`. |
| Inicio del cómputo | `startDate` = `documentDate` del documento; el plazo cuenta **desde el día siguiente** (Ley 39/2015 art. 30.3). |
| Tipo de días por defecto | Si el documento no especifica → `business` (días hábiles, Ley 39/2015 art. 30.2). |
| Cardinalidad | Una reclamación puede tener **varios** HearingProcess (OneToMany). El "activo" es el de `endDate` más lejana entre los no vencidos (`endDate >= hoy`). |
| Idempotencia | Cada HearingProcess guarda el `triggerDocument`; reprocesar el mismo documento actualiza en vez de duplicar. |
| Visibilidad del dossier-notice | Solo mientras el plazo está vivo (`endDate >= hoy`). El histórico queda en el timeline. |

## 1. Extracción LLM

**Prompts** (`config/prompts/document/analyze-single.md` y `analyze-multi.md`):

Dos claves nuevas en el JSON de salida, solo con valor cuando `documentType = 'audiencia'`
y el documento abre un trámite indicando plazo para alegar:

- `hearing_days`: entero, número de días para presentar alegaciones (null si no aplica)
- `hearing_days_type`: `"business"` (días hábiles) | `"calendar"` (días naturales); si el
  documento no lo especifica, el prompt instruye a devolver `"business"`

**Normalización** (`DocumentAnalyzer::normalizeDocumentAnalysis()`): expone
`hearingDays` (?int) y `hearingDaysType` (?string, validado contra `business|calendar`).

Tras editar las plantillas: `bin/console app:langfuse:sync-prompts`.

## 2. Entidad y migración

```
HearingProcess (tabla: hearing_process)
├── id              UUID v7 (PK)
├── complaint       ManyToOne → AccessRequestComplaint (inversedBy: hearingProcesses, not null)
├── triggerDocument ManyToOne → Document (nullable)
├── startDate       DATE_IMMUTABLE   (= documentDate)
├── endDate         DATE_IMMUTABLE   (calculada)
├── hearingDays     int
├── hearingDaysType string(16)       ('business'|'calendar')
└── createdAt       DATETIME_IMMUTABLE
```

- `AccessRequestComplaint`: colección `hearingProcesses` + helper `getActiveHearingProcess(): ?HearingProcess`
  (el de `endDate` más lejana entre los no vencidos; null si no hay ninguno vivo).
- `HearingProcessRepository::findApproachingDeadlines(int $daysThreshold, ?User $user)`:
  hearing processes activos (o recién vencidos) de reclamaciones del usuario, para `DeadlineAlerts`.
- Migración idempotente (`CREATE TABLE IF NOT EXISTS hearing_process ...`, `DROP TABLE IF EXISTS` en down).
- `DeadlineHistory`: nueva constante `TYPE_HEARING = 'hearing'`.

## 3. Cálculo del plazo (`DeadlineCalculator`)

Nuevo método público:

```php
calculateHearingDeadline(\DateTimeImmutable $documentDate, int $days, string $type): \DateTimeImmutable
```

- El cómputo empieza el día siguiente a `documentDate`.
- `calendar` → `documentDate + days` días naturales.
- `business` → N días hábiles contados desde el día siguiente, saltando fines de semana
  y festivos nacionales (reutiliza `addBusinessDays()` / `isHoliday()` existentes).

## 4. Pipeline de documentos (`ProcessDocumentHandler`, caso `Audiencia`)

Comportamiento actual (se mantiene): `ensureComplaint()` + `recordStatusChange(...)`.

Nuevo, cuando el análisis trae `hearingDays`:

1. Idempotencia: buscar HearingProcess existente con el mismo `triggerDocument`; si existe, actualizar fechas/días.
2. Si no existe, crear HearingProcess con `startDate = documentDate`, `endDate` calculada con `DeadlineCalculator`.
3. Registrar entrada en `DeadlineHistory` (tipo `hearing`, con `triggerDocument`).
4. La nota del `recordStatusChange` pasa a incluir el plazo:
   *"Trámite de audiencia abierto: 10 días hábiles para alegar (hasta 15/06/2026)"*.
   El timeline ya detecta "audiencia" en las notas → el item sale con la fecha sin tocar el template.

Si el análisis NO trae `hearingDays` → comportamiento actual sin cambios (solo status change).

## 5. UI

### 5a. Dossier-notice (detalle de solicitud, `templates/solicitudes/show.html.twig`)

Nuevo bloque `<aside class="dossier-notice is-amber">` cuando
`request.complaint?.activeHearingProcess` existe:

- Eyebrow: "Trámite de audiencia"
- Headline (fecha destacada): *"Tienes hasta el {endDate} para presentar alegaciones"*
- Prose: N días hábiles/naturales desde la notificación del {startDate} + enlace al documento que lo abrió
- Se oculta al vencer el plazo.

### 5b. Zona "Plazos" del detalle (`templates/components/RequestStatusSidebar.html.twig`)

Nueva fila tras "Plazo resolución reclamación", visible cuando la reclamación tiene
algún HearingProcess. Muestra el activo o, si todos están vencidos, el de `endDate`
más reciente (con tag "Vencido", igual que el resto de filas):

```
Plazo de alegaciones    15/06/2026  [· N días | Vencido]
  └ aside-note: Trámite de audiencia: 10 días hábiles desde el 01/06/2026.
```

### 5c. Box de Plazos de la home (`DeadlineAlerts`)

Nueva fuente de alertas: hearing processes con `endDate` dentro del umbral (7 días)
o vencidos, del usuario actual. Mismo formato/ordenación por urgencia:

- Mensajes: "Plazo de alegaciones vencido" / "vence hoy" / "vence mañana" / "vence en N días"
- Enlace al detalle de la solicitud.

## 6. Tests

- **Unit** `DeadlineCalculatorTest`: cálculo hearing deadline en días naturales, hábiles
  cruzando fin de semana y festivo nacional, y arranque en viernes.
- **Unit** `DocumentAnalyzerTest` (o equivalente): normalización de `hearing_days` /
  `hearing_days_type` (valores válidos, inválidos, ausentes).
- **Handler**: creación de HearingProcess + idempotencia al reprocesar (si la infraestructura
  de tests con BD lo permite en este entorno; si no, unit test de la lógica extraída).
- Helper `getActiveHearingProcess()`: activo vs vencido vs varios.

## 7. Documentación

- `docs/document-processing.md`: nuevas claves del schema LLM + flujo HearingProcess.
- `docs/complaint-workflow.md`: sección sobre el trámite de audiencia y su plazo.
