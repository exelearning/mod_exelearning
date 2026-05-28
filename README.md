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

**Fase 0 → Fase 1 (arranque)**.

- Investigación basada en evidencia consolidada en [`research/`](./research/)
  (metodología inspirada en
  [`learningml-ng`](https://github.com/erseco/learningml-ng): ADRs, fichas REPO/FTE,
  matrices, experimentos reproducibles).
- **Esqueleto mínimo del plugin** publicado: el plugin se instala en Moodle pero
  la implementación funcional (sidebar iframe, multi-grade-items, xAPI handler,
  editor embebido) llegará en iteraciones según los ADRs en
  [`research/decisiones/adr/`](./research/decisiones/adr/).

## Estructura

```
mod_exelearning/
├── README.md            # Este archivo
├── AGENTS.md            # Reglas para agentes (puntero a research/AGENTS.md)
├── version.php          # Metadatos del plugin Moodle (target 4.5 LTS+)
├── lib.php              # API pública del módulo (stubs funcionales)
├── mod_form.php         # Formulario de creación de actividad
├── view.php             # Vista de la actividad (stub)
├── index.php            # Listado por curso
├── db/install.xml       # Esquema mdl_exelearning
├── db/access.php        # Capabilities
├── lang/en/             # Strings en inglés
├── pix/                 # Iconos del plugin
├── blueprint.json       # Receta moodle-playground para test rápido
├── docker-compose.yml   # Entorno de desarrollo local
├── .env.dist            # Plantilla de variables de entorno
└── research/            # Documentación de investigación
    └── README.md
```

## Levantar el entorno local

```bash
cp .env.dist .env                # primera vez
docker compose up -d             # arranca Moodle + MariaDB
docker compose logs -f moodle    # seguir el progreso de la instalación
```

Cuando la instalación termine (≈1 min), Moodle está en <http://localhost> con
usuario `user` / contraseña `1234` (configurables en `.env`). El plugin
`mod_exelearning` aparecerá en *Administración del sitio → Plugins → Módulos
de actividad*.

Para tirar el entorno y conservar datos: `docker compose down`. Para borrar
también los volúmenes: `docker compose down -v`.

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
