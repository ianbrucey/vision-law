<?php

namespace Tests\Architecture;

use Tests\TestCase;

/**
 * Spec 013 — Vision Systems verdict tests (06-plan.md, Tickets 1–5).
 *
 * This file grows ticket by ticket. Ticket 1 (token layer) carries:
 * - C-02, token-layer half: all 25 theme token sets complete in the compiled
 *   CSS, Ocean Deep the default (:root fallback set). The claim's other
 *   half — the rendered root carrying the Ocean Deep theme and the switcher
 *   offering all 25 — is asserted with the landing build in Ticket 3
 *   (03-contract.md §Theme structure: switching is client-side, mounted in
 *   the landing nav).
 * - C-04: the motion tokens are defined once and a
 *   `prefers-reduced-motion` block neutralizes the motion layer.
 *
 * Token values are transcribed from
 * specs/013-vision-systems-ui-landing/tokens.md (the C-02 implementation
 * authority); slugs are the contract identifiers of 03-contract.md.
 */
class VisionSystemsUiTest extends TestCase
{
    /** The 25 contract slugs (03-contract.md §Theme structure). */
    private const THEME_SLUGS = [
        'aurora', 'earth-grove', 'midnight', 'ocean-deep', 'ember', 'daylight',
        'regal', 'matte-slate', 'matte-sand', 'matte-clay', 'matte-olive',
        'matte-dusk', 'calm-deep-ocean', 'lagoon', 'sage-mist', 'dawn-mist',
        'stone-shore', 'twilight-drift', 'liberty-dusk', 'barn-stone',
        'parchment', 'walnut', 'canyon-mesa', 'adobe-dawn', 'americana-rwb',
    ];

    /** Every token in the token contract (03-contract.md §Design-token contract). */
    private const CONTRACT_TOKENS = [
        '--bg', '--bg-deep', '--glass', '--glass-2', '--line', '--wash',
        '--txt', '--mut', '--acc', '--acc-2', '--ok', '--warn', '--bad',
        '--cyan', '--radius', '--radius-sm', '--font', '--ease', '--dur-1',
        '--dur-2', '--dur-3', '--reading-lh',
    ];

    /**
     * Tokens whose values are per-theme (tokens.md): each theme block must
     * define these directly. The remaining contract tokens are structural —
     * defined once at :root (013-D13) and inherited by every theme, which
     * the contract allows: the computed set per theme is what must be
     * complete (03-contract.md, completeness rule).
     */
    private const PER_THEME_TOKENS = [
        '--bg', '--bg-deep', '--glass', '--glass-2', '--line', '--wash',
        '--txt', '--mut', '--acc', '--acc-2', '--ok', '--warn', '--bad', '--cyan',
    ];

    /**
     * Calm/heritage slugs whose blocks set --reading-lh to the looser 1.72
     * (contract §Theme structure, calm reading rule; 013-D13). Every other
     * theme computes the :root base value 1.6.
     */
    private const LOOSE_READING_SLUGS = [
        'calm-deep-ocean', 'lagoon', 'sage-mist', 'dawn-mist', 'stone-shore',
        'twilight-drift', 'liberty-dusk', 'barn-stone', 'parchment', 'walnut',
        'canyon-mesa', 'adobe-dawn', 'americana-rwb',
    ];

    // ------------------------------------------------------------------
    // C-02 — theme token sets complete, Ocean Deep default (token-layer half)
    // ------------------------------------------------------------------

    public function test_all_25_theme_token_sets_complete_and_ocean_deep_default(): void
    {
        $css = $this->compiledAppCss();
        $root = $this->rootTokens($css);

        // Structural tokens live once at :root, at the 013-D13 values.
        $this->assertSame('8px', $root['--radius-sm'] ?? null, ':root --radius-sm must be the 013-D13 value 8px.');
        $this->assertSame('14px', $root['--radius'] ?? null, ':root --radius must be the source value 14px.');
        $this->assertSame('1.6', $root['--reading-lh'] ?? null, ':root --reading-lh must be the 013-D13 base 1.6.');

        foreach (self::THEME_SLUGS as $slug) {
            $block = $this->themeTokens($css, $slug);
            $this->assertNotNull($block, "Theme [data-vs-theme=\"{$slug}\"] is missing from the compiled CSS.");

            foreach (self::PER_THEME_TOKENS as $token) {
                $this->assertArrayHasKey(
                    $token,
                    $block,
                    "Theme {$slug} does not define {$token} directly — the set is incomplete."
                );
            }

            // Computed set (:root inherited + theme block) must be complete.
            $computed = array_merge($root, $block);
            foreach (self::CONTRACT_TOKENS as $token) {
                $this->assertArrayHasKey(
                    $token,
                    $computed,
                    "Theme {$slug} computes no value for {$token} — the set is incomplete."
                );
            }

            if (in_array($slug, self::LOOSE_READING_SLUGS, true)) {
                $this->assertSame(
                    '1.72',
                    $block['--reading-lh'] ?? null,
                    "Theme {$slug} is a calm/heritage set and must set --reading-lh: 1.72 in its block."
                );
            }
        }

        // Ocean Deep is the default: the :root fallback carries its complete
        // per-theme set verbatim (same values as its theme block), so first
        // paint is Ocean Deep before any script runs.
        $ocean = $this->themeTokens($css, 'ocean-deep');
        $this->assertNotNull($ocean);
        foreach ($ocean as $token => $value) {
            if (str_starts_with($token, '--lightningcss-')) {
                continue; // compiler-injected color-scheme helpers, not tokens
            }
            $this->assertArrayHasKey($token, $root, ":root fallback is missing Ocean Deep's {$token}.");
            $this->assertSame(
                $value,
                $root[$token],
                ":root fallback value for {$token} differs from the Ocean Deep theme block."
            );
        }
    }

    // ------------------------------------------------------------------
    // C-04 — motion tokens + reduced-motion neutralization
    // ------------------------------------------------------------------

    public function test_motion_tokens_and_reduced_motion_rules_present(): void
    {
        $css = $this->compiledAppCss();
        $root = $this->rootTokens($css);

        // The shared curve and duration scale, defined once at :root, at the
        // contract / 013-D13 values.
        $this->assertSame('cubic-bezier(.32,.72,0,1)', $root['--ease'] ?? null, ':root --ease must be the shared Apple-soft curve.');
        $this->assertSame('.18s', $root['--dur-1'] ?? null, ':root --dur-1 must be the 013-D13 micro duration .18s.');
        $this->assertSame('.45s', $root['--dur-2'] ?? null, ':root --dur-2 must be the 013-D13 panel duration .45s.');
        $this->assertSame('.65s', $root['--dur-3'] ?? null, ':root --dur-3 must be the 013-D13 entrance duration .65s.');

        // Transition rules reference the shared tokens (theme cross-fade in
        // the token layer; component entrances join it in later tickets).
        $this->assertMatchesRegularExpression(
            '/transition:[^;}]*var\(--dur-3\)[^;}]*var\(--ease\)/',
            $css,
            'No transition rule references the shared --dur-3 / --ease motion tokens.'
        );

        // prefers-reduced-motion neutralizes the motion layer: animations
        // and transitions off.
        $squashed = (string) preg_replace('/\s+/', '', $css);
        $mediaAt = strpos($squashed, '@media(prefers-reduced-motion:reduce)');
        $this->assertNotFalse($mediaAt, 'No prefers-reduced-motion block in the compiled CSS.');
        $block = substr($squashed, $mediaAt, 400);
        $this->assertStringContainsString('transition:none!important', $block, 'The reduced-motion block must switch transitions off.');
        $this->assertStringContainsString('animation:none!important', $block, 'The reduced-motion block must switch animations off.');
    }

    // ------------------------------------------------------------------
    // Compiled-CSS parsing helpers
    // ------------------------------------------------------------------

    private function compiledAppCss(): string
    {
        $manifestPath = public_path('build/manifest.json');
        $this->assertFileExists($manifestPath, 'Vite manifest missing — run `npm run build` first.');

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $entry = $manifest['resources/css/app.css'] ?? null;
        $this->assertNotNull($entry, 'resources/css/app.css is missing from the Vite manifest.');

        $cssPath = public_path('build/'.$entry['file']);
        $this->assertFileExists($cssPath);

        return (string) file_get_contents($cssPath);
    }

    /**
     * Declarations of one block body as a name → normalized-value map.
     *
     * @return array<string, string>
     */
    private function declarations(string $body): array
    {
        $map = [];
        foreach (explode(';', $body) as $declaration) {
            $colon = strpos($declaration, ':');
            if ($colon === false) {
                continue;
            }
            $name = trim(substr($declaration, 0, $colon));
            $value = (string) preg_replace('/\s+/', '', substr($declaration, $colon + 1));
            if ($name !== '') {
                $map[$name] = strtolower($value);
            }
        }

        return $map;
    }

    /**
     * All :root declarations merged (the compiler emits several :root
     * blocks; later ones win per property, matching the cascade).
     *
     * @return array<string, string>
     */
    private function rootTokens(string $css): array
    {
        preg_match_all('/(?<![\w-]):root\s*\{([^}]*)\}/', $css, $matches);
        $merged = [];
        foreach ($matches[1] as $body) {
            $merged = array_merge($merged, $this->declarations($body));
        }

        return $merged;
    }

    /**
     * Declarations of the [data-vs-theme="<slug>"] block, or null if absent.
     *
     * @return array<string, string>|null
     */
    private function themeTokens(string $css, string $slug): ?array
    {
        if (! preg_match('/\[data-vs-theme=["\']?'.preg_quote($slug, '/').'["\']?\]\s*\{([^}]*)\}/', $css, $m)) {
            return null;
        }

        return $this->declarations($m[1]);
    }
}
