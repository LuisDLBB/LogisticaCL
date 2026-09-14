# Documentación de LogisticaCL

Cada módulo debe mantener un documento en `docs/modules/` antes de incorporar tablas, pantallas o reglas de negocio.

## Información obligatoria

- Propósito y responsable del módulo.
- Usuarios que lo utilizarán.
- Alcance actual y funcionalidades fuera de alcance.
- Maestros y módulos de los que depende.
- Reglas de negocio validadas.
- Decisiones pendientes y riesgos.
- Tablas involucradas y trazabilidad requerida.

## Controles de consistencia

Antes de crear o modificar una tabla, se revisará que:

1. Los datos propios de una empresa estén relacionados con `tenant_id`.
2. Las claves de negocio sean únicas y no se duplique información de maestros.
3. Las relaciones apunten a tablas existentes y estén ordenadas en las migraciones.
4. Las tarifas, pagos y condiciones tengan vigencia, responsable y registro histórico.
5. Los cambios críticos mantengan auditoría y no eliminen información utilizada.
6. La definición del módulo coincida con las pantallas de Sites y con las reglas del backend.

Las inconsistencias se documentarán en el módulo afectado antes de continuar con su implementación.
