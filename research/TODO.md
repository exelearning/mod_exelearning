# TODO inmediato

Lista humana de pendientes documentales. La fuente de verdad operativa sigue
siendo `status.yaml` + `tareas/backlog/`.

## Prioridad Alta

(sin pendientes)

## Prioridad Media

- [ ] Auditorías de cumplimiento pendientes: licencias, privacidad y
      accesibilidad.
- [ ] TAREA-013 / RIE-001 (M8): investigar sandboxing de JS en cliente para mitigar
      (ShadowRealm, SES/Compartments, Web Worker + DOM proxy, QuickJS-WASM, librerías
      tipo `sandboxjs`) manteniendo el servido same-origin. Evaluar viabilidad con el
      motor eXeLearning (DOM + jQuery + pipwerks). Ver DEC-0019 (M8).

## Cerrado

- [x] TAREA-012 / RIE-001: investigación de aislamiento del `.elpx` COMPLETADA
      (DEC-0019, 2026-06-02). Core no aísla (mod_scorm sin sandbox; core_h5p curado);
      mod_exelearning ya es el mejor aislado de los tres; no hay origen separado en core
      (requiere infra). Roadmap de hardening documentado (NO implementado por decisión):
      Tier 1 (M2 Permissions-Policy + M3 CSP estricto-con-toggle + M1) → Tier 2 (M6
      postMessage bridge → M7 origen opaco/subdominio). Implementación = trabajo futuro.
- [x] TAREA-009 / RIE-011: TOCTOU de `maxattempt` ACEPTADO por paridad con core
      (ni mod_scorm ni mod_h5pactivity lo protegen; el UNIQUE ya está presente).
      Lock `\core\lock` confinado a `!sessionknown` queda como mitigación futura
      opcional. Ver DEC-0018 (revisión 2026-06-01). Riesgo baja/baja.
- [x] Fase 0: estructura, plantillas, schemas, índices, dashboard y diario.
- [x] TAREA-002: Context7 completado en FTE-001..FTE-008.
- [x] TAREA-003: EXP-001 completado.
- [x] TAREA-004: EXP-002 completado.
- [x] TAREA-005: DEC-0003 aceptada y matriz cuantificada.
- [x] TAREA-006: CI matriz Moodle/PHP/DB documentada e implementada.
- [x] TAREA-007: DEC-0015 justifica la multicalificación.
- [x] TAREA-008 / RIE-010: aplicado guard de origen al puente legacy
      `postMessage`; `amd/build/` regenerado con `grunt amd` en Moodle 5.2beta.
- [x] TAREA-010: el ZIP del editor descargado desde GitHub se verifica contra
      el digest SHA-256 publicado por GitHub Releases API.
- [x] TAREA-011: e2e real por navegador completado: `completionpassgrade`,
      `grademodel` peritem/overall, libro, `maxattempt`, `reviewmode` y puente
      iframe/SCORM verificados con Chrome + Docker.
