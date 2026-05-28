# Checklist: auditoría de un plugin Moodle externo

Para producir una ficha `REPO-NNN` con calidad mínima.

- [ ] `version.php`: `component`, `version`, `requires`, `release`
- [ ] `db/install.xml`: tablas, claves, índices anotados
- [ ] `db/access.php`: capabilities listadas
- [ ] `lib.php`: funciones públicas `<mod>_*` (add_instance, update_instance, delete_instance, supports, grade_item_update, update_grades, get_completion_state)
- [ ] `mod_form.php`: campos del formulario, file managers, tipos de archivo aceptados
- [ ] `view.php`: cómo se renderiza la actividad (iframe, frameset, redirect)
- [ ] `classes/privacy/provider.php`: GDPR
- [ ] `backup/moodle2/`: estructura
- [ ] `lang/en/<mod>.php`: strings notables (`pluginname`, `modulename`, …)
- [ ] `amd/src/`, `templates/`: assets cliente
- [ ] Integraciones SCORM/xAPI/cmi5/LTI presentes
- [ ] Licencia
- [ ] Riesgos para reutilización
