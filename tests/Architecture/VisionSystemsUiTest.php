<?php

namespace Tests\Architecture;

use Illuminate\Support\Facades\Blade;
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
 * Ticket 3 adds:
 * - C-05: the landing composes only from <x-vs.*> components (a scan of
 *   the landing view, its partials, and the public layout). The
 *   landing's rendered verdicts (C-01, C-02 rendered half, C-07, and
 *   the 013-D14 F3 illustration label) live in
 *   tests/Feature/Public/LandingVisionSystemsTest.php.
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
    // C-05 — the landing is built only from <x-vs.*> components (Ticket 3)
    // ------------------------------------------------------------------

    public function test_landing_built_only_from_vs_components(): void
    {
        // The landing view, its partials, and the shared public layout
        // are scanned (00-brief.md C-05; 03-contract.md §Landing page
        // section contract): no class token from a component's own
        // class family may appear in a class attribute — that is
        // duplicated component markup, the same line C-03 draws inside
        // the family — colors stay token references, and the views must
        // actually compose from <x-vs.*> tags. Layout scaffolding
        // (structure, grids, typography) uses the landing's own vsl-
        // prefixed classes, which are not component classes.
        $files = array_merge(
            [
                resource_path('views/public/landing.blade.php'),
                resource_path('views/layouts/public.blade.php'),
            ],
            glob(resource_path('views/public/partials/*.blade.php')) ?: []
        );
        $this->assertGreaterThanOrEqual(3, count($files));

        $componentClass = '/^vs-(btn|pill|tab|state|band|stage|rail|protocol|checkpoint|composer|ev|count|card|tag|iconbtn|send|fileinput)(__|--)?/';
        $usesComponents = false;

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $name = basename($file);

            if (str_contains($source, '<x-vs.')) {
                $usesComponents = true;
            }

            preg_match_all('/class="([^"]*)"/', $source, $classAttrs);
            foreach ($classAttrs[1] as $classAttr) {
                foreach (preg_split('/\s+/', trim($classAttr)) ?: [] as $token) {
                    $this->assertDoesNotMatchRegularExpression(
                        $componentClass,
                        $token,
                        "{$name} duplicates a vs component class in markup: {$token}"
                    );
                }
            }

            $this->assertDoesNotMatchRegularExpression(
                '/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/',
                $source,
                "{$name} contains a raw color value"
            );
        }

        $this->assertTrue($usesComponents, 'The landing must compose from <x-vs.*> components.');
    }

    // ------------------------------------------------------------------
    // C-03 — components render from tokens only (Ticket 2)
    // ------------------------------------------------------------------

    public function test_vs_components_render_from_tokens_only(): void
    {
        // Every component in the contract inventory renders with defaults
        // (minimal props) and carries its component class.
        $renders = [
            'button' => ['<x-vs.button>Save</x-vs.button>', ['<button', 'vs-btn', 'vs-btn--secondary', 'Save']],
            'pill' => ['<x-vs.pill>MAT-2026-014</x-vs.pill>', ['vs-pill', 'vs-pill--ghost', 'MAT-2026-014']],
            'tab' => ['<x-vs.tab>Platform</x-vs.tab>', ['<button', 'vs-tab', 'Platform']],
            'state' => ['<x-vs.state state="established" />', ['vs-state', 'vs-state--ok', 'ESTABLISHED']],
            'band' => ['<x-vs.band level="strong" />', ['vs-band', 'vs-band--strong', 'STRONG']],
            'protocol' => ['<x-vs.protocol name="ASSESSMENT" tools="brain queries" />', ['vs-protocol', 'Active protocol: ASSESSMENT', 'Tools in hand: brain queries']],
            'checkpoint' => ['<x-vs.checkpoint>Hold it, or file?</x-vs.checkpoint>', ['vs-checkpoint', 'vs-checkpoint--awaiting', 'Checkpoint — your call required', 'Hold it, or file?']],
            'composer' => ['<x-vs.composer />', ['<form', 'vs-composer', '<textarea', 'vs-send']],
            'count' => ['<x-vs.count value="34" label="facts" />', ['vs-count', '34', 'facts']],
            'card' => ['<x-vs.card>Body copy</x-vs.card>', ['vs-card', 'Body copy']],
        ];
        foreach ($renders as $name => [$template, $needles]) {
            $html = Blade::render($template);
            $this->assertNotEmpty(trim($html), "Component {$name} rendered empty.");
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $html, "Component {$name} is missing [{$needle}].");
            }
        }

        // Stage rail and evidence row take structured props.
        $rail = Blade::render(
            '<x-vs.stage-rail :stages="$stages" :current="1" />',
            ['stages' => ['Intake', 'Assessment', 'Draft']],
        );
        foreach (['vs-rail', 'vs-stage--done', 'vs-stage--now', 'Intake', 'Assessment', 'Draft', 'aria-current="step"'] as $needle) {
            $this->assertStringContainsString($needle, $rail, "Stage rail is missing [{$needle}].");
        }

        $row = Blade::render(
            '<x-vs.evidence-row ext="PDF" title="Engagement letter" meta="Intake packet" anchor="p. 12" />',
        );
        foreach (['vs-evidence', 'vs-evidence__icon', 'PDF', 'Engagement letter', 'Intake packet', 'vs-evidence__anchor', 'p. 12'] as $needle) {
            $this->assertStringContainsString($needle, $row, "Evidence row is missing [{$needle}].");
        }

        // Token-only rule (door 1 extended): no raw hex, rgba, or hsl value
        // anywhere in the vs component views.
        $files = glob(resource_path('views/components/vs/*.blade.php'));
        $this->assertNotFalse($files);
        $this->assertGreaterThanOrEqual(12, count($files), 'The vs component inventory is incomplete.');
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression(
                '/#[0-9a-fA-F]{3,8}\b|rgba?\s*\(|hsla?\s*\(/',
                $source,
                basename($file).' carries a raw color value — components reference tokens only.'
            );
        }

        // The component classes the views emit are defined in the compiled
        // CSS (the vs component layer in app.css).
        $css = $this->compiledAppCss();
        foreach (['.vs-btn', '.vs-pill', '.vs-tab', '.vs-state', '.vs-band', '.vs-stage', '.vs-protocol', '.vs-checkpoint', '.vs-composer', '.vs-evidence', '.vs-count', '.vs-card'] as $class) {
            $this->assertStringContainsString($class, $css, "Compiled CSS is missing the {$class} component styles.");
        }
    }

    public function test_vs_components_render_their_contract_states(): void
    {
        // Button variants + disabled + link form.
        $this->assertStringContainsString('vs-btn--primary', Blade::render('<x-vs.button variant="primary">Go</x-vs.button>'));
        $this->assertStringContainsString('vs-btn--danger', Blade::render('<x-vs.button variant="danger">Delete</x-vs.button>'));
        $this->assertStringContainsString('vs-btn--ghost', Blade::render('<x-vs.button variant="ghost">Preview</x-vs.button>'));
        $this->assertStringContainsString('disabled', Blade::render('<x-vs.button disabled>Go</x-vs.button>'));
        $this->assertStringContainsString('<a ', Blade::render('<x-vs.button href="/home">Home</x-vs.button>'));

        // Pill tones map to signal tokens.
        foreach (['acc', 'ok', 'warn', 'bad', 'cyan', 'ghost'] as $tone) {
            $this->assertStringContainsString("vs-pill--{$tone}", Blade::render("<x-vs.pill tone=\"{$tone}\">Label</x-vs.pill>"), "Pill tone {$tone} did not render.");
        }

        // Selected tab takes the accent surface.
        $this->assertStringContainsString('vs-tab--on', Blade::render('<x-vs.tab selected>Platform</x-vs.tab>'));
        $this->assertStringContainsString('aria-current="true"', Blade::render('<x-vs.tab href="/platform" selected>Platform</x-vs.tab>'));

        // State chips: the style guide's language, never renamed — each
        // canonical state renders its tone class and its label text.
        $states = [
            'established' => ['vs-state--ok', 'ESTABLISHED'],
            'satisfied' => ['vs-state--ok', 'SATISFIED'],
            'asserted' => ['vs-state--cyan', 'ASSERTED'],
            'viable' => ['vs-state--cyan', 'VIABLE'],
            'disputed' => ['vs-state--warn', 'DISPUTED'],
            'open' => ['vs-state--warn', 'OPEN'],
            'refuted' => ['vs-state--bad', 'REFUTED'],
            'excluded' => ['vs-state--bad', 'EXCLUDED'],
            'held' => ['vs-state--warn-outline', 'HELD'],
            'checkpoint' => ['vs-state--warn-outline', 'CHECKPOINT'],
        ];
        foreach ($states as $state => [$class, $label]) {
            $html = Blade::render("<x-vs.state state=\"{$state}\" />");
            $this->assertStringContainsString($class, $html, "State {$state} rendered the wrong tone.");
            $this->assertStringContainsString($label, $html, "State {$state} did not render its label.");
        }

        // Bands: one per level, canonical labels.
        $bands = [
            'strong' => ['vs-band--strong', 'STRONG'],
            'viable' => ['vs-band--viable', 'VIABLE'],
            'gray' => ['vs-band--gray', 'GRAY ZONE'],
            'failing' => ['vs-band--failing', 'FAILING'],
        ];
        foreach ($bands as $level => [$class, $label]) {
            $html = Blade::render("<x-vs.band level=\"{$level}\" />");
            $this->assertStringContainsString($class, $html, "Band {$level} rendered the wrong class.");
            $this->assertStringContainsString($label, $html, "Band {$level} did not render its label.");
        }

        // Checkpoint: awaiting vs resolved, with decision actions.
        $awaiting = Blade::render('<x-vs.checkpoint>Decide.<x-slot:actions><x-vs.button variant="primary">Hold</x-vs.button></x-slot:actions></x-vs.checkpoint>');
        $this->assertStringContainsString('vs-checkpoint--awaiting', $awaiting);
        $this->assertStringContainsString('vs-checkpoint__actions', $awaiting);
        $this->assertStringContainsString('vs-btn--primary', $awaiting);
        $resolved = Blade::render('<x-vs.checkpoint state="resolved">Held.</x-vs.checkpoint>');
        $this->assertStringContainsString('vs-checkpoint--resolved', $resolved);

        // Composer states: empty (send disabled), typing, sending, disabled.
        $this->assertStringContainsString('vs-composer--empty', Blade::render('<x-vs.composer />'));
        $this->assertStringContainsString('vs-composer--typing', Blade::render('<x-vs.composer value="Draft text" />'));
        $this->assertStringContainsString('vs-composer--sending', Blade::render('<x-vs.composer state="sending" value="Draft text" />'));
        $disabled = Blade::render('<x-vs.composer state="disabled" />');
        $this->assertStringContainsString('vs-composer--disabled', $disabled);
        $this->assertStringContainsString('disabled', $disabled);

        // Evidence row flagged state carries the warn tag.
        $flagged = Blade::render('<x-vs.evidence-row title="Scan" flagged />');
        $this->assertStringContainsString('vs-evidence--flagged', $flagged);
        $this->assertStringContainsString('vs-tag--warn', $flagged);
        $this->assertStringContainsString('Low confidence', $flagged);
    }

    /**
     * Ticket 2 token-layer completion: --acc-ink, the text color on an
     * --acc surface, transcribed from the per-theme .btn.primary
     * overrides in tokens.md (the leftover Ticket 1 deferred — component
     * rules, not token values). Every theme defines it; themes whose
     * source defines no override carry the library default, white.
     */
    public function test_acc_ink_token_defined_for_every_theme(): void
    {
        $css = $this->compiledAppCss();
        foreach (self::THEME_SLUGS as $slug) {
            $block = $this->themeTokens($css, $slug);
            $this->assertNotNull($block, "Theme {$slug} is missing.");
            $this->assertArrayHasKey('--acc-ink', $block, "Theme {$slug} does not define --acc-ink.");
        }

        $ocean = $this->themeTokens($css, 'ocean-deep');
        $this->assertSame('#032630', $ocean['--acc-ink'] ?? null, 'Ocean Deep --acc-ink must be the tokens.md override value.');
        $aurora = $this->themeTokens($css, 'aurora');
        $this->assertSame('#fff', $aurora['--acc-ink'] ?? null, 'Aurora defines no override — --acc-ink must be the library default.');
        $grove = $this->themeTokens($css, 'earth-grove');
        $this->assertSame('#0b0f0a', $grove['--acc-ink'] ?? null, 'Earth Grove --acc-ink must be the shared grove/midnight override value.');
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
