<?php

namespace Tests\Feature;

use Tests\TestCase;

class DesignTokensTest extends TestCase
{
    /**
     * C-01: every --vl-* token named in specs/002-ui-foundation/03-contract.md
     * must exist in the compiled app.css.
     */
    public function test_all_vl_design_tokens_defined(): void
    {
        // Token table, 03-contract.md "Tokens (the single source)" — exact names.
        $tokens = [
            '--vl-ink',
            '--vl-ink-2',
            '--vl-brass',
            '--vl-brass-soft',
            '--vl-paper',
            '--vl-card',
            '--vl-line',
            '--vl-text',
            '--vl-mut',
            '--vl-ok',
            '--vl-ok-soft',
            '--vl-bad',
            '--vl-bad-soft',
            '--vl-info',
            '--vl-info-soft',
        ];

        $css = $this->compiledAppCss();

        foreach ($tokens as $token) {
            $this->assertStringContainsString(
                $token,
                $css,
                "Design token {$token} is missing from the compiled app.css."
            );
        }
    }

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
}
