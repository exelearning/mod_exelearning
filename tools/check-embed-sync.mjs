#!/usr/bin/env node
// Maintenance helper: verify the external-embed shim/relay logic stays in sync across
// the three eXeLearning embedders. mod_exelearning is the CANONICAL source; wp-exelearning
// and omeka-s-exelearning mirror it -- only the export wrapper differs (mod is dual-export
// for Vitest; wp/omeka are auto-running IIFEs). This is NOT a CI gate (there is no shared
// infra across the three repos); it is a local check to run before/after touching the
// embedder. Exits non-zero when a copy has drifted (a required invariant is missing).
//
// Usage (mirror paths via flags or WP_EXE_DIR / OMEKA_EXE_DIR env vars):
//   node tools/check-embed-sync.mjs --wp /path/to/wp-exelearning --omeka /path/to/omeka-s-exelearning
// With no mirror paths it only sanity-checks the canonical mod files and prints how to
// point it at the mirrors.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const MOD_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/** Parse --wp / --omeka flags, falling back to env vars. */
function resolveMirrors() {
    const args = process.argv.slice(2);
    const get = (flag) => {
        const i = args.indexOf(flag);
        return i !== -1 && args[i + 1] ? args[i + 1] : null;
    };
    return {
        wp: get('--wp') || process.env.WP_EXE_DIR || null,
        omeka: get('--omeka') || process.env.OMEKA_EXE_DIR || null,
    };
}

// Logic invariants every RELAY copy must contain (normalised: whitespace + quote style
// are ignored, so tabs-vs-spaces and the IIFE wrapper do not count as drift).
const RELAY_INVARIANTS = [
    'isCrossOriginHttps',                  // open-mode structural invariant (DEC-0061)
    'normalizeHost',                       // trailing-dot FQDN-root normalisation (no host. bypass)
    'url.origin === window.location.origin', // cross-origin gate (rejects same-origin)
    'allow-scripts allow-same-origin allow-popups allow-forms allow-presentation', // video sandbox
    'data-exe-embed-player',              // forged-message defence (D2)
    'data-exe-embed-src',                 // the page-navigation (id-reuse) fix
    'Math.min(embed.w, rect.width)',      // overlay clamp (clickjacking defence)
    'youtube-nocookie.com/embed/',        // strict-mode per-provider reconstruction
    'reconstructProvider',                // id-only provider channel (DEC-0067)
];

// Logic invariants every SHIM copy must contain.
const SHIM_INVARIANTS = [
    'isCrossOriginHttps',                 // promote any cross-origin https iframe
    'data-exe-embed-id',
    'data-exe-embed-url',
    '.pdf$',                              // the PDF detector (promote PDFs too)
    'extractProvider',                    // id-only provider channel (DEC-0067)
];

// Host + token + setting invariants every sandbox PHP must contain.
const PHP_INVARIANTS = [
    'www.dailymotion.com',
    'mediateca.educa.madrid.org',
    'allow-scripts allow-popups allow-forms', // secure tokens (normalised, order matters)
    'embedmode',                          // the open/strict embed policy setting (DEC-0061)
];

const FILES = {
    relay: { mod: 'js/exe_embed_relay.js', wp: 'assets/js/exe-embed-relay.js', omeka: 'asset/js/exe-embed-relay.js', invariants: RELAY_INVARIANTS },
    shim: { mod: 'js/exe_embed_shim.js', wp: 'assets/js/exe-embed-shim.js', omeka: 'asset/js/exe-embed-shim.js', invariants: SHIM_INVARIANTS },
    php: { mod: 'classes/local/ui/player_iframe.php', wp: 'includes/class-iframe-sandbox.php', omeka: 'src/Service/IframeSandbox.php', invariants: PHP_INVARIANTS },
};

const norm = (s) => s.replace(/\s+/g, '').replace(/'/g, '"');

function check(label, absPath, invariants) {
    if (!fs.existsSync(absPath)) {
        return { label, path: absPath, missing: ['<file not found>'] };
    }
    const body = norm(fs.readFileSync(absPath, 'utf8'));
    const missing = invariants.filter((inv) => body.indexOf(norm(inv)) === -1);
    return { label, path: absPath, missing };
}

function main() {
    const mirrors = resolveMirrors();
    const roots = { mod: MOD_ROOT, wp: mirrors.wp, omeka: mirrors.omeka };
    const results = [];

    for (const [kind, spec] of Object.entries(FILES)) {
        for (const repo of ['mod', 'wp', 'omeka']) {
            if (!roots[repo]) { continue; }
            results.push(check(`${repo}:${kind}`, path.join(roots[repo], spec[repo]), spec.invariants));
        }
    }

    let drift = 0;
    for (const r of results) {
        if (r.missing.length) {
            drift++;
            console.error(`DRIFT  ${r.label}  (${r.path})`);
            r.missing.forEach((m) => console.error(`         missing: ${m}`));
        } else {
            console.log(`ok     ${r.label}`);
        }
    }

    if (!mirrors.wp || !mirrors.omeka) {
        console.log('\nNote: pass --wp <dir> --omeka <dir> (or set WP_EXE_DIR / OMEKA_EXE_DIR)');
        console.log('to also check the wp-exelearning and omeka-s-exelearning mirrors.');
    }
    if (drift) {
        console.error(`\n${drift} file(s) drifted from the canonical embedder logic.`);
        process.exit(1);
    }
    console.log('\nNo drift detected.');
}

main();
