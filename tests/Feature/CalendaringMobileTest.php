<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * C-20 mobile gate (009 T-18, 007-D04): the agenda and the home
 * deadlines module at 390px — no horizontal overflow, agenda list
 * and home module rendered, actions reachable without hover.
 *
 * Technique mirrors tests/Feature/AppHomeMobileTest (spec 010) /
 * DocumentMobileTest (spec 007) exactly: page HTML from the test
 * client is standalone-ized (built CSS inlined, external scripts
 * stripped, metrics probe injected), rendered at 390px in headless
 * Chromium, and asserted for no horizontal page overflow, primary
 * actions ≥44px, and tables scrolling inside their cards. Staging
 * is in the system temp dir only; screenshots are copied into
 * specs/009-calendaring/ as evidence. The Chromium binary comes
 * from CHROMIUM_BIN (CI provides Chrome) or the dev-server
 * default; when no binary is executable — or the available binary
 * cannot read the staged files — the gate skips gracefully rather
 * than failing on environment.
 */
class CalendaringMobileTest extends TestCase
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

        $this->loader = FixtureLoader::loadCalendaringFixtures();
        // Stage where the snap-packaged Chromium can read: /root on
        // the dev server, the system temp dir anywhere else (the
        // 007 staging choice — 010's sys-temp staging skips on this
        // host for exactly that reason).
        $this->stageDir = is_writable('/root')
            ? '/root/vl009-mobile-test'
            : sys_get_temp_dir().'/vl009-mobile-test';
        $this->specDir = base_path('specs/009-calendaring');
        if (! is_dir($this->stageDir)) {
            mkdir($this->stageDir, 0700, true);
        }
        $this->chromiumBin = getenv('CHROMIUM_BIN') ?: self::CHROMIUM;
    }

    public function test_mobile_agenda_gate(): void
    {
        if (! is_executable($this->chromiumBin)) {
            $this->markTestSkipped('No headless-browser binary at '.$this->chromiumBin);
        }

        $attorney = $this->loader->user('user-attorney');

        $screens = [
            'agenda' => $this->actingAs($attorney)->get(route('calendar.index', ['mode' => 'list'])),
            'home' => $this->actingAs($attorney)->get(route('app.home')),
        ];

        // Server-rendered content: the agenda list and the home
        // deadlines module both render for the attorney.
        $screens['agenda']->assertOk();
        $screens['agenda']->assertSee('Summary judgment response due');
        $screens['agenda']->assertSee('Board report deadline');
        $screens['home']->assertOk();
        $screens['home']->assertSee('Deadlines & agenda');
        $screens['home']->assertSee('Board report deadline');

        foreach ($screens as $name => $response) {
            $html = (string) $response->getContent();
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
    }

    /**
     * Build a standalone HTML file (built CSS inlined, external scripts
     * stripped, layout metrics probe injected), render it at 390px, and
     * return the probe's measurements. Mirrors AppHomeMobileTest.
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
