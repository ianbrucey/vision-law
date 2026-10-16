<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 010 T-06 verdict: test_mobile_home_and_chrome_gate (C-10, 007-D04).
 *
 * Technique mirrors tests/Feature/DocumentMobileTest (spec 007): page HTML
 * from the test client is standalone-ized (built CSS inlined, external
 * scripts stripped, metrics probe injected), rendered at 390px in headless
 * Chromium, and asserted for: no horizontal page overflow, primary actions
 * ≥44px, tables scrolling inside their cards. On top of the 007 metrics,
 * C-10 pins the chrome contract itself: every nav target and the sign-out
 * form are present in the (server-rendered) drawer markup, and the home
 * modules stack in DOM order matters → activity → security.
 *
 * Staging is in the system temp dir only. Screenshots are copied into
 * specs/010-app-home/ as evidence, as with 007. The Chromium binary comes
 * from CHROMIUM_BIN (CI provides Chrome) or the dev-server default; when
 * no binary is executable — or the available binary cannot read the staged
 * files (e.g. a confined snap build) — the gate skips gracefully rather
 * than failing on environment.
 */
class AppHomeMobileTest extends TestCase
{
    use RefreshDatabase;

    private const WIDTH = 390;

    private const CHROMIUM = '/usr/bin/chromium-browser';

    private FixtureLoader $loader;

    private string $stageDir;

    private string $specDir;

    private string $chromiumBin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = FixtureLoader::loadHomeFixtures();
        $this->stageDir = sys_get_temp_dir().'/vl010-mobile-test';
        $this->specDir = base_path('specs/010-app-home');
        if (! is_dir($this->stageDir)) {
            mkdir($this->stageDir, 0700, true);
        }
        $this->chromiumBin = getenv('CHROMIUM_BIN') ?: self::CHROMIUM;
    }

    public function test_mobile_home_and_chrome_gate(): void
    {
        if (! is_executable($this->chromiumBin)) {
            $this->markTestSkipped('No headless-browser binary at '.$this->chromiumBin);
        }

        $admin = $this->loader->user('user-home-admin');
        $lone = $this->loader->user('user-lone');

        $screens = [
            'home' => $this->actingAs($admin)->get(route('app.home')),
            'home-empty' => $this->actingAs($lone)->get(route('app.home')),
            'matters' => $this->actingAs($admin)->get(route('matters.index')),
        ];

        // C-10 chrome reachability at 390px: the drawer carries the whole
        // standard nav plus the sign-out form (server-rendered markup —
        // the drawer toggle itself is a ≥44px button, probed below).
        foreach ($screens as $name => $response) {
            $response->assertOk("screen {$name} did not render 200");
            $html = (string) $response->getContent();

            foreach ([route('app.home'), route('matters.index'), route('templates.index'), route('two-factor.settings')] as $href) {
                $this->assertStringContainsString('href="'.$href.'"', $html, "screen {$name}: nav target {$href} missing from the page/drawer markup");
            }
            $this->assertStringContainsString('action="'.route('logout').'"', $html, "screen {$name}: sign-out form missing from the chrome");

            $metrics = $this->renderAndMeasure($name, $html);

            $this->assertFalse(
                $metrics['overflow'],
                "screen {$name}: horizontal page overflow (scrollWidth {$metrics['pageScrollWidth']}px > ".self::WIDTH.'px)'
            );
            $this->assertSame(
                [],
                $metrics['smallTargets'],
                "screen {$name}: touch targets under 44px: ".implode('; ', $metrics['smallTargets'])
            );
            $this->assertSame(
                [],
                $metrics['looseTables'],
                "screen {$name}: tables not scrolling inside their card: ".implode('; ', $metrics['looseTables'])
            );

            $shot = "{$this->specDir}/10-mobile-{$name}.png";
            $this->assertFileExists($shot, "screen {$name}: screenshot missing");
            $this->assertGreaterThan(2048, filesize($shot), "screen {$name}: screenshot suspiciously small");
        }

        // Module stacking: at phone width the single-column layout stacks
        // in DOM order — matters → activity → security.
        $homeHtml = (string) $screens['home']->getContent();
        $mattersPos = strpos($homeHtml, 'My matters');
        $activityPos = strpos($homeHtml, 'Recent activity');
        $securityPos = strpos($homeHtml, 'Security status');
        $this->assertTrue($mattersPos !== false && $activityPos !== false && $securityPos !== false, 'home modules missing');
        $this->assertTrue(
            $mattersPos < $activityPos && $activityPos < $securityPos,
            'home modules do not stack matters → activity → security'
        );
    }

    /**
     * Build a standalone HTML file (built CSS inlined, external scripts
     * stripped, layout metrics probe injected), render it at 390px, and
     * return the probe's measurements. Mirrors DocumentMobileTest.
     *
     * @return array{overflow: bool, pageScrollWidth: int, smallTargets: list<string>, looseTables: list<string>}
     */
    private function renderAndMeasure(string $name, string $html): array
    {
        $standalone = $this->standaloneHtml($html);
        $base = "{$this->stageDir}/{$name}";
        file_put_contents($base.'.html', $standalone);

        // Screenshot for the spec folder (visual contract evidence).
        $shot = new Process([
            $this->chromiumBin, '--headless=new', '--disable-gpu', '--no-sandbox',
            '--hide-scrollbars',
            '--window-size='.self::WIDTH.',844',
            '--screenshot='.$base.'.png',
            'file://'.$base.'.html',
        ]);
        $shot->setTimeout(90);
        $shot->run();
        if (! $shot->isSuccessful() || ! is_file($base.'.png')) {
            $this->markTestSkipped(
                'Headless browser could not render the staged files in this environment: '
                .trim($shot->getErrorOutput())
            );
        }
        copy($base.'.png', "{$this->specDir}/10-mobile-{$name}.png");

        // DOM dump carries the metrics probe's JSON.
        $dump = new Process([
            $this->chromiumBin, '--headless=new', '--disable-gpu', '--no-sandbox',
            '--window-size='.self::WIDTH.',844',
            '--dump-dom',
            'file://'.$base.'.html',
        ]);
        $dump->setTimeout(90);
        $dump->run();
        $this->assertTrue($dump->isSuccessful(), "chromium dump-dom failed for {$name}: ".$dump->getErrorOutput());

        $dom = $dump->getOutput();
        $this->assertMatchesRegularExpression(
            '/<div id="layout-metrics"[^>]*>(.*?)<\/div>/s',
            $dom,
            "screen {$name}: metrics probe missing from rendered DOM"
        );
        preg_match('/<div id="layout-metrics"[^>]*>(.*?)<\/div>/s', $dom, $m);
        $metrics = json_decode(html_entity_decode($m[1]), true);
        $this->assertIsArray($metrics, "screen {$name}: metrics probe returned invalid JSON");

        return [
            'overflow' => (bool) ($metrics['overflow'] ?? true),
            'pageScrollWidth' => (int) ($metrics['pageScrollWidth'] ?? 0),
            'smallTargets' => $metrics['smallTargets'] ?? ['probe error'],
            'looseTables' => $metrics['looseTables'] ?? ['probe error'],
        ];
    }

    /**
     * Make the response HTML renderable from file:// — inline the built
     * app CSS, drop external scripts (their srcs don't resolve), keep
     * inline scripts, and inject the layout metrics probe. Mirrors
     * DocumentMobileTest.
     */
    private function standaloneHtml(string $html): string
    {
        $css = '';
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        foreach ((array) $manifest as $entry) {
            if (isset($entry['file']) && str_ends_with((string) $entry['file'], '.css')) {
                $path = public_path('build/'.$entry['file']);
                if (is_file($path)) {
                    $css .= (string) file_get_contents($path)."\n";
                }
            }
        }

        // Swap the build <link> tags for the inlined CSS.
        $html = (string) preg_replace(
            '#<link[^>]+href="/build/[^"]+\.css"[^>]*>#',
            '',
            $html
        );
        $html = str_replace('</head>', "<style>\n".$css."\n</style>\n</head>", $html);

        // External scripts can't resolve from file:// — drop them, keep inline.
        $html = (string) preg_replace('#<script[^>]+src="[^"]*"[^>]*></script>#', '', $html);

        $probe = <<<'JS'
<script>
(function () {
    var res = { overflow: false, pageScrollWidth: 0, smallTargets: [], looseTables: [] };
    res.pageScrollWidth = document.documentElement.scrollWidth;
    res.overflow = res.pageScrollWidth > window.innerWidth + 1;
    // Primary actions: <button>, button-styled anchors (x-ui.button renders
    // inline-flex), and summary disclosures. Plain text links are navigation,
    // not actions, and are out of scope.
    document.querySelectorAll('button, a.inline-flex, summary.inline-flex, input[type="submit"]').forEach(function (el) {
        var r = el.getBoundingClientRect();
        if (r.width === 0 && r.height === 0) return;
        var cs = window.getComputedStyle(el);
        if (cs.display === 'none' || cs.visibility === 'hidden') return;
        if (el.disabled || el.getAttribute('aria-disabled') === 'true') return;
        var minSide = Math.min(r.width, r.height);
        if (minSide < 44) {
            var label = (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 32);
            var cls = (el.getAttribute('class') || '').replace(/\s+/g, ' ').slice(0, 80);
            var id = el.getAttribute('id') || '';
            res.smallTargets.push(el.tagName + (id ? '#' + id : '') + ' "' + label + '" [' + cls + '] ' + Math.round(r.width) + 'x' + Math.round(r.height));
        }
    });
    // Tables must scroll inside their cards, never the page.
    document.querySelectorAll('table').forEach(function (t) {
        var p = t.parentElement, ok = false, depth = 0;
        while (p && depth < 6) {
            var o = window.getComputedStyle(p).overflowX;
            if (o === 'auto' || o === 'scroll') { ok = true; break; }
            p = p.parentElement; depth++;
        }
        if (!ok) {
            var r = t.getBoundingClientRect();
            if (r.width > window.innerWidth + 1) {
                res.looseTables.push('table ' + Math.round(r.width) + 'px wide without an overflow-x scroller');
            }
        }
    });
    var d = document.createElement('div');
    d.id = 'layout-metrics';
    d.style.display = 'none';
    d.textContent = JSON.stringify(res);
    document.body.appendChild(d);
})();
</script>
JS;
        $html = str_replace('</body>', $probe."\n</body>", $html);

        return $html;
    }
}
