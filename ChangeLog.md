# CHANGELOG MODULE POSPLUS FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 1.2.7

### Actualizar desde la 1.2.6

Reemplazar la carpeta `custom/posplus`. No hay cambios de tablas, permisos ni menús: no hace falta reactivar el módulo.

### Ticket: dirección de despacho y teléfonos del cliente

- Nueva sección del setup **Datos del cliente en el ticket** con dos opciones.
- **Imprimir la dirección de despacho** (apagada por defecto): bajo el nombre del cliente se imprime la dirección del contacto de envío de la factura o, si no hay, la del cliente (dirección, código postal, ciudad y estado). Apagada, el ticket queda como antes (solo la ciudad).
- **Imprimir los teléfonos del cliente** (encendida por defecto, como hasta ahora): se imprimen el fijo y el móvil del cliente y los del contacto de envío, sin repetir. Se guarda invertida (`POSPLUS_TICKET_HIDE_PHONES`) para que las instalaciones existentes sigan imprimiéndolos sin tocar nada.

## 1.2.6

### Actualizar desde la 1.2.5

Reemplazar la carpeta `custom/posplus`. No hay cambios de tablas, permisos, menús ni opciones: no hace falta reactivar el módulo.

### Cobro: lógica en una clase compartida (sin cambios visibles)

- El registro del cobro (validación de la factura, stock, pago en la moneda de cada caja, línea bancaria, vuelto, referencia obligatoria y cierre de la factura) pasa de `payplus_ajax.php` a la clase `PosplusPayment` (`class/pospluspayment.class.php`). El endpoint del popup Pagar solo comprueba el token, los permisos y la factura, y llama a la clase.
- El comportamiento es el mismo: se comparó el código anterior con el nuevo en 13 cobros (efectivo en $ con vuelto, efectivo en Bs parcial, punto de venta y pago móvil con referencia, mezcla de $ y Bs, y los errores de referencia, moneda, monto, modo, factura pagada, token y factura inexistente) con resultados idénticos en respuestas, pagos, líneas bancarias, stock y estado de la factura.
- Prepara la venta sin conexión de la 2.0, que registrará los cobros con esta misma clase (ver `docs/OFFLINE.md`).

## 1.2.5

### Actualizar desde la 1.2.4

Reemplazar la carpeta `custom/posplus`. No hay cambios de tablas, permisos, menús ni opciones: no hace falta reactivar el módulo.

### Precio en Bs del listado de productos

- El listado convierte el precio con IVA del producto (precio × tasa, redondeado a céntimos), que es el que TakePOS pone en la línea de factura. La 1.2.3 reconstruía el precio con IVA a partir del precio sin IVA, y con `MAIN_MAX_DECIMALS_UNIT` en 2 ese precio está redondeado (0,40 $ con IVA → 0,34 sin IVA → 0,34 × 1,16 ≠ 0,40): el listado mostraba 356,45 Bs en lugar de 356,44.

**Configuración necesaria:** *Máximo de decimales para precios unitarios* (`MAIN_MAX_DECIMALS_UNIT`) debe estar en 5 (valor por defecto de Dolibarr). Con 2, la línea de un producto de 0,40 $ con IVA se guarda a 0,34 sin IVA y la venta sale a 0,39 $ (y 351,45 Bs en lugar de 356,44): el error está en los dólares, no en la tasa.

## 1.2.4

### Actualizar desde la 1.2.3

Reemplazar la carpeta `custom/posplus`. No hay cambios de tablas, permisos, menús ni opciones: no hace falta reactivar el módulo.

### Cantidad repetida en la factura en pantalla

Con el stock visible en las líneas, el `takepos/invoice.php` de esta instalación imprime la cantidad al inicio de la celda y otra vez antes del stock, pegadas: 1 se veía "11" y 2 se veía "22". POSPlus corrige el texto en pantalla sin tocar el núcleo, y solo cuando es exactamente la cantidad dos veces (con un núcleo sin el defecto no cambia nada). El ticket no estaba afectado.

## 1.2.3

### Actualizar desde la 1.2.2

Reemplazar la carpeta `custom/posplus`. No hay cambios de tablas, permisos, menús ni opciones: no hace falta reactivar el módulo.

### Montos en la moneda del cliente (Bs) iguales en listado, factura y ticket

Antes, el mismo producto salía con tres montos distintos en Bs: el listado convertía el precio con IVA sin redondear, la factura en pantalla (core) convertía el total ya redondeado a céntimos de $ (2,67 $ × 889,40 = 2.374,70 Bs en lugar de 2.372,92) y ambos usaban la última tasa, mientras que el ticket usa la tasa y los montos guardados en la factura.

- La factura borrador del POS se pone a la tasa vigente cada vez que se muestra (TakePOS solo la fijaba al asignar la moneda, así que un borrador abierto antes de actualizar la tasa conservaba la anterior). Los montos en $ no cambian; los de Bs se recalculan.
- La factura en pantalla muestra los montos en Bs guardados en la factura (líneas y total), que son los que cobra el popup y los que imprime el ticket. Las facturas ya cobradas (historial) muestran los suyos, a la tasa con que se cobraron.
- El listado de productos calcula los Bs como Dolibarr en la línea de factura: precio sin IVA × tasa, redondeado, más el IVA calculado en Bs. La tasa es la misma que toma la factura (respeta `MULTICURRENCY_USE_RATE_ON_DOCUMENT_DATE`).

## 1.2.2

### Actualizar desde la 1.2.1

Reemplazar la carpeta `custom/posplus`. No hay cambios de tablas, permisos ni menús. La nueva opción funciona activada aunque no se reactive el módulo; para que aparezca marcada en la configuración, desactivar y activar el módulo una vez (o guardarla desde *Configuración*).

### Cobro (botón Pagar)

- **Referencia obligatoria**: todo cobro que no sea en efectivo (transferencia, pago móvil, punto de venta...) exige el número de confirmación en el nuevo campo *Referencia* del popup. Se considera efectivo el modo cuya cuenta configurada en TakePOS es de tipo *Caja* (LIQ, efectivo en Bs...); sin el módulo Bancos, solo LIQ. El servidor también lo valida.
- La referencia se guarda donde la guarda el core: en el pago (*Número* del pago) y en la línea bancaria (útil para la conciliación). En un cobro con pagos parciales cada pago lleva su propia referencia: el campo se vacía tras cada uno.
- El ticket imprime `Ref: …` debajo de cada pago que la tenga.
- Nueva opción *Pedir la referencia en todo cobro que no sea en efectivo* (`POSPLUS_PAYMENT_REF_REQUIRED`), activada por defecto.
- Las devoluciones (venta con total negativo) siguen el flujo del core, sin referencia.

### Efectivo en bolívares configurado al activar

TakePOS solo ofrece cuentas de tipo *Caja* a los modos de pago cuyo código empieza por `LIQ`. Con otro código (p. ej. `EFBS`) la caja en Bs no aparece en la configuración de la terminal y, si se asignó antes, la asignación se pierde al guardar esa página. Al activar el módulo, para cada moneda extranjera de Multimoneda:

- **Caja**: si no hay ninguna cuenta de tipo Caja en bolívares, se crea `CAJA<moneda>` *Efectivo Bolívares* (saldo 0). Otras monedas solo se tratan si ya tienen una caja.
- **Modo de pago** `LIQBS` (`LIQ` + código ISO en otras monedas): si un modo con otro código ya está asignado a esa caja (p. ej. `EFBS`), se renombra conservando su id y su historial, y se trasladan sus asignaciones; si no, se crea.
- **Terminales**: cada terminal sin cuenta para ese modo recibe la caja. Nunca se cambia una asignación ya hecha; si hay varias cajas en la moneda, se avisa para asignarla a mano.

La página de configuración de POSPlus muestra lo que falta y un botón *Configurar ahora* para aplicarlo sin reactivar el módulo. Requiere los módulos Multimoneda y Bancos.

## 1.2.1

### Actualizar desde la 1.2.0

Reemplazar la carpeta `custom/posplus`. No hay cambios de tablas, permisos ni menús: no hace falta desactivar y activar el módulo.

### Cobro (botón Pagar)

- **Montos en la moneda de cada caja**: el popup muestra el total y el restante en $ y en la moneda de la factura (Bs), con dos campos enlazados por la tasa de la factura (antes el campo en Bs usaba la tasa del día de la moneda del cliente, que podía no coincidir con la de la factura). Cada botón de modo indica el monto que tomará en la moneda de su caja.
- Un cobro en una caja o cuenta en Bs se envía en Bs y queda **exacto en la línea bancaria**; el core calcula el equivalente en $ con la tasa de la factura. Antes había que teclear el equivalente en $ y los Bs registrados podían diferir en céntimos.
- Si se entregan más Bs que el restante, se muestra el **vuelto a entregar** antes de registrar; se cobra solo el restante.
- La factura se cierra cuando el restante queda en cero en cualquiera de las dos monedas (evita que quede abierta por un céntimo de redondeo).
- Se rechaza un monto en Bs que vale menos de 0,01 $ (dejaba una línea bancaria sin importe aplicado a la factura) y un monto en una moneda distinta a la de la cuenta del modo.
- Se controla el resultado de cerrar la factura como pagada.

### Arqueo de caja

- Dos aperturas de turno al mismo tiempo en la misma terminal (doble clic, dos pestañas, dos cajeros) ya no crean dos turnos abiertos: la segunda espera a la primera y se rechaza.
- **Arqueo ciego**: el ticket del turno abierto (con lo esperado calculado al momento) solo lo ve quien tiene el permiso *Ver el historial de arqueos*; antes un cajero podía verlo escribiendo la dirección.
- El ticket del turno abierto ya no muestra avisos PHP.

### Otros

- `build/buildzip.php`: en Windows las rutas del zip se guardan con `/` (con `\` el zip se descomprimía mal en Linux).

## 1.2.0

Requiere Dolibarr 23 o superior (probado en 24.0.2) y PHP 7.2 o superior.

### Actualizar desde la 1.1

1. Reemplazar la carpeta `custom/posplus`.
2. **Desactivar y volver a activar el módulo** (Inicio → Configuración → Módulos): crea las tablas del arqueo, los permisos y el menú. La configuración del módulo ya no se borra al desactivarlo.
3. Dar los permisos nuevos a quien corresponda: *Ver el historial de arqueos* y *Hacer el cierre del día (Z)*.
4. Las cuentas de efectivo en otra moneda (p. ej. efectivo en Bs) deben ser de tipo **Caja**.
5. Recomendado: fijar `MAIN_SERVER_TZ` a la zona del negocio (p. ej. `America/Caracas`), para que el core guarde la fecha de las facturas con el día local aunque el hosting esté en otro país.

### Arqueo de caja por turnos (nuevo)

- **Apertura de turno** con el fondo de cada caja de la terminal (USD, Bs...), un monto por caja. Sin turno abierto no se puede cobrar. Sustituye a la apertura nativa de TakePOS (de una sola moneda) y se abre sola al entrar al POS si no hay turno.
- **Retiros e ingresos de efectivo** desde el POS, con motivo; se registran como movimiento de la caja y entran en el cálculo.
- **Arqueo X** (cierra el turno) y **cierre Z** (cierra el día): por cada caja, fondo + cobros − devoluciones + ingresos − retiros = esperado, frente a lo contado (un monto por caja); por cada medio no efectivo (tarjeta, Pago Móvil, punto...), esperado frente a declarado. Diferencia (sobrante / faltante / cuadrado) por línea y por moneda.
- **Arqueo ciego** (opcional, activo por defecto): el cajero no ve lo esperado; las diferencias se muestran al cerrar.
- Cada turno cuenta desde el cierre del anterior hasta su cierre, así ningún pago se pierde ni se cuenta dos veces. Solo cuentan los pagos de las ventas y devoluciones de la terminal.
- Las **ventas del día del cierre Z** se separan por moneda de la factura (USD en $, VEF en Bs), cada una con sus devoluciones y su neto.
- El **cierre Z** valida el control de caja nativo del día con el **día local**, que es el que TakePOS usa para bloquear los cobros (el core lo guardaba en UTC y el bloqueo no coincidía con el día). Después del Z no se puede abrir otro turno ese día.
- **Ticket de 80 mm** del arqueo con el detalle por caja y medio, los movimientos, las diferencias, las ventas del día (en el Z) y firmas de cajero y supervisor.
- **Historial** en Bancos/Cajas → *Arqueos de caja POS*: filtros por caja, fecha y estado, diferencias por moneda, ficha y reimpresión.
- Tablas nuevas `llx_posplus_cashsession`, `llx_posplus_cashsession_line` y `llx_posplus_cashmovement` (MySQL y PostgreSQL).

### Cobro (botón Pagar)

- POSPlus registra todos los pagos, también el que completa la factura (con el vuelto en efectivo `LIQ` y el movimiento de stock por lotes, como el core); luego el POS muestra la factura pagada e imprime el ticket si `TAKEPOS_AUTO_PRINT_TICKETS` está activo. Las ventas con total negativo siguen por el core (abono).
- **Efectivo en varias monedas**: un modo de pago es efectivo cuando su cuenta es de tipo **Caja**, sea cual sea su código o moneda (`LIQ` → Caja USD, `EFBS` → Caja Bs). La línea bancaria se registra como `LIQ` (regla del core para las cajas) y el pago conserva su modo real. Ya no hace falta una cuenta corriente para cuadrar el efectivo en Bs.
- **Monedas**: el importe se envía en moneda principal y lo convierte el servidor con la tasa de la factura. Solo se aceptan cuentas en la moneda principal o en la de la factura (antes se podían registrar Bs como USD). Al primer pago, una factura en moneda principal pasa a la moneda extranjera de la terminal con la tasa del día (como al elegir la moneda del cliente en TakePOS), así se pueden mezclar $ y Bs en cualquier orden.
- Los modos que no se pueden usar se muestran **deshabilitados con el motivo** (sin turno abierto, falta la tasa del día, varias monedas extranjeras en la terminal).
- Los modos creados por el usuario sin traducción (`EFBS`, `PM`...) muestran la etiqueta del diccionario.

### Ticket de venta

- **Formato de recibo fiscal** para impresora de 80 mm: RIF, nombre y dirección de la empresa; RIF/CI, razón social, ciudad y teléfono del cliente; operador, caja y condición (contado/crédito); nombre del documento configurado en TakePOS (`TAKEPOS_RECEIPT_NAME`, p. ej. "NOTA DE DESPACHO") con su número; fecha y hora de la venta; renglones "cant. x precio" + descripción con marca de impuesto (E exento, G general, R reducido) e importe sin IVA; SUBTTL, EXENTO, BI/IVA por alícuota; pagos en la moneda de la caja o cuenta donde entraron, con el cambio; TOTAL. Las etiquetas son traducibles.
- Contador de impresiones y DUPLICADO (con la fecha de reimpresión), ticket provisional para borradores, ticket sin detalle y ticket de regalo, como el core.

### Fechas y horas

- Las horas mostradas e impresas están en la **zona horaria del operador** (la que informa su navegador al iniciar sesión), no en la del hosting, en formato 12 h AM/PM. Sin zona del operador en la sesión se usa la del servidor.
- Las fechas de calendario (fecha de la factura, fecha bancaria) se muestran y filtran tal cual, sin convertir zona, para que el día no se corra.

### Cierre de caja (resumen del día)

- **Separado por moneda de la factura** (como el cierre Z): facturas emitidas, devoluciones, total neto y pendientes por cobrar se muestran en la moneda de cada factura (facturas en USD en $, facturas en VEF en Bs con sus importes en Bs).
- Nueva sección **Cobrado por moneda**: lo que entró en cada moneda entre todos los medios de pago; un pago mixto ($ + Bs) suma cada parte en su moneda.
- Desglose por modo de pago y cuenta, y sección **Efectivo por moneda** con lo que debe haber en cada caja.
- Excluye facturas abandonadas; pendientes calculados como el core (descuenta abonos y anticipos aplicados); un pago que cubre varias facturas ya no se cuenta dos veces; consulta compatible con PostgreSQL.

### Seguridad

- XSS: el nombre y el RIF del cliente se insertaban como HTML en la cabecera del POS; el ticket y el cierre de caja imprimían datos de clientes y productos sin escapar.
- Cobro: token CSRF obligatorio; solo facturas TakePOS de la terminal y de una entidad del usuario; la cuenta bancaria es la configurada en TakePOS para el modo (la enviada por el navegador solo se acepta con `TAKEPOS_CAN_FORCE_BANK_ACCOUNT_DURING_PAYMENT`); se rechaza si la caja del día está cerrada; un importe ≤ 0 se rechaza (antes cobraba todo el saldo); la factura se bloquea durante el cobro para que un doble envío no cobre dos veces; ya no se cobran facturas abandonadas.
- Los botones desactivados en la configuración (precio, descuento de línea, descuento de factura, zona libre) también se **rechazan en el servidor**, no solo se ocultan.

### Otros

- Configuración: se quitan los parámetros de ejemplo de ModuleBuilder.
- Versión en formato `x.y.z`; versión mínima de Dolibarr corregida (declaraba la 19).

## 1.1

- Cierre de caja: ahora contabiliza las **devoluciones** de ventas del POS.
  - Nueva sección "Devoluciones" con el detalle (abono, factura origen, cliente con CI/RIF e importe) y su total.
  - "Facturas emitidas" excluye los abonos (`f.type != 2`) y el **Total neto** (ventas − devoluciones) se muestra **después de las devoluciones**, para que el reporte lea en orden: facturado → devoluciones → neto → desglose de pagos.
  - El desglose de pagos muestra por modo y moneda **Entradas**, **Salidas** y **Total** (más el equivalente en la moneda principal cuando la cuenta tiene otra moneda). El reverso de la devolución entra como salida, de modo que el modo queda neto en su canal y moneda original.
  - Las devoluciones se atribuyen a la terminal por una **marca de origen** que pone EasyBack al crear el abono desde el POS (`module_source = 'easyback'`, `pos_source = <terminal>`). Así el cierre cuenta **solo las devoluciones hechas desde el punto**; las hechas desde la ficha (Finanzas/backoffice) quedan fuera de las transacciones del POS. No requiere cambios en el core.
  - Al marcarse con `module_source = 'easyback'` (no `takepos`) el abono **conserva su numeración de abono** (`IC...`), no entra en el numerador de ventas del POS.

## 1.0

Initial version
