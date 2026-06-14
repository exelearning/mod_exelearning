---
id: DEC-0061
titulo: "Embeds externos en modo seguro: promover YouTube/Vimeo/PDF al padre (overlay inline) — standalone, sin subdominio"
estado: Aceptada
fecha: 2026-06-14
agentes:
  - erseco
  - claude-code
fuentes:
  - RIE-001
relacionados:
  - DEC-0059
  - DEC-0060
herramienta_ia:
  interfaz: claude-code
  modelo: claude-opus-4-8
---

## Contexto

El modo seguro ([[DEC-0059]] + [[DEC-0060]]) sirve el `.elpx` en un iframe de **origen opaco**
(`sandbox` sin `allow-same-origin`, servido por `tokenpluginfile`), de modo que el HTML/JS no
confiable del autor no puede leer la sesión/DOM de Moodle. El precio es que **el contenido externo
embebido deja de funcionar**:

- **YouTube/Vimeo salen EN BLANCO.** La flag de sandbox se **propaga al iframe anidado** del player,
  que pierde su origen `youtube.com`/`vimeo.com` y no arranca. Verificado empíricamente y por la
  especificación HTML; no hay primitiva de navegador (credentialless, fenced frames, COEP/COOP,
  `referrerpolicy`…) que lo arregle desde dentro del sandbox.
- **Los PDF tampoco renderizan.** Chrome bloquea su visor (PDFium) en un iframe sandboxed sin
  `allow-same-origin`. Afecta a PDF remotos **y** a los empaquetados en el `.elpx`.

Cómo lo resuelven otros: Moodle (filter/media) y la mayoría de plugins **embeben YouTube directo, sin
sandbox**, confiando en el autor; H5P no ejecuta JS de autor. La única vía "clásica" para aislar +
embeber sería servir el contenido desde un **origen separado (subdominio)** → iframe cross-origin sin
sandbox opaco. **erseco descartó el subdominio** (requisito: el plugin debe funcionar *standalone*) y
descartó degradar a `legacy` para los vídeos ("hay que encontrar la forma de que se vean").

## Decisión

**Promote-to-parent con overlay inline.** El contenido sigue aislado en el sandbox opaco; un **shim**
inyectado en el paquete detecta los iframes de hosts en **lista blanca** (vídeo) o con ruta `.pdf`,
los sustituye por un **placeholder** del mismo tamaño y **reporta su geometría** al padre por
`postMessage`. Un **relay** en la página de Moodle (contexto de confianza) **valida + reconstruye** la
URL y **superpone el reproductor real** —que él renderiza en su propio contexto, con origen real, así
que funciona— **en el sitio exacto** del placeholder, sincronizado con el scroll. El player es
cross-origin (o un PDF del paquete servido como `application/pdf`) y no puede tocar el host; la lista
blanca + la validación estricta impiden que el contenido haga al padre cargar una URL arbitraria.

| Pieza | Cambio |
|---|---|
| `js/exe_embed_shim.js` (nuevo) | Corre DENTRO del iframe. Se **auto-activa solo en origen opaco** (`isOpaqueOrigin()`: `document.cookie` lanza / `window.origin==='null'`), así que el mismo fichero horneado queda **dormido en modo legacy**. Reemplaza iframes whitelisted/`.pdf` por un placeholder (`data-exe-embed-id` + url, mismo w/h) y reporta `{id,url,x,y,w,h}` en load/scroll/resize/mutation (rAF). Dual-export `window.exeEmbedShim` + `module.exports` (Vitest). |
| `js/exe_embed_relay.js` (nuevo) | Corre en el padre. Valida cada URL reportada: `new URL()`, rechaza userinfo (`@`), host exacto contra la lista, exige patrón de id (`/embed/{id}` YT, `/video/{id}` Vimeo) y **reconstruye** la URL canónica (nunca pasa la del contenido tal cual). Crea/posiciona un `<iframe>` player overlay clampado al iframe de contenido. Autentica por `event.source === iframe.contentWindow` (el origen opaco no da `event.origin` útil). |
| `classes/local/package_manager.php` | Copia `js/exe_embed_shim.js` a `libs/exe_embed_shim.js` del paquete (plugin-owned, refrescado en cada extracción). |
| `classes/local/scorm/scorm_injector.php` | Hornea `<script>window.__exeEmbedWhitelist=…</script>` + `<script src="libs/exe_embed_shim.js">` al inicio del `<head>` de **todo** HTML del paquete (marker `embed-shim`, idempotente). Independiente del bridge SCORM (reusa la misma iteración de extracción, [[DEC-0054]]). |
| `classes/local/ui/player_iframe.php` | `embed_whitelist()` + `DEFAULT_EMBED_HOSTS` (YouTube/Vimeo). La CSP ya permitía `frame-src 'self' $siteorigin https:`. |
| `view.php` | Inyecta el relay **inline** en el bloque `if ($securemode)` (independiente de SCORM, para todo paquete seguro), igual que el relay del bridge, para que su listener esté instalado antes de que cargue el iframe. |

Decisiones de diseño confirmadas por erseco:

1. **UX = overlay inline, NO modal/lightbox** ("aunque tengamos el riesgo debería ser inline"). El
   player se ve en su sitio y se reproduce ahí; con scroll el overlay sigue al hueco.
2. **PDFs = los locales del paquete siempre + cualquier `https` `.pdf`** (regla por extensión, sin
   lista blanca de host para PDF). **Invariante de seguridad:** un `.pdf` *same-origin* solo se
   renderiza si pertenece a este paquete (su path está bajo el directorio del contenido **o** contiene
   el hash del paquete como segmento). Como `tokenpluginfile` sirve los ficheros del paquete por
   extensión (`application/pdf`) con `X-Content-Type-Options: nosniff`, un `.pdf` del paquete nunca es
   HTML ejecutable; y una URL same-origin ajena (p.ej. `/admin/x.pdf`) se rechaza.
3. **`referrerpolicy`** del player: `strict-origin-when-cross-origin` para vídeo (evita YouTube Error
   153), `no-referrer` para PDF. El iframe de CONTENIDO mantiene su `no-referrer`.
4. **Solo en secure.** En legacy el contenido es same-origin, el shim queda dormido y los players ya
   van inline.

Lista blanca por defecto: `www.youtube.com`, `youtube.com`, `www.youtube-nocookie.com`,
`youtube-nocookie.com`, `player.vimeo.com`, `vimeo.com`.

## Consecuencias

- El modo seguro deja de **degradar la UX** del contenido que abre recursos externos: YouTube, Vimeo y
  PDF (remotos y empaquetados) se ven inline sin sacrificar el aislamiento ni exigir un subdominio.
- Las imágenes y el vídeo HTML5 externos ya cargaban por CSP (`img-src/media-src … https:`); esto solo
  añade los casos que el sandbox rompía (iframes de player + PDF).
- La feature pertenece a la **familia eXeLearning**: el mismo JS (shim + relay, misma lógica) está en
  wp-exelearning (PR #56) y omeka-s-exelearning (PR #21). Diferencia de cableado: **mod hornea** el
  shim en la extracción y **inlina** el relay en `view.php`; wp/omeka lo inyectan a la hora de servir.
- El bridge SCORM ([[DEC-0059]]) no se ve afectado: usa otro tipo de mensaje (`scorm` vs `exe-embed`)
  y su relay sigue independiente.

## Riesgos

- **Clickjacking del overlay (aceptado por erseco).** El player se superpone al área del contenido.
  Mitigaciones: el overlay lo **controla el padre** (el contenido solo reporta geometría + URL, no
  inyecta nada), se **clampa a los límites del iframe de contenido** (no puede cubrir la toolbar/UI de
  Moodle) y el player es **cross-origin** (no puede leer ni clicar el host). Geometría absurda
  (no finita) se descarta.
- **Render de URL arbitraria por el padre** → contenida por la lista blanca de host + reconstrucción
  de URL + rechazo de userinfo/patrón (salvaguarda de RIE-001, familia del modo seguro). Para PDF, la
  apertura es a un visor (no ejecuta como la página) y el caso same-origin queda atado a ficheros del
  propio paquete.
- **`targetOrigin: '*'`** en el `postMessage` del shim: aceptable porque no viaja ningún secreto y el
  padre autentica por `event.source` (el origen opaco no ofrece `event.origin` usable).

## Validación

- **Vitest** `tests/js/exe_embed.test.js` (14 tests): el validador acepta `youtube.com/embed/{id}`,
  `youtube-nocookie`, `player.vimeo.com/video/{id}` y **reconstruye** la URL canónica; **rechaza**
  `youtube.com.evil.com`, `evil.com@youtube.com`, esquema no-https, id inválido; PDF cross-origin
  `https` se acepta, PDF same-origin solo si es del paquete (dir/hash), `/admin/x.pdf` se rechaza; el
  shim produce placeholder para video/PDF y deja intacto un iframe no permitido.
- **Regresión SCORM (que no se rompa la puntuación)** — el modo seguro ahora carga **a la vez** el
  bridge SCORM y el shim/relay de embeds; deben convivir:
  - Vitest `tests/js/embed_scorm_coexistence.test.js` (4 tests): los 5 globals (tracker + shim/relay
    SCORM + shim/relay embed) quedan definidos y distintos (sin pisarse); el relay SCORM **sigue
    aceptando** un mensaje `track` (`acceptTrack` con nonce correcto) con los módulos de embed
    cargados, y rechaza el nonce erróneo; ambos canales se ignoran mutuamente (`scorm` vs `exe-embed`).
  - PHPUnit en el contenedor vivo (`make test` / `phpunit-docker.sh`): **119 tests OK** sobre
    `track_test` (endpoint que **guarda la puntuación**), `scorm_injector_test` (mi cambio: hornea
    bridge + embed, idempotente), `grades_test`/`lib_grades_test`/`gradeitems_test`/`grademodel_test`.
- **PHPUnit** `tests/local/scorm/scorm_injector_test.php`: asserts de que el marker `embed-shim`, el
  global `__exeEmbedWhitelist` y `libs/exe_embed_shim.js` se hornean en `index.html` y en las páginas
  anidadas (`../libs/…`), idempotente, **sin perder** el bridge SCORM ni el wrapper pipwerks.
- **`phpcs --standard=moodle`** 0/0 sobre `player_iframe.php`, `package_manager.php`,
  `scorm_injector.php`, `view.php` y los tests.
- **Verificación en vivo** (contenedor `mod_exelearning_3-moodle-1`, `iframemode=secure`): creada una
  actividad desde `research/fixtures/elpx/external-embeds-demo.elpx` → la extracción **hornea** el shim
  (marker `embed-shim` + global `__exeEmbedWhitelist` con `youtube-nocookie.com` + asset
  `libs/exe_embed_shim.js` de 8690 B presente) y el contenido conserva los iframes YouTube/Vimeo + el
  PDF local. El mecanismo de render inline es **idéntico** al ya verificado visualmente en
  wp-exelearning (wp-env :8890: YouTube + Vimeo + PDF remoto + PDF local renderizan inline,
  `example.com` no se promueve) y al wiring vivo de omeka (:8080).
- **Fixtures**: `research/fixtures/elpx/{youtube-embed,vimeo-embed,external-embeds-demo}.elpx` (el
  último con PDF remoto + PDF local empaquetado + imagen/vídeo remotos + un iframe no-whitelist).

## Seguimiento

- UI de admin para editar la lista blanca (hoy constante; configurable por filtro/ajuste como
  seguimiento, igual que en wp/omeka).
- Paridad mantenida con wp-exelearning (PR #56) y omeka-s-exelearning (PR #21): el JS shim/relay es la
  misma lógica en los tres.
- Independiente de TAREA-015 (xAPI) y TAREA-016 (origen por URL): el origen separado por URL sigue
  siendo una alternativa válida para quien lo quiera, pero ya no es **necesaria** para ver vídeos/PDF.
