# Plan de mejora tras auditoria de `main`

Fecha: 2026-06-12  
Rama revisada: `main`  
HEAD revisado: `59045e3` (`Add Moodle global search integration for eXeLearning activities (#70)`)

Nota de alcance: el PR #69 (`feature/completion-status`) no estaba mezclado en `main` durante esta revision, por lo que sus cambios de finalizacion personalizada no se evaluan como parte del estado actual de `main`.

## Verificacion realizada

- `php -l` sobre los PHP del plugin: correcto.
- `python3 research/tools/test_schema_validation.py`: correcto.
- `git diff --check`: correcto.
- `vendor/bin/phpcs --standard=moodle` excluyendo `vendor`, `dist/static` y `exelearning`: falla solo por un PHP experimental bajo `research/experimentos/...`, no por codigo productivo.

## Prioridad 1

### 1. Endurecer extraccion de `.elpx` embebido al migrar desde `mod_exescorm`

**Evidencia**

- `classes/local/migration/source/exescorm_source.php:174`
- `classes/local/zip_utils.php`

**Problema**

La migracion desde `mod_exescorm` extrae una entrada `.elpx` concreta desde un ZIP legacy sin validar previamente el nombre de la entrada con las mismas reglas de seguridad que usa el extractor general. El extractor general ya rechaza rutas absolutas, `..`, backslashes, protocolos y otros casos inseguros, pero este camino concreto no reutiliza esa defensa antes de llamar a `extract_to_pathname()`.

**Impacto**

Riesgo de path traversal o comportamiento no deseado durante una migracion site-wide ejecutada por administracion. El riesgo practico es limitado porque el origen es contenido legacy administrado, pero la migracion deberia aplicar el mismo modelo defensivo que el resto del plugin.

**Propuesta**

- Rechazar entradas `.elpx` inseguras en `exescorm_source::classify()` o `resolve_elpx()`.
- Reutilizar `zip_utils::is_unsafe_zip_entry()`.
- Anadir tests con entradas como:
  - `../evil.elpx`
  - `/absolute.elpx`
  - `folder\evil.elpx`
  - `file://evil.elpx`
- Mantener la semantica actual para ZIPs validos con un unico `.elpx` embebido.

### 2. No continuar tracking sin lock cuando falla la adquisicion

**Evidencia**

- `classes/local/track.php:154`
- `classes/local/track.php:283`
- `classes/local/attempts.php:198`

**Problema**

El flujo de tracking usa un lock para proteger la asignacion de intentos y evitar carreras en `MAX(attempt) + 1`. Si el lock no se consigue, el codigo continua en modo degradado sin proteccion. Eso reintroduce la carrera que el lock intenta evitar.

**Impacto**

En concurrencia real, dos commits simultaneos pueden intentar crear el mismo numero de intento o pisarse de forma dificil de reproducir.

**Propuesta**

- Si no se obtiene el lock, devolver un error controlado y reintentable.
- Para endpoint web, responder con estado semantico claro y mensaje breve.
- Para API externa/mobile, lanzar excepcion Moodle apropiada.
- Registrar `debugging()` o log tecnico para diagnosticar saturacion de locks.
- Anadir test que simule fallo de lock y compruebe que no se escribe sin proteccion.

### 3. Centralizar comprobacion de extraccion valida del paquete

**Evidencia**

- `classes/local/package_manager.php:179`
- `classes/local/migration/migration_service.php:253`

**Problema**

`package_manager::extract_stored()` llama a `extract_to_storage()` y despues continua con inyecciones/parches. La migracion ya contiene una defensa local porque `extract_to_storage()` puede fallar o dejar un area de contenido no util debido a shims. Esa comprobacion deberia vivir en el extractor central.

**Impacto**

Una subida o reextraccion fallida podria dejar el modulo en un estado aparentemente poblado pero sin `index.html` servible.

**Propuesta**

- Hacer que `package_manager::extract_stored()` verifique explicitamente:
  - resultado de `extract_to_storage()`;
  - existencia de `index.html` en el area de contenido;
  - existencia de `content.xml` si aplica al flujo.
- Lanzar excepcion clara si la extraccion no genera un paquete servible.
- Reutilizar esta defensa desde migracion y eliminar duplicacion.
- Anadir tests de paquete corrupto o ZIP sin `index.html`.

## Prioridad 2

### 4. Sustituir lock por configuracion en el instalador del editor embebido

**Evidencia**

- `classes/local/embedded_editor_installer.php:835`

**Problema**

El instalador del editor usa `get_config()` y `set_config()` como lock. Ese patron no es atomico: dos peticiones simultaneas pueden leer que no hay lock y ejecutar instalacion a la vez.

**Impacto**

Riesgo de instalaciones concurrentes, renombres cruzados o estado parcial del directorio del editor.

**Propuesta**

- Usar `\core\lock\lock_config::get_lock_factory('mod_exelearning')`.
- Crear un lock especifico para instalacion/actualizacion/borrado del editor.
- Mantener mensaje de error comprensible si otra instalacion esta en curso.
- Cubrir con test unitario del camino de lock no disponible si es viable.

### 5. Reducir superficie del Service Worker del editor embebido

**Evidencia**

- `editor/static.php:143`
- `editor/index.php:219`
- `docs/EMBEDDED_EDITOR.md`

**Problema**

`preview-sw.js` se sirve con `Service-Worker-Allowed: /`, lo que permitiria un scope raiz sobre el origen Moodle si algun flujo futuro registrase el service worker. Al mismo tiempo, `editor/index.php` intenta impedir el registro de ese service worker.

**Impacto**

Superficie innecesaria sobre un origen sensible. Aunque el registro esta bloqueado por el bootstrap actual, la cabecera amplia no aporta valor y puede volverse peligrosa si cambia el codigo del editor.

**Propuesta**

- Eliminar la cabecera `Service-Worker-Allowed: /`, o limitarla al scope minimo del editor.
- Mantener el bloqueo de registro en `editor/index.php`.
- Documentar la decision en `docs/EMBEDDED_EDITOR.md`.
- Anadir una comprobacion sencilla de que `preview-sw.js` no se registra desde el bootstrap Moodle.

### 6. Homogeneizar chequeo de contencion de rutas en `editor/static.php`

**Evidencia**

- `editor/static.php:123`
- `editor/styles.php:104`

**Problema**

`editor/static.php` comprueba contencion con `strpos($filepath, $staticroot) !== 0`. `editor/styles.php` ya usa una variante mas estricta con separador de directorio, evitando falsos prefijos.

**Impacto**

Riesgo bajo, porque el path se limpia y el instalador ya filtra entradas inseguras, pero conviene usar el mismo patron robusto en ambos endpoints.

**Propuesta**

- Cambiar la comprobacion para aceptar solo:
  - `$filepath === $staticroot`
  - o `strpos($filepath, $staticroot . DIRECTORY_SEPARATOR) === 0`
- Anadir test de ruta con prefijo comun si el endpoint tiene cobertura.

## Prioridad 3

### 7. Actualizar documentacion tecnica tras el refactor de #71

**Evidencia**

- `docs/GRADEBOOK.md`
- `docs/TRACKING.md`
- `docs/ELPX_PACKAGE.md`
- `docs/EMBEDDED_EDITOR.md`
- `docs/PRIVACY_BACKUP_FILES.md`
- `docs/scorm-shim-current-flow.md`
- `docs/xapi-integration-plan.md`

**Problema**

Tras extraer logica desde `lib.php` a clases dedicadas, varias referencias de linea y algunas rutas documentales quedaron obsoletas. La arquitectura conceptual sigue siendo mayoritariamente valida, pero la evidencia apunta a ubicaciones antiguas.

**Impacto**

La documentacion pierde valor como base de investigacion y revision. Tambien aumenta el coste de futuras auditorias porque obliga a redescubrir rutas ya documentadas.

**Propuesta**

- Sustituir referencias fragiles a rangos de linea por nombres de clase/metodo cuando sea posible.
- Actualizar rutas hacia:
  - `classes/grades/grade_sync.php`
  - `classes/grades/grade_recalculator.php`
  - `classes/grades/completion_validator.php`
  - `classes/local/package_manager.php`
  - `classes/local/scorm/scorm_injector.php`
  - `classes/local/scorm/idevice_patch.php`
  - `classes/local/ui/teacher_mode_hider.php`
- Mantener lineas concretas solo cuando sean imprescindibles como evidencia.

### 8. Reescribir `research/cumplimiento/privacidad.md` con estado real

**Evidencia**

- `research/cumplimiento/privacidad.md`
- `classes/privacy/provider.php`
- `db/install.xml`

**Problema**

El documento sigue hablando en modo hipotesis y menciona estructuras antiguas de intentos/xAPI. El plugin ya tiene provider de privacidad real y xAPI no esta implementado todavia.

**Impacto**

Documento de cumplimiento potencialmente enganoso: peor que una tarea pendiente, porque mezcla estado historico con estado actual.

**Propuesta**

- Convertirlo en una ficha de cumplimiento actual:
  - datos almacenados en `exelearning_attempt`;
  - exportacion implementada;
  - borrado por usuario/contexto implementado;
  - relacion con gradebook via Moodle core;
  - xAPI como hoja de ruta, no como dato actual.
- Citar `classes/privacy/provider.php` como implementacion fuente.

### 9. Reconciliar `research/cumplimiento/licencias.md`

**Evidencia**

- `research/cumplimiento/licencias.md`
- `thirdpartylibs.xml`
- `scripts/package.sh`
- `docs/EMBEDDED_EDITOR.md`

**Problema**

El documento mantiene varios `PENDIENTE` sobre licencias de repos hermanos, editor embebido y dependencias, aunque parte de ese trabajo ya esta reflejado en `thirdpartylibs.xml` y en el empaquetado.

**Impacto**

La situacion legal/documental queda menos clara de lo que esta en el codigo.

**Propuesta**

- Separar explicitamente:
  - resuelto;
  - pendiente de auditoria legal;
  - no aplica;
  - diferido.
- Documentar como se declara el editor embebido en el ZIP de release.
- Verificar que el resultado del paquete contiene los metadatos esperados.

### 10. Corregir comentario contradictorio en importacion de estilos

**Evidencia**

- `classes/local/styles_service.php:251`
- `classes/local/styles_service.php:261`

**Problema**

El comentario/docblock indica que el valor por defecto bloquea importaciones, pero el codigo las permite por defecto.

**Impacto**

Confusion para mantenedores y revisores de seguridad.

**Propuesta**

- Decidir si el default correcto es permitir o bloquear.
- Corregir comentario y docblock para reflejar la decision.
- Si se cambia comportamiento, anadir upgrade o nota de compatibilidad.

### 11. Ajustar `composer lint` o el experimento PHP de `research`

**Evidencia**

- `composer.json`
- `research/experimentos/resultados/EXP-002-screenshots/cli_create_test_instance.php`

**Problema**

El script local `composer lint` ejecuta `phpcs --standard=moodle .`. Con artefactos externos excluidos, sigue fallando por un PHP experimental bajo `research/experimentos/...`.

**Impacto**

Pequena friccion de DX: un comando estandar del repo puede fallar por un archivo que no forma parte del plugin productivo.

**Propuesta**

- Opcion A: excluir `research/experimentos/resultados/` del lint local.
- Opcion B: adaptar ese PHP experimental al estandar Moodle.
- Opcion C: mover resultados generados a una ruta ignorada si no deben versionarse.

## Orden recomendado de ejecucion

1. `exescorm_source`: validacion de entrada ZIP + tests.
2. `track`: fallo de lock como error reintentable.
3. `package_manager`: comprobacion centralizada de extraccion valida.
4. `embedded_editor_installer`: lock atomico con `core\lock`.
5. `editor/static.php`: Service Worker y contencion de rutas.
6. Limpieza documental post-#71.
7. Actualizacion de `research/cumplimiento`.
8. Ajuste de `composer lint` o del experimento PHP.

## Criterios de aceptacion sugeridos

- `vendor/bin/phpcs --standard=moodle <archivos tocados>` limpio.
- Tests unitarios nuevos para los cambios de seguridad/concurrencia donde sea viable.
- `php -l` sobre archivos PHP tocados.
- `python3 research/tools/test_schema_validation.py` si se toca `research`.
- Documentacion actualizada sin referencias obsoletas a lineas antiguas de `lib.php`.
