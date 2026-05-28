# Privacidad — mod_exelearning

Objetivo: cumplir **GDPR** y la normativa española aplicable a menores.

## Datos personales gestionados (hipótesis)

- Identificador de usuario Moodle (`userid`).
- Statements xAPI con `actor` resoluble a usuario Moodle (vía `core_xapi`).
- Tabla `mdl_exelearning_attempt` con `userid`, `gradeitemid`, `scaled`, `raw`,
  `timecreated`.

## Decisiones a tomar

- DEC futura: ¿se almacenan statements xAPI completos (con `result.response`) o sólo el
  resumen `(verb, scaled)` necesario para calificar?
- DEC futura: ¿se permite borrado del intento por el alumno sin perder la nota?

## Implementación requerida

- `classes/privacy/provider.php` debe declarar:
  - `metadata` (qué se almacena, finalidad, base legal).
  - `get_users_in_context()`.
  - `export_user_data()`.
  - `delete_data_for_user()`, `delete_data_for_all_users_in_context()`.
- Especial cuidado con statements en `core_xapi_state` que pertenezcan al componente
  `mod_exelearning`: provider debe exportarlos/borrarlos vía `core_xapi`.

## Datos de menores

Moodle suele desplegarse en centros educativos con menores. Implicaciones:

- No enviar datos a servicios externos por defecto (ningún LRS externo en v1).
- No exponer `actor.mbox` por defecto (usar `actor.account` con `homePage` Moodle).
- Documentar política de retención: ¿se borran intentos al final del curso académico?

## Pendientes

- [PENDIENTE] Confirmar política de logs de Moodle: ¿`statement.id` aparece en logs
  con datos personales?
- [PENDIENTE] Diseño del privacy provider tras DEC-0004.
