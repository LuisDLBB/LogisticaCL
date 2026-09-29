# Regla permanente: pase a producción

Hasta que el usuario diga **“Martina”**, todo desarrollo y prueba de LogisticaCL debe permanecer exclusivamente en localhost. No modificar producción ni dev.4n.cl, ni ejecutar deploy por iniciativa propia.

La palabra **“Martina”** autoriza únicamente a iniciar el proceso de preparación del pase a producción. No autoriza el deploy. Al recibirla:

1. Detener la implementación y preparar un checklist de producción.
2. Mostrar la rama actual, `git status`, archivos modificados, commits pendientes, migraciones nuevas, resultado de pruebas, respaldo requerido de la base de datos, variables y configuración necesarias en producción, impacto esperado y plan de rollback.
3. No mostrar ni publicar secretos.
4. No modificar producción todavía.
5. Esperar una segunda confirmación final y explícita del usuario para ejecutar el pase a producción.

Solo después de esa segunda confirmación se podrá ejecutar el pase a producción.
