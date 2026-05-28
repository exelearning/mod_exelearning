---
id: FTE-005
titulo: "LTI 1.3 + Assignment and Grade Services (AGS)"
categoria: estandar
version_consultada: "LTI 1.3 / AGS 2.0"
enlaces_oficiales:
  - https://www.imsglobal.org/spec/lti/v1p3
  - https://www.imsglobal.org/spec/lti-ags/v2p0
context7:
  library_id: "[PENDIENTE: context7]"
  query: "[PENDIENTE: context7]"
  fecha: null
  version_devuelta: "[PENDIENTE: context7]"
fecha_consulta: 2026-05-28
relevancia_para_mod_exelearning: "Estándar tool↔platform con soporte nativo de múltiples line items por enlace. Modelo distinto: el contenido vive en una herramienta externa."
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-7
---

## Qué es

Estándar 1EdTech (antes IMS Global) para integrar herramientas externas en LMS con
launch firmado (OIDC + JWT) y servicios de gradebook (AGS), namesrole (NRPS) y deep
linking.

## Conceptos clave

- **Platform** (LMS) y **Tool** (herramienta externa).
- **LineItem**: ítem calificable.
- **Score**: nota enviada a un line item.
- **ResourceLink**: enlace a un recurso instanciado en un curso.

## API / Puntos de extensión relevantes

- `POST /scores` por line item.
- `GET/POST /lineitems` listar/crear.
- En Moodle: subplugin `ltiservice_gradebookservices`.

## Soporte para multi-grade-items

**Nativo y excelente.** Una herramienta puede crear N line items por contexto y
publicar scores independientes. Es el patrón de referencia para multi-item.

## Soporte para navegación/sidebar

No aplica directamente: el contenido se renderiza en la herramienta, no en el LMS.
Para `mod_exelearning` el contenido vive *en* Moodle, así que LTI implica externalizar
la pieza eXeLearning ⇒ pierde el sentido de "subir un paquete al curso".

## Implementaciones de referencia consultadas

- REPO-004 — `public/mod/lti/service/gradebookservices/`.

## Riesgos / Limitaciones

- Modelo tool-provider; requiere infra externa.
- Complejidad de OIDC/JWT/keyset.
- Pierde el flujo "subir paquete eXeLearning a Moodle" que se quiere.

## Preguntas abiertas

- ¿Hay un caso en que se quiera lanzar eXeLearning Online como tool LTI? — registrar como caso de uso alternativo.
