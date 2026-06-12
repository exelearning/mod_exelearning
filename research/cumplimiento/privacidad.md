# Privacidad — mod_exelearning

Objetivo: cumplir **GDPR** y la normativa española aplicable a menores.

Estado: el **privacy provider está implementado por completo**. La fuente de verdad es
`classes/privacy/provider.php`; este documento describe el comportamiento real del código,
no una hipótesis.

## Datos personales almacenados (estado actual)

El plugin guarda datos personales en una única tabla, `exelearning_attempt` (historial de
intentos plano, DEC-0007). `provider::get_metadata()` la declara campo a campo y añade un
enlace al subsistema `core_grades`, porque el plugin publica las puntuaciones de cada usuario
en el libro de calificaciones de Moodle mediante `grade_update()`.

Campos declarados en los metadatos (`exelearning_attempt`, ver `db/install.xml`):

| Campo | Significado |
|---|---|
| `userid` | Usuario al que pertenece el intento. |
| `attempt` | Número de intento (base 1) por usuario y actividad. |
| `itemnumber` | `0` = nota global agregada; `>0` = iDevice calificable. |
| `rawscore` | Puntuación bruta. |
| `maxscore` | Puntuación máxima. |
| `scaledscore` | `rawscore/maxscore` en el rango `0..1`. |
| `status` | `completed` / `passed` / `failed` / `incomplete`. |
| `timecreated` / `timemodified` | Marcas de tiempo. |

Además, `provider::get_metadata()` declara el campo `usermodified` de la tabla `exelearning`
(qué usuario editó por última vez la configuración de la actividad) y el enlace al subsistema
`core_grades` para el flujo hacia el libro de calificaciones.

`sessiontoken` existe en la tabla pero **no** se exporta: es un token de correlación por carga
de página, no identificativo del usuario.

## Interfaces implementadas

`provider` implementa los tres interfaces del Privacy API
(`core_privacy\local\metadata\provider`, `core_privacy\local\request\core_userlist_provider`,
`core_privacy\local\request\plugin\provider`):

- **Metadatos**: `get_metadata()` declara `exelearning_attempt`, el `usermodified` de
  `exelearning` y el enlace a `core_grades`.
- **Búsquedas inversas**: `get_contexts_for_userid()` (contextos con datos del usuario) y
  `get_users_in_context()` (usuarios con datos en un contexto), ambas sobre
  `exelearning_attempt` unido a `course_modules`/`context`.
- **Exportación**: `export_user_data()` recorre los contextos de módulo aprobados, lee los
  intentos del usuario ordenados por `attempt, itemnumber` y los escribe con
  `writer::with_context()`. Las marcas de tiempo se normalizan con
  `\core_privacy\local\request\transform::datetime()`.
- **Borrado** (tres puntos de entrada, todos recalculan el libro de calificaciones tras borrar
  los intentos, vía `clear_grades_for_users()` → `exelearning_recalculate_user_grades()`, para
  que ningún usuario borrado conserve una nota sin intento que la respalde):
  - `delete_data_for_all_users_in_context()` — borra todos los usuarios de un contexto.
  - `delete_data_for_user()` — borra un usuario en los contextos aprobados.
  - `delete_data_for_users()` — borra un conjunto de usuarios en un contexto.

## Relación con el libro de calificaciones

Las notas no se almacenan como dato propio del plugin: se derivan de `exelearning_attempt` y se
publican en `core_grades` mediante `grade_update()`. Por eso el provider declara el enlace al
subsistema `core_grades` (es Moodle core quien gestiona la exportación/borrado de las notas) y
cada ruta de borrado del plugin recalcula la nota para no dejar residuos.

## xAPI — roadmap, no es dato actual

El plugin **no** almacena statements xAPI hoy. El tracking actual es 100% shim SCORM 1.2 y la
ingesta xAPI es un plan (`docs/xapi-integration-plan.md`, DEC-0032), pendiente de PR2/TAREA-015.
Cuando se implemente, las decisiones de privacidad a tomar serán:

- Si se persisten statements completos (con `result.response`) o solo el resumen
  `(verb, scaled)` necesario para calificar.
- El diseño anonimiza el `actor` en el cliente y atribuye el intento a `$USER` en el servidor,
  por lo que el provider de privacidad seguiría operando sobre columnas normalizadas
  (`exelearning_attempt`), no sobre JSON crudo. Una eventual tabla de auditoría
  (`exelearning_tracking_events`) tendría que añadirse a los metadatos en ese momento.

## Datos de menores

Moodle se despliega habitualmente en centros con menores. Implicaciones que el diseño respeta:

- No se envían datos a servicios externos por defecto (no hay LRS externo; está fuera de
  alcance, `docs/xapi-integration-plan.md` §7).
- El emisor xAPi previsto nunca expone `actor.mbox`: usa `actor.account` con `homePage` de
  Moodle (cuando exista la ruta xAPI).
- Política de retención: documentar si se borran los intentos al final del curso académico
  (responsabilidad del administrador del centro, no del plugin).

## Pendientes

- [PENDIENTE] Confirmar la política de logs de Moodle cuando se implemente xAPI: si
  `statement.id` u otros identificadores aparecen en logs con datos personales.
- [PENDIENTE] Al implementar xAPI (PR2), ampliar `get_metadata()` y las rutas de
  exportación/borrado si se añade una tabla de auditoría de statements.
