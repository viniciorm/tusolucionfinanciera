# CRM en preparación

Esta carpeta contiene la interfaz de demostración existente y componentes PHP preparatorios para una aplicación Laravel independiente. No es un CRM funcional ni está lista para producción.

- `interfaz/`: interfaz navegable sin autenticación ni datos reales; reutiliza el logotipo original del repositorio.
- `preparacion/src/`: normalización de hojas, validación de lotes y regla preliminar de acceso.
- `preparacion/database/`: migración no integrada ni probada en Laravel/MySQL.
- `preparacion/tests/`: pruebas unitarias independientes de PHP.

La implementación prevista usará Google OpenID Connect directamente con Laravel, base de datos propia y lectura de Sheets sin modificar su origen. Autenticación, autorización completa, persistencia y sincronización aún no están implementadas.

El repositorio es público: no subir usuarios reales, IDs de planillas, archivos de entorno, datos de clientes ni credenciales. No desplegar ni mezclar esta carpeta con la web existente hasta completar la implementación y pruebas.
