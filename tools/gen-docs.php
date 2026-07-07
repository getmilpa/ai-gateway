<?php

/**
 * This file is part of milpa/ai-gateway — the dual-provider LLM client and agentic
 * tool-use runtime for the Milpa PHP framework.
 *
 * (c) TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/ai-gateway
 */

declare(strict_types=1);

/**
 * Generates the static API reference site for milpa/ai-gateway.
 *
 * Thin entry over the family docs generator (`Milpa\Docs\SiteGenerator`,
 * shipped inside the milpa/core dist this package already requires): reflects
 * over `src/`, renders one `mui-api`-styled page per public type wrapped in
 * the `mui-docs` shell, a nav, a per-page table of contents, and `index.html`.
 *
 * Usage: php tools/gen-docs.php --out <dir> [--css-base <url>] [--version <v>]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

// Required-value long options (`name:`, not `name::`) so `--css-base /ds` with a
// space is captured; optional (`::`) only binds `--css-base=/ds`. getopt yields
// `false` for a flag it can't bind a value to, so guard with is_string, not `??`
// (which only rescues null) before falling back to the default.
$opts = getopt('', ['out:', 'css-base:', 'version:']);
$out = is_string($opts['out'] ?? null) ? $opts['out'] : 'build/docs';
$cssBase = is_string($opts['css-base'] ?? null) ? $opts['css-base'] : 'https://cdn.jsdelivr.net/npm/@milpa/design@0.8.0';

// Version shown in the docs chrome (topbar badge, title, footer). Prefer an
// explicit --version; otherwise read the release-please manifest (present in
// the published repo); fall back to "dev" for local builds.
$version = is_string($opts['version'] ?? null) ? $opts['version'] : null;
if ($version === null) {
    $manifest = dirname(__DIR__) . '/.github/.release-please-manifest.json';
    $data = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;
    $version = is_array($data) && is_string($data['.'] ?? null) ? $data['.'] : 'dev';
}

$count = (new Milpa\Docs\SiteGenerator(dirname(__DIR__) . '/src', $out, $cssBase, $version))->generate();

// INTERIM re-branding: the family generator (milpa/core <= 0.2) hardcodes the
// "Milpa Core" brand, hero prose and install snippet in Shell/SiteGenerator.
// Until core parametrizes those, rewrite the generated HTML for this package.
// Tracked in the monorepo ROADMAP (gen-docs multi-paquete, mejoras diferidas).
$rebrand = [
    'utm_content=core' => 'utm_content=ai-gateway',
    'Milpa Core' => 'Milpa AI Gateway',
    'id="milpa-core"' => 'id="milpa-ai-gateway"',
    'composer require milpa/core' => 'composer require milpa/ai-gateway',
    'https://github.com/getmilpa/core' => 'https://github.com/getmilpa/ai-gateway',
    'https://getmilpa.github.io/core/' => 'https://getmilpa.github.io/ai-gateway/',
    // Footer credit link: inherit the muted footer color instead of browser-default blue
    // (fixed at source in core's Shell for >0.2; injected here for the 0.2 vendor).
    '.docs-footer__credit { margin:0; font-size:var(--text-xs); }'
    => '.docs-footer__credit { margin:0; font-size:var(--text-xs); }'
        . '.docs-footer__credit a { color:inherit; text-decoration:underline; text-underline-offset:2px; text-decoration-color:var(--border-strong); }'
        . '.docs-footer__credit a:hover { color:var(--text); text-decoration-color:currentColor; }',

    'The framework-agnostic <strong>contracts core</strong> of Milpa — a modular PHP runtime for '
        . 'applications operable by <strong>both humans and agents</strong>. No ORM, no HTTP client, no kernel: '
        . 'just the primitives every Milpa module builds on.'
    => 'A <strong>dual-provider LLM gateway</strong> for Milpa — OpenAI and Anthropic chat completions '
        . 'behind one client, with tool-call format translation both ways, plus an agentic tool-use loop '
        . 'that drives a <code>milpa/tool-runtime</code> <code>ToolRegistry</code> until the model is done.',
];
$pages = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS));
foreach ($pages as $file) {
    if ($file->getExtension() === 'html') {
        file_put_contents($file->getPathname(), strtr((string) file_get_contents($file->getPathname()), $rebrand));
    }
}

echo "generated {$count} page(s) to {$out} (v{$version}, css-base: {$cssBase})\n";
exit(0);
