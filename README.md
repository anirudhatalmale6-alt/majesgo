# MajesGo — Panel de administración (Hito 1)

Plataforma de taxi estilo inDrive para **Majes / El Pedregal, Arequipa**.
_Tu taxi en un toque._

Este repositorio contiene el **Hito 1: fundación del backend + panel de administración web**.
Las apps de **Pasajero** y **Conductor** (Flutter, Android + iOS) se construyen en los siguientes hitos.

## Qué incluye este hito

- **Autenticación de administrador** (login seguro, cambio de contraseña, control de cuentas activas/inactivas).
- **Gestión de conductores** (los crea el administrador — no hay auto-registro): alta, edición, foto/vehículo/licencia, estados (Disponible / Ocupado / Desconectado), estado de cuenta (Activo / Suspendido / Bloqueado), reseteo de contraseña.
- **Sistema de saldo y comisión**: cada conductor tiene un saldo; la comisión por carrera completada es **configurable** (por defecto S/ 0.50); libro de movimientos (ledger) con saldo resultante por cada operación.
- **Recargas**: registro y validación de recargas por Yape / transferencia / carga manual; al aprobar se acredita el saldo automáticamente.
- **Configuración**: comisión, montos de recarga sugeridos, datos de Yape, nombre/ciudad/moneda de la plataforma, contraseña.
- **Instalable como app (PWA)**: se puede agregar a la pantalla de inicio del celular con el ícono de MajesGo.
- **Diseño 100% con la identidad de marca MajesGo** (verde #00C853, amarillo #FFC107, negro #0D0D0D, tipografía Poppins).

## Stack

- **Laravel 13** (PHP 8.2+)
- **MySQL** en producción (SQLite en desarrollo — listo para ambos)
- **Blade** para las vistas del panel
- Preparado para exponer una **API** que consumirán las apps móviles en los próximos hitos.

## Puesta en marcha (desarrollo)

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite      # o configurar MySQL en .env
php artisan migrate --seed
php artisan serve
```

Usuario administrador de ejemplo (creado por el seeder): se define en `database/seeders/DatabaseSeeder.php`.
**Importante:** cambiar la contraseña del administrador después del primer ingreso.

## Estructura principal

- `app/Models/` — Driver, Recharge, SaldoMovement, Setting, User
- `app/Http/Controllers/Admin/` — Auth, Dashboard, Driver, Recharge, Setting
- `database/migrations/` — drivers, settings, recharges, saldo_movements
- `resources/views/admin/` — panel (layout con marca, login, dashboard, conductores, recargas, configuración)

---

© MajesGo · Propiedad del cliente. Código entregado como parte del proyecto.
