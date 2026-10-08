<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\Helpers\FixtureLoader;
use Tests\Helpers\VirtualAuthenticator;
use Tests\TestCase;

/**
 * Spec 008 T-04 verdict: test_mobile_layout_includes_passkey_screens
 * (C-10, 008-D09 — the standing mobile-first rule).
 *
 * The three new 008 screens — enrollment page with an empty passkey
 * section, enrollment page with a passkey listed, and the login
 * challenge with the passkey section — render at 390px (headless
 * Chromium) with no horizontal page overflow and every action ≥44px,
 * using the same probe as spec 007's DocumentMobileTest (008-D09),
 * which is deliberately NOT edited.
 *
 * Screenshots land in specs/008-passkey-authentication/10-mobile-*.png.
 * Staging follows 007 exactly: /root on the dev server (snap Chromium),
 * the system temp dir elsewhere (CI), CHROMIUM_BIN honored.
 */
class PasskeyMobileTest extends TestCase
{
    use RefreshDatabase;

    private const WIDTH = 390;

    private const CHROMIUM = '/usr/bin/chromium-browser';

    private string $stageDir;

    private string $specDir;

    private string $chromiumBin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stageDir = is_writable('/root')
            ? '/root/vl-mobile-test'
            : sys_get_temp_dir().'/vl-mobile-test';
        $this->specDir = base_path('specs/008-passkey-authentication');
        if (! is_dir($this->stageDir)) {
            mkdir($this->stageDir, 0700, true);
        }
    }

    public function test_mobile_layout_includes_passkey_screens(): void
    {
        $this->chromiumBin = getenv('CHROMIUM_BIN') ?: self::CHROMIUM;

        if (! is_executable($this->chromiumBin)) {
            $this->markTestSkipped('No headless-browser binary at '.$this->chromiumBin);
        }

        $user = $this->makeUser('pk-mobile@sterling.test');

        // Screen 1: enrollment page, empty passkey section.
        $this->actingAs($user);
        $screens = [
            'enrollment-empty' => $this->get(route('two-factor.settings'))
                ->assertOk()->getContent(),
        ];

        // Screen 2: enrollment page with a passkey listed (the second
        // visit — the first renders the recovery-codes once-display).
        $authenticator = VirtualAuthenticator::make();
        $options = $this->postJson(route('passkeys.register.options'))->assertOk()->json();
        $this->postJson(
            route('passkeys.register'),
            $authenticator->attest($options) + ['alias' => 'iPhone']
        )->assertOk();
        $this->get(route('two-factor.settings'))->assertOk();
        $screens['enrollment-listed'] = $this->get(route('two-factor.settings'))
            ->assertOk()->getContent();

        // Screen 3: the login challenge with the passkey section.
        $this->post(route('logout'));
        $this->post('/login', [
            'email' => $user->email,
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ])->assertRedirect(route('two-factor.login'));
        $screens['challenge'] = $this->get(route('two-factor.login'))
            ->assertOk()->getContent();

        foreach ($screens as $name => $html) {
            $this->assertNotSame('', $html, "screen {$name}: empty render");

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

            $shot = "{$this->specDir}/10-mobile-{$name}.png";
            $this->assertFileExists($shot, "screen {$name}: screenshot missing");
            $this->assertGreaterThan(2048, filesize($shot), "screen {$name}: screenshot suspiciously small");
        }
    }

    private function makeUser(string $email): User
    {
        $loader = FixtureLoader::load();
        $orgId = $loader->id('org_sterling');

        $user = User::create([
            'org_id' => $orgId,
            'name' => 'Mobile User',
            'email' => $email,
            'password' => FixtureLoader::DEFAULT_PASSWORD,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole(
            Role::where('org_id', $orgId)->where('name', 'attorney')->firstOrFail()
        );

        return $user;
    }

    /**
     * Build a standalone HTML file (built CSS inlined, external scripts
     * stripped, layout metrics probe injected), render it at 390px, and
     * return the probe's measurements. Same technique as
     * DocumentMobileTest (008-D09).
     *
     * @return array{overflow: bool, pageScrollWidth: int, smallTargets: list<string>}
     */
    private function renderAndMeasure(string $name, string $html): array
    {
        $standalone = $this->standaloneHtml($html);
        $base = "{$this->stageDir}/passkey-{$name}";
        file_put_contents($base.'.html', $standalone);

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

        $html = (string) preg_replace(
            '#<link[^>]+href="/build/[^"]+\.css"[^>]*>#',
            '',
            $html
        );
        $html = str_replace('</head>', "<style>\n".$css."\n</style>\n</head>", $html);

        $html = (string) preg_replace('#<script[^>]+src="[^"]*"[^>]*></script>#', '', $html);

        $probe = <<<'JS'
<script>
(function () {
    var res = { overflow: false, pageScrollWidth: 0, smallTargets: [] };
    res.pageScrollWidth = document.documentElement.scrollWidth;
    res.overflow = res.pageScrollWidth > window.innerWidth + 1;
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
