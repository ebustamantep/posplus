# POSPlus 2.0: venta sin conexión (diseño)

Estado: diseño aprobado el 2026-11-03. Fase 0 hecha en la 1.2.6 (clase `PosplusPayment`).

## Alcance

- Una terminal, cortes de hasta 1 hora, solo venta al contado.
- Pago en $ o Bs con la tasa guardada en el navegador.
- Ticket provisional impreso con el diálogo de impresión del navegador (térmica USB).
- Al volver la conexión, cada venta se convierte en una factura normal de TakePOS: validada, cobrada, con su movimiento de stock y dentro del turno abierto.
- Fuera de alcance: crédito, anulaciones, devoluciones, descuentos, historial offline y consulta de stock.

Se activa con una opción del setup de POSPlus (`POSPLUS_OFFLINE`, desactivada por defecto). El código va en `posplus/offline/`; con la opción apagada no se carga nada.

## Arquitectura

```
          CON CONEXIÓN                               SIN CONEXIÓN
┌────────────────────────────┐            ┌──────────────────────────────┐
│ TakePOS + POSPlus (normal) │──corte────▶│ Pantalla "Venta offline"     │
│  · cada 5 min descarga una │            │ /custom/posplus/offline/     │
│    copia del catálogo      │◀─vuelve────│  · copia local (IndexedDB)   │
└────────────────────────────┘ conexión   │  · cola de ventas            │
             ▲                            │  · ticket provisional        │
             │  sincronizar               └──────────────────────────────┘
             └──── offline/sync.php ◀── ventas en cola (una a una)
```

- **Pantalla propia, no TakePOS:** un service worker solo controla las páginas de su carpeta (`/custom/posplus/…`) y TakePOS vive en `/takepos/`. Con conexión se usa TakePOS; al detectar el corte, TakePOS ofrece pasar a "Venta offline".
- **Service worker** (`offline/sw.js`): guarda la pantalla offline y sus JS/CSS para que cargue aunque se refresque o se cierre el navegador. Requiere HTTPS. Sin CDN: jQuery y estilos de la propia instalación.
- **Detección del corte:** consulta a `offline/ping.php` cada 15 s con un tiempo máximo de 3 s; tras 2 fallos seguidos se considera sin conexión (`navigator.onLine` no es fiable).
- **Almacenamiento:** IndexedDB, con `navigator.storage.persist()`.

## Copia local (`offline/snapshot.php`)

JSON descargado al abrir el turno y cada 5 minutos con conexión:

| Dato | Contenido |
|---|---|
| Productos | id, ref, nombre, código de barras, `price`, `price_ttc`, `price_base_type`, `tva_tx`, categorías (sin imágenes) |
| Categorías | las que muestra la terminal |
| Tasa | código, tasa y `date_sync` de la tasa vigente |
| Modos de pago | los de la terminal, con cuenta, moneda, si es efectivo y si exige referencia (`posplus_mode_needs_reference`) |
| Ticket | encabezado y pie de TakePOS, datos de la empresa, nombre del documento, cliente genérico |
| Cajeros | usuario y hash del PIN offline |
| Control | fecha de la copia, versión, turno abierto |

Si la copia tiene más de `POSPLUS_OFFLINE_MAX_AGE` horas (12 por defecto), la pantalla offline no deja vender.

## Venta offline

1. Entrada con el PIN del cajero (hash PBKDF2 calculado en el navegador; el PIN se define por usuario en POSPlus).
2. Búsqueda por nombre, ref o código de barras. Precio = `price_ttc` de la copia; Bs = precio × tasa, redondeado a céntimos (el mismo cálculo que el listado de TakePOS).
3. Cobro con las reglas del popup Pagar: cada caja en su moneda, mezcla de $ y Bs, vuelto, referencia obligatoria en lo que no es efectivo.
4. Ticket con el formato de POSPlus, armado en el navegador, marcado **COMPROBANTE PROVISIONAL – SIN CONEXIÓN** y con número provisional `OFF-T1-AAAAMMDD-NNN`.
5. La venta queda en cola con un UUID, la hora del operador y la tasa usada.

## Sincronización (`offline/sync.php`)

El navegador envía las ventas de una en una, en orden. Antes pide un token CSRF nuevo; si la sesión expiró durante el corte, abre el login y continúa después.

Por cada venta, en una sola transacción:

1. **Sin duplicados:** si el UUID ya está en `llx_posplus_offline_sale`, se devuelve la factura ya creada.
2. **Comprobar los datos del navegador:** la tasa debe existir en `llx_multicurrency_rate` y ser la vigente en el momento de la venta (tolerancia de 2 horas); el precio de cada línea debe ser el actual del producto o uno de su historial reciente (`llx_product_price`); si no, la venta pasa a revisión.
3. **Crear la factura** como TakePOS (`module_source = takepos`, `pos_source` = terminal), con la fecha de la venta, la moneda y la **tasa de la venta**, y las líneas al precio TTC vendido.
4. **Validar y cobrar** con `PosplusPayment` (`validateInvoice` y `addPayment`, con `datepaye` = hora de la venta): numeración definitiva, stock, pagos por caja, referencia, vuelto, cierre.
5. **Registrar** UUID ↔ factura ↔ turno y hacer el commit.

Si algo falla, la venta queda en estado error con el motivo y la cola sigue. La página "Ventas offline" de POSPlus lista pendientes, sincronizadas y con error, permite reintentar y reimprimir el ticket definitivo.

## Base de datos (MySQL y PostgreSQL)

```sql
llx_posplus_offline_sale (
  rowid, entity,
  uuid          varchar(36),  -- índice único
  terminal      varchar(16),
  fk_user       integer,
  fk_session    integer,      -- turno de POSPlus
  fk_facture    integer,      -- NULL hasta sincronizar
  offline_ref   varchar(32),  -- OFF-T1-20261103-001
  date_sale     datetime,
  payload       text,         -- la venta tal como llegó (auditoría)
  status        smallint,     -- 0 pendiente, 1 sincronizada, -1 error
  error         text,
  datec, tms
)
```

PIN offline: `llx_posplus_offline_pin (fk_user, pin_hash, salt)` o un extrafield de usuario.

## Arqueo

- La venta offline se asigna al turno abierto de la terminal y entra en sus totales al sincronizarse.
- El cierre Z se bloquea mientras el navegador tenga ventas en cola.
- Sin turno abierto, la pantalla offline no deja vender.

## Opciones

| Opción | Por defecto |
|---|---|
| `POSPLUS_OFFLINE` | No |
| `POSPLUS_OFFLINE_MAX_AGE` (horas) | 12 |
| `POSPLUS_OFFLINE_REFRESH` (minutos) | 5 |

## Fases

| Fase | Versión | Contenido |
|---|---|---|
| 0 | 1.2.6 ✔ | Registro del cobro en `PosplusPayment`, compartido por el popup y la sincronización |
| 1 | 2.0 | `snapshot.php`, IndexedDB, service worker, detección del corte, botón "Venta offline" |
| 2 | 2.0 | PIN, pantalla de venta, cobro, ticket provisional, cola |
| 3 | 2.0 | `sync.php`, tabla, comprobaciones, página "Ventas offline", bloqueo del Z |
| 4 | 2.0 | Pruebas de corte: en mitad de una venta, al refrescar, al cerrar el navegador, durante la sincronización, cola reenviada dos veces, sesión expirada |

## Riesgos aceptados

- Borrar los datos del navegador con ventas en cola las pierde: la pantalla muestra siempre "N ventas sin enviar".
- Stock no controlado durante el corte (puede quedar negativo; poco con una terminal y una hora).
- Un cambio de tasa durante el corte no afecta: cada venta conserva la tasa con la que se vendió.
