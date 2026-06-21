# CRUD de Reclamaciones (EasyAdmin) — Diseño

**Fecha:** 2026-06-21
**Alcance:** Panel de administración únicamente (`/admin`, EasyAdmin 4). Sin cambios en el frontend de usuario.

---

## Objetivo

Añadir un CRUD completo de `AccessRequestComplaint` (Reclamaciones) en el panel de admin, y sustituir el campo de texto estático `complaintStatusLabel` en el CRUD de Solicitudes por un enlace clicable a la reclamación asociada cuando ésta exista.

---

## Ficheros afectados

| Fichero | Tipo de cambio |
|---|---|
| `src/Controller/Admin/AccessRequestComplaintCrudController.php` | Nuevo |
| `src/Controller/Admin/DashboardController.php` | Añadir ítem de menú |
| `src/Controller/Admin/AccessRequestCrudController.php` | Sustituir campo |
| `src/Entity/AccessRequestComplaint.php` | Añadir `__toString()` |

Sin migraciones, sin servicios nuevos, sin cambios de BD.

---

## Entidad

`AccessRequestComplaint` — relación `OneToOne` bidireccional con `AccessRequest`. El FK (`access_request_id`) vive en la tabla `access_request_complaint`. Una solicitud puede tener cero o una reclamación.

---

## AccessRequestComplaintCrudController

### Configuración general

- Label singular: **"Reclamación"**, plural: **"Reclamaciones"**
- Orden por defecto: `createdAt DESC`
- Paginación: 30 por página
- Búsqueda: `externalId`, `expedienteEstado`, `expedienteTitulo`
- Acción **New desactivada** (las reclamaciones se crean desde el frontend; admin sólo gestiona las existentes)

### Campos

| Campo | Páginas | Notas |
|---|---|---|
| `id` | Detail | Sólo lectura |
| `accessRequest` | Index, Detail | `AssociationField` → enlaza al CRUD de Solicitudes |
| `externalId` | Index, Detail, Edit | Ref. CTBG |
| `status` | Index, Detail, Edit | `ChoiceField` con badges |
| `complaintResult` | Index, Detail, Edit | `ChoiceField` con badges, nullable |
| `filedAt` | Index, Detail, Edit | Fecha de presentación |
| `deadlineAt` | Index, Detail, Edit | Plazo resolución CTBG |
| `complianceDeadlineAt` | Detail, Edit | Plazo de cumplimiento |
| `expedienteEstado` | Detail, Edit | Estado en portal CTBG |
| `expedienteTitulo` | Detail, Edit | Título del expediente CTBG |
| `fechaApertura` | Detail, Edit | Fecha apertura expediente CTBG |
| `fechaCierre` | Detail, Edit | Fecha cierre expediente CTBG |
| `createdAt` | Index, Detail | Sólo lectura |
| `updatedAt` | Detail | Sólo lectura |

### Badges de `status`

| Valor | Color |
|---|---|
| `reclaimed` | `primary` |
| `complaint_granted` | `success` |
| `complaint_denied` | `danger` |
| `complaint_archived` | `secondary` |

### Badges de `complaintResult`

| Valor | Color |
|---|---|
| `upheld` | `success` |
| `partially_upheld` | `warning` |
| `dismissed` | `danger` |
| `inadmitted` | `danger` |
| `archived` | `secondary` |

### Filtros

- `status` (ChoiceFilter)
- `complaintResult` (ChoiceFilter)
- `accessRequest` (EntityFilter)
- `filedAt` (DateTimeFilter)

---

## Cambios en AccessRequestCrudController

Sustituir:
```php
yield TextField::new('complaintStatusLabel', 'Reclamación')->hideOnForm();
```

Por:
```php
yield AssociationField::new('complaint', 'Reclamación')
    ->hideOnForm()
    ->setCrudController(AccessRequestComplaintCrudController::class);
```

EasyAdmin muestra el campo en blanco cuando `complaint === null`, y un enlace clicable al detalle del CRUD de reclamaciones cuando existe.

---

## Cambios en AccessRequestComplaint::__toString()

```php
public function __toString(): string
{
    return $this->externalId ?? 'Reclamación ' . substr((string) $this->id, 0, 8);
}
```

---

## Cambios en DashboardController

Añadir ítem de menú bajo la sección "Solicitudes":
```php
yield MenuItem::linkToCrud('Reclamaciones', 'fa fa-gavel', AccessRequestComplaint::class);
```

---

## Lo que NO entra en este diseño

- Creación de reclamaciones desde admin (se hace desde el frontend)
- Vista de `HearingProcess` (trámites de audiencia) — entidad separada, fuera de alcance
- Cambios en el frontend de usuario
