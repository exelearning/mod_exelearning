# Checklist: embebibilidad de un paquete eXeLearning publicado

Para evaluar si la técnica candidata de mod_exelearning sirve.

- [ ] Punto de entrada conocido (`index.html` o equivalente)
- [ ] Sidebar/TOC funciona dentro de `<iframe>` (eventos, focus)
- [ ] Navegación interna no rompe la URL principal (no `window.top`)
- [ ] CSS no contamina la página de Moodle (scope o `<iframe>` cerrado)
- [ ] Assets resolubles vía `pluginfile.php` con paths relativos
- [ ] El paquete genera statements xAPI o llamadas SCORM identificables
- [ ] Resoluciones móviles aceptables (≥360px ancho)
- [ ] Modo offline plausible (sin CDNs externos requeridos)
- [ ] Resaltado del nodo activo en la sidebar tras navegación
- [ ] Tamaño total razonable (<50MB por paquete sin media pesada)
