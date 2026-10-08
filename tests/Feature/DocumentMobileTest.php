<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 007 T-10 verdict: test_mobile_layout (C-15, 007-D04).
 *
 * Every 007 screen renders at 390px (headless Chromium) with:
 * - no horizontal page overflow,
 * - all primary actions reachable and ≥44px in their smallest dimension,
 * - tables scrolling inside their cards (never the page).
 *
 * Screenshots land in specs/007-document-management/10-mobile-*.png for
 * visual comparison against 05-ui-mockup.html.
 *
 * NOTE: On the dev server Chromium runs as a snap; it can only read/write
 * under /root, so standalone HTML + screenshots stage in /root/vl-mobile-test/
 * there. Elsewhere (CI) they stage in the system temp dir. The PNGs are
 * copied into the spec folder afterwards either way.
 */
class DocumentMobileTest extends TestCase
{
    use RefreshDatabase;

    private const WIDTH = 390;

    private const CHROMIUM = '/usr/bin/chromium-browser';

    private FixtureLoader $loader;

    private string $stageDir;

    private string $specDir;

    /**
     * Headless-browser binary: CHROMIUM_BIN env var (CI provides Chrome via
     * browser-actions/setup-chrome) or the dev server default. When no
     * binary is executable the visual gate skips — the layout assertions
     * need a real renderer.
     */
    private string $chromiumBin;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('filesystems.disks.documents', [
            'driver' => 'local',
            'root' => sys_get_temp_dir().'/visionlaw-test-documents',
            'throw' => true,
            'report' => false,
        ]);
        Config::set('document.disk', 'documents');
        Storage::forgetDisk('documents');

        $this->loader = FixtureLoader::loadDocumentFixtures();
        $this->actingAs($this->loader->docUser('g.grant@sterling.example'));

        // Stage where the current user can write: /root on the dev server
        // (its snap-packaged Chromium can only read/write under /root),
        // the system temp dir anywhere else (CI runners are not root).
        $this->stageDir = is_writable('/root')
            ? '/root/vl-mobile-test'
            : sys_get_temp_dir().'/vl-mobile-test';
        $this->specDir = base_path('specs/007-document-management');
        if (! is_dir($this->stageDir)) {
            mkdir($this->stageDir, 0700, true);
        }
    }

    public function test_mobile_layout(): void
    {
        $this->chromiumBin = getenv('CHROMIUM_BIN') ?: self::CHROMIUM;

        if (! is_executable($this->chromiumBin)) {
            $this->markTestSkipped('No headless-browser binary at '.$this->chromiumBin);
        }

        $matter = $this->loader->docMatter('MAT-2026-001');
        $m = (string) $matter->getKey();

        // A PDF document for the preview / versions / share / activity screens.
        $upload = $this->post(
            "/matters/{$m}/documents/upload",
            ['file' => $this->uploadedFile($this->pdfBytes('Mobile gate'), 'mobile-gate.pdf'), 'title' => 'Mobile gate'],
            ['Accept' => 'application/json']
        );
        $upload->assertCreated();
        $docId = (string) $upload->json('data.id');

        // An authored document for the editor screen.
        $authored = $this->post(
            route('documents.authored.store', [$m]),
            ['title' => 'Mobile editor doc'],
            ['Accept' => 'application/json']
        );
        // The store redirects (HTML flow); resolve the document by title.
        $authoredDoc = Document::query()
            ->where('matter_id', $matter->getKey())
            ->where('title', 'Mobile editor doc')
            ->firstOrFail();

        $template = DocumentTemplate::query()
            ->where('name', 'Engagement letter')
            ->firstOrFail();

        $screens = [
            'upload' => route('documents.upload.form', [$m]),
            'list' => route('documents.index', [$m]),
            'preview' => route('documents.preview', [$m, $docId]),
            'versions' => route('documents.versions.index', [$m, $docId]),
            'editor' => route('documents.editor.edit', [$m, $authoredDoc->getKey()]),
            'template-form' => route('templates.generate.form', [$template->getKey()]).'?matter_id='.$m,
            'share' => route('documents.share', [$m, $docId]),
            'activity' => route('documents.activity', [$m, $docId]),
            'retention-policies' => route('admin.retention.policies.index'),
            'retention-disposition' => route('admin.retention.disposition.index'),
            'retention-holds' => route('admin.retention.holds.index'),
        ];

        foreach ($screens as $name => $url) {
            $response = $this->get($url);
            $response->assertOk("screen {$name} ({$url}) did not render 200");

            $metrics = $this->renderAndMeasure($name, $response->getContent());

            $this->assertFalse(
                $metrics['overflow'],
                "screen {$name}: horizontal page overflow (scrollWidth {$metrics['pageScrollWidth']}px > ".self::WIDTH.'px)'
            );
            $this->assertSame(
                [],
                $metrics['smallTargets'],
                'screen '.$name.': touch targets under 44px: '.implode('; ', $metrics['smallTargets'])
            );
            $this->assertSame(
                [],
                $metrics['looseTables'],
                'screen '.$name.': tables not scrolling inside their card: '.implode('; ', $metrics['looseTables'])
            );

            $shot = "{$this->specDir}/10-mobile-{$name}.png";
            $this->assertFileExists($shot, "screen {$name}: screenshot missing");
            $this->assertGreaterThan(2048, filesize($shot), "screen {$name}: screenshot suspiciously small");
        }
    }

    /**
     * Build a standalone HTML file (built CSS inlined, external scripts
     * stripped, layout metrics probe injected), render it at 390px, and
     * return the probe's measurements.
     *
     * @return array{overflow: bool, pageScrollWidth: int, smallTargets: list<string>, looseTables: list<string>}
     */
    private function renderAndMeasure(string $name, string $html): array
    {
        $standalone = $this->standaloneHtml($html);
        $base = "{$this->stageDir}/{$name}";
        file_put_contents($base.'.html', $standalone);

        // Screenshot for the spec folder (visual contract vs the mockup).
        $shot = new Process([
            $this->chromiumBin, '--headless=new', '--disable-gpu', '--no-sandbox',
            '--hide-scrollbars',
            '--window-size='.self::WIDTH.',844',
            '--screenshot='.$base.'.png',
            'file://'.$base.'.html',
        ]);
        $shot->setTimeout(90);
        $shot->run();
        $this->assertTrue($shot->isSuccessful(), "chromium screenshot failed for {$name}: ".$shot->getErrorOutput());
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
     * inline scripts, and inject the layout metrics probe.
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

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function uploadedFile(string $bytes, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'vl-test-');
        assert($path !== false);
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function pdfBytes(string $text): string
    {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);

        return "%PDF-1.4\n"
            ."1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>endobj\n"
            ."trailer<</Root 1 0 R>>\n%%EOF";
    }
}
