# Diseño: Activación de cuentas configurable + email de activación

**Fecha:** 2026-04-13

## Contexto

PideInfo está en beta cerrada. Actualmente todas las cuentas nacen con `isActive = false` y un admin debe activarlas manualmente desde EasyAdmin. Se quiere:

1. Hacer configurable si la activación manual es necesaria (por defecto no).
2. Enviar un email al usuario cuando un admin active su cuenta manualmente.
3. `/guias/privacidad` con acceso público — **ya implementado** en `security.yaml`.

## Cambios en scope

### A. Variable de entorno `USER_NEEDS_MANUAL_ACTIVATION`

- Tipo: booleano. Valor por defecto: `false` (cuentas activadas automáticamente al registrarse).
- Se añade a `.env.dev` (y `.env` si existe como plantilla).
- Cuando `false`: en `SecurityController::register()` se llama `$user->setIsActive(true)` antes de persistir. El flash message ya no menciona la beta cerrada ni la activación manual.
- Cuando `true`: comportamiento actual — `isActive` permanece `false` hasta que un admin lo cambie.

Inyección en el controlador:
```php
#[Autowire(env: 'bool:USER_NEEDS_MANUAL_ACTIVATION')]
private bool $needsManualActivation,
```

### B. Email al activar manualmente

**Condición de envío:** solo cuando `USER_NEEDS_MANUAL_ACTIVATION=true` y un admin cambia `isActive` de `false` a `true` vía EasyAdmin.

**Punto de enganche:** override de `updateEntity()` en `UserCrudController`.

Lógica:
1. Antes de llamar a `parent::updateEntity()`, capturar `$originalIsActive = $user->isActive()`.
2. Llamar a `parent::updateEntity($entityManager, $entityInstance)`.
3. Si `$originalIsActive === false && $user->isActive() === true && $this->needsManualActivation`, enviar email.

**Template:** `templates/email/account_activated.html.twig`, extendiendo `email/base.html.twig`. Contenido: saludo con nombre, mensaje de que la cuenta está activa, botón "Acceder a PideInfo" apuntando a `app_login`.

**Remitente:** igual que el resto de emails (`mail_from_address` / `mail_from_name`).

### C. `/guias/privacidad` — sin cambios

Ya presente en `security.yaml`:
```yaml
- { path: ^/guias/privacidad, roles: PUBLIC_ACCESS }
```

## Archivos afectados

| Archivo | Cambio |
|---|---|
| `.env.dev` | Añadir `USER_NEEDS_MANUAL_ACTIVATION=false` |
| `src/Controller/SecurityController.php` | Inyectar env var; activar usuario si `false`; ajustar flash |
| `src/Controller/Admin/UserCrudController.php` | Override `updateEntity()`; inyectar mailer y env var; enviar email |
| `templates/email/account_activated.html.twig` | Nuevo template de activación |

## Lo que NO cambia

- `User::$isActive` por defecto sigue siendo `false` en la entidad (la lógica de activación automática vive en el controlador, no en el constructor).
- `UserChecker` no cambia — sigue bloqueando si `isActive = false`.
- No se añade migración — no hay cambios de esquema.
