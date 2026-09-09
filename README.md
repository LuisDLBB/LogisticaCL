# LogisticaCL

Sistema logístico multitenant de 4N.

## Ramas de trabajo

- `main`: versiones aprobadas.
- `develop`: integración y pruebas en `dev.4n.cl`.
- `feature/*`: trabajo aislado de cada módulo.

## Datos

Las tablas que almacenen información propia de una empresa usarán `tenant_id`.
Los cambios de estructura de la base de datos se incorporarán mediante migraciones versionadas en este repositorio.

## Forma de trabajo

1. Crear una rama desde `develop`.
2. Desarrollar y probar el cambio en esa rama.
3. Solicitar una revisión para incorporarlo a `develop`.
4. Probar la integración en `dev.4n.cl`.
