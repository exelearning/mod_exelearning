# mod_exelearning

Repositorio del futuro **plugin de actividad de Moodle** `mod_exelearning`, que integrará
recursos publicados con [eXeLearning](https://exelearning.net/) en un curso Moodle
preservando el árbol de navegación (sidebar) propio de eXeLearning **y** permitiendo
registrar **varios ítems en el libro de calificaciones** por cada recurso (un paquete con
N cuestionarios producirá N grade items independientes).

Este plugin nace de la fusión conceptual de dos plugins existentes:

- [`mod_exescorm`](https://github.com/exelearning/mod_exescorm) — fork de `mod_scorm` con
  soporte ELPX, eXeLearning Online y editor embebido; calificación agregada en un único
  grade item.
- [`mod_exeweb`](https://github.com/exelearning/mod_exeweb) — visor de paquetes
  eXeLearning sin calificación que preserva la sidebar nativa.

## Estado

**Fase 0 — Investigación**. Todavía no existe código del plugin. Se está construyendo un
repositorio de investigación basado en evidencia en [`research/`](./research/) siguiendo
una metodología inspirada en
[`learningml-ng`](https://github.com/erseco/learningml-ng) (ADRs, fichas REPO/FTE,
matrices de decisión, experimentos reproducibles).

## Estructura

```
mod_exelearning/
├── README.md          # Este archivo
├── AGENTS.md          # Reglas para agentes (puntero a research/AGENTS.md)
└── research/          # Repositorio de investigación (fase 0)
    └── README.md      # Mapa de la investigación
```

## Cómo navegar la investigación

Empezar por:

1. [`research/README.md`](./research/README.md) — mapa del directorio.
2. [`research/AGENTS.md`](./research/AGENTS.md) — reglas operativas (evidencia,
   trazabilidad, IDs, idioma).
3. [`research/status.yaml`](./research/status.yaml) — ledger append-only de decisiones,
   tareas y riesgos.
4. [`research/decisiones/adr/`](./research/decisiones/adr/) — decisiones formalizadas
   como ADRs.

## Licencia

Pendiente de decidir como parte de DEC-0002 (política de clones y licencias). Plugins de
Moodle se distribuyen tradicionalmente bajo GPLv3+.
