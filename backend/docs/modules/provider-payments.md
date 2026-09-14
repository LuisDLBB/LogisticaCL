# Pago a Proveedores

## Responsables

- Responsable funcional: Luis de la Barra.
- Responsable técnico: Pendiente de definición conjunta con Hans de la Barra.
- Fecha de última revisión: 14 de septiembre de 2026.

## Propósito

Controlar los servicios ejecutados por agencias, repartidores externos y otros proveedores; calcular montos, reunir evidencia, gestionar aprobaciones y dejar el pago trazable.

## Usuarios

- Operaciones: valida ejecución, adicionales y evidencia.
- Finanzas: revisa documentos, programa y registra pagos.
- Gerencia: aprueba excepciones o montos fuera de regla.
- Agencias y proveedores: entregan evidencia según el acceso que se defina.

## Dependencias

- Maestros requeridos: empresas, clientes, coberturas, tipos de servicio, proveedores/agencias y usuarios.
- Módulos relacionados: operación, trazabilidad, última milla, facturación y finanzas.
- Integraciones externas: pendientes de definición.

## Reglas de negocio validadas

- Para pagos en regiones, la base inicial indicada es: RUT proveedor + RUT cliente + tipo de servicio + peso.
- Cada proveedor queda clasificado como `RM` o `Regiones` mediante `TipoOperador`; la clasificación se utilizará al resolver las reglas de pago.
- La cobertura definirá si el servicio se paga a una agencia regional o a un repartidor externo de Santiago.
- Al construir la base origen de pagos, las coberturas se procesarán en tres grupos independientes: `RM`, `Temuco` y `Regiones`.
- Para las coberturas de `RM` y `Temuco`, el RUT de razón social que se usará en la base origen de pagos será el de 4 Nortes. La regla se implementará cuando se defina ese proceso de carga.
- Los pagos requieren respaldo operacional, motivo, fecha, valor, condición de pago y documento tributario antes de su envío a Finanzas.

## Pendientes y riesgos

- Definir la regla equivalente para repartidores externos en RM.
- Validar tabla de tarifas, tramos de peso, vigencias, adicionales y redondeos.
- Definir catálogo de estados de aprobación y responsables por monto.
- Definir la evidencia mínima por tipo de servicio.
- No crear tablas de pago hasta validar estos puntos.

## Calidad de datos detectada

- Los registros `No Aplica`, `Planta`, `N/A` y RUT con valor `0` no son proveedores pagables y no deben importarse a este maestro.
- Existen RUT con dígito verificador en minúscula, sin guion o sin dígito verificador; deben normalizarse antes de cargar.
- El titular de la cuenta bancaria puede ser distinto de la razón social del proveedor; por eso las cuentas se registran separadamente.
- El campo `TipoOperador` ya separa RM y Regiones. Las categorías más específicas, como Agencia o Courier Stgo, aún aparecen dentro del nombre del operador y deben normalizarse antes de usarse para cálculos adicionales.
- Los datos de contacto están mayoritariamente vacíos y deberán completarse o validarse antes de habilitar notificaciones.
