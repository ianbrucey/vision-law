<?php

namespace Tests\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Spec 002 T-06 — the enforced doors (docs/UI_Standards.md, "Enforced doors" §).
 *
 * C-05: this suite is green on the clean tree, AND every door trips on a
 * violating fixture. Each negative test copies the views tree to a temp dir,
 * injects ONE violating snippet, and asserts that door's scanner flags it —
 * proving the door trips instead of silently passing on a clean tree. The
 * real tree is never polluted: temp dirs are deleted after each negative test.
 *
 * Deliberate scope notes:
 * - 003 freeze (T-07): resources/views/welcome.blade.php was DELETED in 003
 *   T-02 — its 002-D08 exemptions (doors 1/4/7) died with it. The new
 *   door-4 exemption is resources/views/public/*: the landing is a fixed
 *   marketing layout whose hero h1 is the page's single heading per the
 *   approved mockup (06-plan.md T-02/T-07).
 * - layouts/app.blade.php is the sanctioned home of the nav drawer (door 5):
 *   the drawer is layout infrastructure required by the mobile standards —
 *   keyboard-operable, Escape-closes, focus-trapped — not an ad-hoc dialog.
 * - Door 8 (leak sentinels) from UI_Standards.md is NOT re-implemented here:
 *   it is already enforced by tests/Architecture/LeakSentinelTest.php
 *   (spec 001, verdict C-14). This ticket's "7 doors" are the 7 in 06-plan.md.
 */
class VisionUiTest extends TestCase
{
    private string $viewsRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->viewsRoot = (string) resource_path('views');
    }

    // ------------------------------------------------------------------
    // Scanners — each returns "relative/path:line" strings for offenders.
    // ------------------------------------------------------------------

    /** @return list<string> absolute paths of every Blade file under $root */
    private function bladeFiles(string $root): array
    {
        $files = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @param  callable(string $relativePath, string $line): bool  $flags
     * @return list<string> "relative/path:line" for each flagged line
     */
    private function scan(string $root, callable $flags): array
    {
        $offenders = [];
        foreach ($this->bladeFiles($root) as $path) {
            $relative = ltrim(substr($path, strlen($root)), '/\\');
            $lines = explode("\n", (string) file_get_contents($path));
            foreach ($lines as $index => $line) {
                if ($flags($relative, $line)) {
                    $offenders[] = $relative.':'.($index + 1);
                }
            }
        }

        return $offenders;
    }

    /** Door 1 — no raw hex in Blade views (tokens only). */
    private function door1Hex(string $root): array
    {
        return $this->scan(
            $root,
            fn (string $relative, string $line): bool => (bool) preg_match('/#[0-9a-fA-F]{6}\b/', $line)
        );
    }

    /** Door 2 — buttons only via <x-ui.button> (mail templates exempt). */
    private function door2Buttons(string $root): array
    {
        return $this->scan($root, function (string $relative, string $line): bool {
            if (str_starts_with($relative, 'components/ui/') || str_starts_with($relative, 'mail/')) {
                return false;
            }

            // Raw <button> tag, or any non-x-ui.button component whose name
            // ends in "button" (e.g. <x-button>, <x-ui-button>).
            return (bool) preg_match('/<button[\s>]/i', $line)
                || (bool) preg_match('/<x-(?!ui\.button\b)[A-Za-z0-9._-]*button[\s>]/i', $line);
        });
    }

    /**
     * Door 3 — form controls only via the four wrappers (hidden inputs exempt).
     */
    private function door3Controls(string $root): array
    {
        return $this->scan($root, function (string $relative, string $line): bool {
            if (str_starts_with($relative, 'components/ui/')) {
                return false;
            }
            if (preg_match('/<select[\s>]/i', $line) || preg_match('/<textarea[\s>]/i', $line)) {
                return true;
            }
            if (preg_match('/<input[\s>]/i', $line)) {
                // Hidden inputs are exempt.
                return ! (bool) preg_match('/<input[^>]*\btype=(["\']?)hidden\1/i', $line);
            }

            // Third-party kit controls (<x-kit-input> etc.) are not allowed either.
            return (bool) preg_match('/<x-(?!ui\.(field|select|checkbox|textarea)\b)[A-Za-z0-9._-]*(?:input|select|textarea|checkbox)[\s>]/i', $line);
        });
    }

    /**
     * Door 4 — page titles only via <x-ui.page-header> (auth views and the
     * public/ marketing landing exempt).
     *
     * Spec 003 T-02/T-07: the landing is a fixed marketing layout — its hero
     * h1 is the page's single heading per the approved mockup (exemption
     * recorded per 06-plan.md T-02/T-07; supersedes welcome.blade.php's
     * deleted 002-D08 door-4 exemption).
     */
    private function door4Titles(string $root): array
    {
        return $this->scan($root, function (string $relative, string $line): bool {
            if (str_starts_with($relative, 'components/ui/')
                || str_starts_with($relative, 'auth/')
                || str_starts_with($relative, 'public/')) {
                return false;
            }

            return (bool) preg_match('/<h1[\s>]/i', $line);
        });
    }

    /**
     * Door 5 — dialogs only via <x-ui.modal>. The shell's nav drawer is the
     * sanctioned exception: layout infrastructure, not an ad-hoc dialog.
     */
    private function door5Dialogs(string $root): array
    {
        return $this->scan($root, function (string $relative, string $line): bool {
            if (str_starts_with($relative, 'components/ui/') || $relative === 'layouts/app.blade.php') {
                return false;
            }

            return (bool) preg_match('/role=["\']dialog["\']/', $line);
        });
    }

    /** Door 6 — wire:poll only inside components/ui/. */
    private function door6Poll(string $root): array
    {
        return $this->scan(
            $root,
            fn (string $relative, string $line): bool => ! str_starts_with($relative, 'components/ui/')
                && str_contains($line, 'wire:poll')
        );
    }

    /** Door 7 — light-only: no dark: variants, no class="dark". */
    private function door7LightOnly(string $root): array
    {
        return $this->scan($root, function (string $relative, string $line): bool {
            return (bool) preg_match('/\bdark:/', $line)
                || (bool) preg_match('/class=["\']dark["\']/', $line);
        });
    }

    // ------------------------------------------------------------------
    // Negative-fixture helpers — the real tree is never touched.
    // ------------------------------------------------------------------

    /** Copy the views tree to a temp dir and append one violating snippet. */
    private function treeWithViolation(string $relativeFile, string $snippet): string
    {
        $tmp = sys_get_temp_dir().'/vision-ui-doors-'.uniqid('', true);
        mkdir($tmp, 0777, true);

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->viewsRoot, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $target = $tmp.'/'.ltrim(substr($item->getPathname(), strlen($this->viewsRoot)), '/');
            if ($item->isDir()) {
                mkdir($target, 0777, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }

        $path = $tmp.'/'.$relativeFile;
        file_put_contents($path, (string) file_get_contents($path)."\n".$snippet."\n");

        return $tmp;
    }

    private function removeDir(string $dir): void
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    // ------------------------------------------------------------------
    // Door 1 — no raw hex
    // ------------------------------------------------------------------

    public function test_door_1_no_raw_hex_in_views(): void
    {
        $this->assertSame([], $this->door1Hex($this->viewsRoot));
    }

    public function test_door_1_trips_on_raw_hex_fixture(): void
    {
        $tmp = $this->treeWithViolation('patterns.blade.php', '<p style="color: #1F2A37">violating hex</p>');
        try {
            $offenders = $this->door1Hex($tmp);
            $this->assertNotEmpty($offenders, 'Door 1 did not trip on injected raw hex.');
            $this->assertStringContainsString('patterns.blade.php', $offenders[0]);
        } finally {
            $this->removeDir($tmp);
        }
    }

    // ------------------------------------------------------------------
    // Door 2 — buttons only via x-ui.button
    // ------------------------------------------------------------------

    public function test_door_2_buttons_only_via_x_ui_button(): void
    {
        $this->assertSame([], $this->door2Buttons($this->viewsRoot));
    }

    public function test_door_2_trips_on_raw_button_fixture(): void
    {
        $tmp = $this->treeWithViolation('patterns.blade.php', '<button class="btn">Raw button</button>');
        try {
            $offenders = $this->door2Buttons($tmp);
            $this->assertNotEmpty($offenders, 'Door 2 did not trip on injected raw <button>.');
            $this->assertStringContainsString('patterns.blade.php', $offenders[0]);
        } finally {
            $this->removeDir($tmp);
        }
    }

    // ------------------------------------------------------------------
    // Door 3 — form controls only via the four wrappers
    // ------------------------------------------------------------------

    public function test_door_3_form_controls_only_via_wrappers(): void
    {
        $this->assertSame([], $this->door3Controls($this->viewsRoot));
    }

    public function test_door_3_trips_on_raw_input_fixture(): void
    {
        $tmp = $this->treeWithViolation('patterns.blade.php', '<input type="text" name="q" />');
        try {
            $offenders = $this->door3Controls($tmp);
            $this->assertNotEmpty($offenders, 'Door 3 did not trip on injected raw <input>.');
            $this->assertStringContainsString('patterns.blade.php', $offenders[0]);
        } finally {
            $this->removeDir($tmp);
        }
    }

    // ------------------------------------------------------------------
    // Door 4 — page titles only via x-ui.page-header
    // ------------------------------------------------------------------

    public function test_door_4_page_titles_only_via_x_ui_page_header(): void
    {
        $this->assertSame([], $this->door4Titles($this->viewsRoot));
    }

    public function test_door_4_trips_on_raw_h1_fixture(): void
    {
        $tmp = $this->treeWithViolation('patterns.blade.php', '<h1>Raw title</h1>');
        try {
            $offenders = $this->door4Titles($tmp);
            $this->assertNotEmpty($offenders, 'Door 4 did not trip on injected raw <h1>.');
            $this->assertStringContainsString('patterns.blade.php', $offenders[0]);
        } finally {
            $this->removeDir($tmp);
        }
    }

    /**
     * Spec 003 T-07 freeze: the public/ marketing exemption is exercised —
     * an h1 inside a public/ view must NOT trip door 4.
     */
    public function test_door_4_public_views_exempt_from_h1_rule(): void
    {
        $tmp = $this->treeWithViolation('public/landing.blade.php', '<h1>Hero headline</h1>');
        try {
            $this->assertSame([], $this->door4Titles($tmp));
        } finally {
            $this->removeDir($tmp);
        }
    }

    // ------------------------------------------------------------------
    // Door 5 — dialogs only via x-ui.modal
    // ------------------------------------------------------------------

    public function test_door_5_dialogs_only_via_x_ui_modal(): void
    {
        $this->assertSame([], $this->door5Dialogs($this->viewsRoot));
    }

    public function test_door_5_trips_on_hand_rolled_dialog_fixture(): void
    {
        $tmp = $this->treeWithViolation(
            'patterns.blade.php',
            '<div x-data="{ open: false }" role="dialog" aria-modal="true">Hand-rolled dialog</div>'
        );
        try {
            $offenders = $this->door5Dialogs($tmp);
            $this->assertNotEmpty($offenders, 'Door 5 did not trip on injected hand-rolled dialog.');
            $this->assertStringContainsString('patterns.blade.php', $offenders[0]);
        } finally {
            $this->removeDir($tmp);
        }
    }

    // ------------------------------------------------------------------
    // Door 6 — wire:poll only inside components/ui/
    // ------------------------------------------------------------------

    public function test_door_6_wire_poll_only_inside_components_ui(): void
    {
        $this->assertSame([], $this->door6Poll($this->viewsRoot));
    }

    public function test_door_6_trips_on_wire_poll_fixture(): void
    {
        $tmp = $this->treeWithViolation('patterns.blade.php', '<div wire:poll.5s="refresh">Polling</div>');
        try {
            $offenders = $this->door6Poll($tmp);
            $this->assertNotEmpty($offenders, 'Door 6 did not trip on injected wire:poll.');
            $this->assertStringContainsString('patterns.blade.php', $offenders[0]);
        } finally {
            $this->removeDir($tmp);
        }
    }

    // ------------------------------------------------------------------
    // Door 7 — light-only
    // ------------------------------------------------------------------

    public function test_door_7_light_only_no_dark_variants(): void
    {
        $this->assertSame([], $this->door7LightOnly($this->viewsRoot));
    }

    public function test_door_7_trips_on_dark_variant_fixture(): void
    {
        $tmp = $this->treeWithViolation('patterns.blade.php', '<p class="dark:text-white">Dark variant</p>');
        try {
            $offenders = $this->door7LightOnly($tmp);
            $this->assertNotEmpty($offenders, 'Door 7 did not trip on injected dark: variant.');
            $this->assertStringContainsString('patterns.blade.php', $offenders[0]);
        } finally {
            $this->removeDir($tmp);
        }
    }
}
