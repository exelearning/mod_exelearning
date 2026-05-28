#!/usr/bin/env python3
"""test_schema_validation.py

Validación ligera de archivos YAML del repo. Sin dependencias externas: comprueba
parseabilidad básica (YAML válido vía `yaml.safe_load` si está disponible; si no,
verificaciones de superficie) y presencia de campos obligatorios mínimos.

Verifica:
- `status.yaml`: tiene secciones `metadata`, `decisiones_bloqueantes`, `tareas`, `riesgos`.
- `tareas/backlog/*.yaml`: tienen `id`, `titulo`, `estado`.
- `tareas/preguntas/*.yaml`: tienen `id`, `pregunta`, `estado`.
- `experimentos/resultados/*.yaml`: tienen `id`, `titulo`, `objetivo`, `estado`.
- Cada `.md` con frontmatter en `fuentes/`, `analisis/notas/`, `decisiones/adr/` empieza
  con `---` y tiene un `id:` reconocible.

Devuelve código de salida 0 si todo pasa, !=0 si hay errores.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path


def find_research_root(start: Path) -> Path:
    current = start.resolve()
    for parent in [current, *current.parents]:
        if parent.name == "research" and (parent / "AGENTS.md").exists():
            return parent
        if (parent / "research" / "AGENTS.md").exists():
            return parent / "research"
    raise SystemExit("No se encontró el directorio research/ ancestro.")


try:
    import yaml  # type: ignore
    HAVE_YAML = True
except ImportError:
    HAVE_YAML = False


def load_yaml(path: Path):
    if HAVE_YAML:
        with path.open("r", encoding="utf-8") as fh:
            return yaml.safe_load(fh)
    # Modo degradado: devolvemos texto y dejamos que validaciones sean por regex.
    return path.read_text(encoding="utf-8")


def check_keys_present(obj, keys: list[str], where: str) -> list[str]:
    errors = []
    if isinstance(obj, dict):
        for k in keys:
            if k not in obj:
                errors.append(f"{where}: falta clave `{k}`")
    elif isinstance(obj, str):
        for k in keys:
            if not re.search(rf"^{re.escape(k)}\s*:", obj, re.M):
                errors.append(f"{where}: falta clave `{k}` (modo degradado)")
    else:
        errors.append(f"{where}: contenido inesperado")
    return errors


def check_frontmatter(path: Path, required: list[str]) -> list[str]:
    errors = []
    text = path.read_text(encoding="utf-8")
    if not text.startswith("---"):
        errors.append(f"{path}: falta frontmatter YAML")
        return errors
    m = re.match(r"^---\s*\n(.*?)\n---\s*\n", text, re.S)
    if not m:
        errors.append(f"{path}: frontmatter no cierra con ---")
        return errors
    fm = m.group(1)
    for k in required:
        if not re.search(rf"^{re.escape(k)}\s*:", fm, re.M):
            errors.append(f"{path}: falta `{k}` en frontmatter")
    return errors


def main() -> int:
    root = find_research_root(Path(__file__).parent)
    errors: list[str] = []

    # status.yaml
    status = root / "status.yaml"
    if not status.exists():
        errors.append("Falta research/status.yaml")
    else:
        data = load_yaml(status)
        errors += check_keys_present(
            data,
            ["metadata", "decisiones_bloqueantes", "tareas", "riesgos"],
            "status.yaml",
        )

    # backlog
    for p in sorted((root / "tareas" / "backlog").glob("*.yaml")):
        data = load_yaml(p)
        errors += check_keys_present(data, ["id", "titulo", "estado"], str(p.relative_to(root)))

    # preguntas
    for p in sorted((root / "tareas" / "preguntas").glob("*.yaml")):
        data = load_yaml(p)
        errors += check_keys_present(data, ["id", "pregunta", "estado"], str(p.relative_to(root)))

    # experimentos
    for p in sorted((root / "experimentos" / "resultados").glob("*.yaml")):
        data = load_yaml(p)
        errors += check_keys_present(data, ["id", "titulo", "objetivo", "estado"], str(p.relative_to(root)))

    # frontmatters
    for sub, required in [
        (root / "fuentes" / "repositorios", ["id", "titulo", "tipo", "ruta_local", "licencia"]),
        (root / "fuentes" / "tecnologia",   ["id", "titulo", "categoria"]),
        (root / "analisis" / "notas",       ["id", "titulo", "fecha"]),
        (root / "decisiones" / "adr",       ["id", "titulo", "estado", "fecha"]),
    ]:
        for p in sorted(sub.glob("*.md")):
            errors += check_frontmatter(p, required)

    if errors:
        print(f"FAIL — {len(errors)} errores:")
        for e in errors:
            print(f"  · {e}")
        return 1
    print("OK — validación superficial pasada (modo {} ).".format("yaml" if HAVE_YAML else "regex"))
    return 0


if __name__ == "__main__":
    sys.exit(main())
