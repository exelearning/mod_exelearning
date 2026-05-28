# tools/

Scripts utilitarios. No depender de paquetes pip externos sin justificar.

- `build_indexes.py` — recorre `fuentes/`, `analisis/`, `decisiones/`,
  `experimentos/`, `tareas/`, y genera `../docs/indices/{repos,fuentes,adrs,tareas,preguntas,experimentos,notas}.yaml`.
- `test_schema_validation.py` — valida YAML/JSON contra los schemas de `../schemas/`.

Ejecución (desde `research/`):

```bash
python3 tools/build_indexes.py
python3 tools/test_schema_validation.py
```

Idempotencia: los scripts deben poder ejecutarse repetidamente sin efectos secundarios
externos.
