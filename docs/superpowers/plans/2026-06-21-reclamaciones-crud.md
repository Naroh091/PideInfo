# Reclamaciones CRUD (EasyAdmin) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Añadir CRUD de `AccessRequestComplaint` en EasyAdmin y enlazar la reclamación asociada desde el CRUD de Solicitudes.

**Architecture:** Tres cambios de configuración puros en EasyAdmin 4 más un `__toString()` en la entidad. Sin migraciones ni servicios nuevos.

**Tech Stack:** PHP 8.2, Symfony 7, EasyAdmin 4, Doctrine ORM.

## Global Constraints

- EasyAdmin 4 pattern: `AbstractCrudController`, configuración en PHP (no YAML).
- Acción **New desactivada** en el CRUD de reclamaciones — las reclamaciones se crean desde el frontend.
- Sin migraciones de BD.
- No hay lógica de negocio testeable con unit tests — la verificación es funcional (abrir el admin en browser).

---

## File Map

| Fichero | Acción |
|---|---|
| `src/Entity/AccessRequestComplaint.php` | Modificar — añadir `__toString()` |
| `src/Controller/Admin/AccessRequestComplaintCrudController.php` | Crear — CRUD completo |
| `src/Controller/Admin/DashboardController.php` | Modificar — añadir ítem de menú |
| `src/Controller/Admin/AccessRequestCrudController.php` | Modificar — sustituir campo |

---

### Task 1: Añadir `__toString()` a `AccessRequestComplaint`

**Files:**
- Modify: `src/Entity/AccessRequestComplaint.php`

**Interfaces:**
- Produces: `AccessRequestComplaint::__toString(): string` — usado por EasyAdmin para renderizar el `AssociationField` en Solicitudes y en el propio CRUD de reclamaciones.

- [ ] **Step 1: Añadir `__toString()` antes del cierre de la clase**

  En `src/Entity/AccessRequestComplaint.php`, justo antes de `}` final, añadir:

  ```php
  public function __toString(): string
  {
      return $this->externalId ?? 'Reclamación ' . substr((string) $this->id, 0, 8);
  }
  ```

- [ ] **Step 2: Verificar sintaxis PHP**

  ```bash
  php -l src/Entity/AccessRequestComplaint.php
  ```
  Expected: `No syntax errors detected in src/Entity/AccessRequestComplaint.php`

- [ ] **Step 3: Commit**

  ```bash
  git add src/Entity/AccessRequestComplaint.php
  git commit -m "feat: add __toString to AccessRequestComplaint for EasyAdmin rendering"
  ```

---

### Task 2: Crear `AccessRequestComplaintCrudController`

**Files:**
- Create: `src/Controller/Admin/AccessRequestComplaintCrudController.php`

**Interfaces:**
- Consumes: `AccessRequestComplaint::__toString()` (Task 1), `AccessRequestCrudController::class` (ya existe).
- Produces: `AccessRequestComplaintCrudController::class` — usado en Task 3 por `AccessRequestCrudController` y `DashboardController`.

- [ ] **Step 1: Crear el fichero del controlador**

  Crear `src/Controller/Admin/AccessRequestComplaintCrudController.php` con el siguiente contenido completo:

  ```php
  <?php

  namespace App\Controller\Admin;

  use App\Entity\AccessRequestComplaint;
  use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
  use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
  use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
  use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
  use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
  use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
  use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
  use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
  use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
  use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
  use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
  use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
  use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
  use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
  use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

  class AccessRequestComplaintCrudController extends AbstractCrudController
  {
      public static function getEntityFqcn(): string
      {
          return AccessRequestComplaint::class;
      }

      public function configureCrud(Crud $crud): Crud
      {
          return $crud
              ->setEntityLabelInSingular('Reclamación')
              ->setEntityLabelInPlural('Reclamaciones')
              ->setSearchFields(['externalId', 'expedienteEstado', 'expedienteTitulo'])
              ->setDefaultSort(['createdAt' => 'DESC'])
              ->setPaginatorPageSize(30);
      }

      public function configureActions(Actions $actions): Actions
      {
          return $actions->disable(Action::NEW);
      }

      public function configureFilters(Filters $filters): Filters
      {
          return $filters
              ->add(ChoiceFilter::new('status', 'Estado')->setChoices([
                  'Reclamada' => AccessRequestComplaint::STATUS_RECLAIMED,
                  'Reclamación estimada' => AccessRequestComplaint::STATUS_GRANTED,
                  'Reclamación desestimada' => AccessRequestComplaint::STATUS_DENIED,
                  'Reclamación archivada' => AccessRequestComplaint::STATUS_ARCHIVED,
              ]))
              ->add(ChoiceFilter::new('complaintResult', 'Resultado')->setChoices([
                  'Estimada' => AccessRequestComplaint::RESULT_UPHELD,
                  'Estimada parcialmente' => AccessRequestComplaint::RESULT_PARTIALLY_UPHELD,
                  'Desestimada' => AccessRequestComplaint::RESULT_DISMISSED,
                  'Inadmitida' => AccessRequestComplaint::RESULT_INADMITTED,
                  'Archivada' => AccessRequestComplaint::RESULT_ARCHIVED,
              ]))
              ->add(EntityFilter::new('accessRequest', 'Solicitud'))
              ->add(DateTimeFilter::new('filedAt', 'Fecha de presentación'));
      }

      public function configureFields(string $pageName): iterable
      {
          yield IdField::new('id')->hideOnForm()->onlyOnDetail();

          yield AssociationField::new('accessRequest', 'Solicitud')
              ->setCrudController(AccessRequestCrudController::class)
              ->hideOnForm();

          yield TextField::new('externalId', 'Ref. CTBG');

          yield ChoiceField::new('status', 'Estado')
              ->setChoices([
                  'Reclamada' => AccessRequestComplaint::STATUS_RECLAIMED,
                  'Reclamación estimada' => AccessRequestComplaint::STATUS_GRANTED,
                  'Reclamación desestimada' => AccessRequestComplaint::STATUS_DENIED,
                  'Reclamación archivada' => AccessRequestComplaint::STATUS_ARCHIVED,
              ])
              ->renderAsBadges([
                  AccessRequestComplaint::STATUS_RECLAIMED => 'primary',
                  AccessRequestComplaint::STATUS_GRANTED => 'success',
                  AccessRequestComplaint::STATUS_DENIED => 'danger',
                  AccessRequestComplaint::STATUS_ARCHIVED => 'secondary',
              ]);

          yield ChoiceField::new('complaintResult', 'Resultado')
              ->setChoices([
                  'Estimada' => AccessRequestComplaint::RESULT_UPHELD,
                  'Estimada parcialmente' => AccessRequestComplaint::RESULT_PARTIALLY_UPHELD,
                  'Desestimada' => AccessRequestComplaint::RESULT_DISMISSED,
                  'Inadmitida' => AccessRequestComplaint::RESULT_INADMITTED,
                  'Archivada' => AccessRequestComplaint::RESULT_ARCHIVED,
              ])
              ->setRequired(false)
              ->renderAsBadges([
                  AccessRequestComplaint::RESULT_UPHELD => 'success',
                  AccessRequestComplaint::RESULT_PARTIALLY_UPHELD => 'warning',
                  AccessRequestComplaint::RESULT_DISMISSED => 'danger',
                  AccessRequestComplaint::RESULT_INADMITTED => 'danger',
                  AccessRequestComplaint::RESULT_ARCHIVED => 'secondary',
              ]);

          yield DateField::new('filedAt', 'Fecha de presentación');
          yield DateField::new('deadlineAt', 'Plazo CTBG');

          yield DateField::new('complianceDeadlineAt', 'Plazo de cumplimiento')
              ->hideOnIndex();

          yield TextField::new('expedienteEstado', 'Estado expediente CTBG')
              ->hideOnIndex();

          yield TextareaField::new('expedienteTitulo', 'Título expediente CTBG')
              ->hideOnIndex();

          yield DateField::new('fechaApertura', 'Fecha apertura CTBG')
              ->hideOnIndex();

          yield DateField::new('fechaCierre', 'Fecha cierre CTBG')
              ->hideOnIndex();

          yield DateField::new('createdAt', 'Creada')
              ->hideOnForm();

          yield DateTimeField::new('updatedAt', 'Actualizada')
              ->hideOnForm()
              ->onlyOnDetail();
      }
  }
  ```

- [ ] **Step 2: Verificar sintaxis PHP**

  ```bash
  php -l src/Controller/Admin/AccessRequestComplaintCrudController.php
  ```
  Expected: `No syntax errors detected in src/Controller/Admin/AccessRequestComplaintCrudController.php`

- [ ] **Step 3: Commit**

  ```bash
  git add src/Controller/Admin/AccessRequestComplaintCrudController.php
  git commit -m "feat: add AccessRequestComplaintCrudController to EasyAdmin"
  ```

---

### Task 3: Registrar en el menú y actualizar el campo en Solicitudes

**Files:**
- Modify: `src/Controller/Admin/DashboardController.php`
- Modify: `src/Controller/Admin/AccessRequestCrudController.php`

**Interfaces:**
- Consumes: `AccessRequestComplaintCrudController::class` (Task 2), `AccessRequestComplaint::class` (ya existe).

- [ ] **Step 1: Añadir ítem de menú en `DashboardController`**

  En `src/Controller/Admin/DashboardController.php`:

  1. Añadir el `use` de la entidad (si no está):
     ```php
     use App\Entity\AccessRequestComplaint;
     ```

  2. En `configureMenuItems()`, bajo la línea de Solicitudes (justo después del ítem "Documentos"):
     ```php
     yield MenuItem::linkToCrud('Reclamaciones', 'fa fa-gavel', AccessRequestComplaint::class);
     ```

  La sección "Solicitudes" debe quedar:
  ```php
  yield MenuItem::section('Solicitudes');
  yield MenuItem::linkToCrud('Solicitudes', 'fa fa-folder-open', AccessRequest::class);
  yield MenuItem::linkToCrud('Reclamaciones', 'fa fa-gavel', AccessRequestComplaint::class);
  yield MenuItem::linkToCrud('Documentos', 'fa fa-file', Document::class);
  ```

- [ ] **Step 2: Sustituir el campo `complaintStatusLabel` en `AccessRequestCrudController`**

  En `src/Controller/Admin/AccessRequestCrudController.php`, dentro de `configureFields()`, localizar:

  ```php
  yield TextField::new('complaintStatusLabel', 'Reclamación')
      ->hideOnForm();
  ```

  Sustituir por:

  ```php
  yield AssociationField::new('complaint', 'Reclamación')
      ->hideOnForm()
      ->setCrudController(AccessRequestComplaintCrudController::class);
  ```

  El `use` de `AssociationField` ya existe en el fichero (línea 17). Añadir el `use` del nuevo controlador:

  ```php
  use App\Controller\Admin\AccessRequestComplaintCrudController;
  ```

- [ ] **Step 3: Verificar sintaxis PHP de ambos ficheros**

  ```bash
  php -l src/Controller/Admin/DashboardController.php && \
  php -l src/Controller/Admin/AccessRequestCrudController.php
  ```
  Expected: `No syntax errors detected` en ambos.

- [ ] **Step 4: Verificar que el contenedor Symfony compila sin errores**

  ```bash
  php bin/console cache:clear 2>&1 | tail -5
  ```
  Expected: `[OK] Cache for the "dev" environment (debug) was successfully cleared.`

- [ ] **Step 5: Commit**

  ```bash
  git add src/Controller/Admin/DashboardController.php \
          src/Controller/Admin/AccessRequestCrudController.php
  git commit -m "feat: wire Reclamaciones CRUD into admin menu and link from Solicitudes"
  ```

---

## Verificación final

Abrir `http://localhost/admin` (o el puerto del dev server) y comprobar:

1. El menú lateral muestra **"Reclamaciones"** bajo la sección "Solicitudes".
2. El listado de Reclamaciones carga y muestra columnas: Solicitud, Ref. CTBG, Estado, Resultado, Fecha presentación, Plazo CTBG, Creada.
3. El botón **New** no aparece en el listado de reclamaciones.
4. Hacer clic en una reclamación abre su detalle con todos los campos.
5. En el listado de Solicitudes, la columna "Reclamación" muestra en blanco para solicitudes sin reclamación, y un enlace clicable para las que sí tienen.
6. Hacer clic en ese enlace lleva directamente al detalle de la reclamación.
