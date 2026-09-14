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
- La cobertura definirá si el servicio se paga a una agencia regional o a un repartidor externo de Santiago.
- Los pagos requieren respaldo operacional, motivo, fecha, valor, condición de pago y documento tributario antes de su envío a Finanzas.

## Pendientes y riesgos

- Definir la regla equivalente para repartidores externos en RM.
- Validar tabla de tarifas, tramos de peso, vigencias, adicionales y redondeos.
- Definir catálogo de estados de aprobación y responsables por monto.
- Definir la evidencia mínima por tipo de servicio.
- No crear tablas de pago hasta validar estos puntos.
