<?php

namespace Tests\Feature\Public;

use Tests\TestCase;

/**
 * Spec 013 (00-brief.md) — the rebuilt landing's verdict tests,
 * Ticket 3:
 *
 *   C-01  test_landing_is_generic_vision_systems_with_vision_law_first_pack
 *   C-02  (rendered half) test_landing_default_theme_is_ocean_deep_and_switcher_lists_only_non_light_sets
 *         — the token-layer half is C-02 in tests/Architecture/
 *         VisionSystemsUiTest.php.
 *   C-07  test_no_meta_text_on_rendered_surfaces (landing surface)
 *   F3    test_sample_label_renders_with_the_vignette_sample_data
 *         (013-D14/D15: the fixtures' sample illustration label renders
 *         with the hero vignette's sample data)
 *
 * C-05 (composition) lives in VisionSystemsUiTest; C-06 (browser
 * gates) is Ticket 4.
 *
 * Content assertions read the spec's canonical fixtures
 * (specs/013-vision-systems-ui-landing/04-fixtures.json) — the same
 * file the landing renders from — so the tests pin the page to the
 * fixtures, not to a second copy of the copy.
 */
class LandingVisionSystemsTest extends TestCase
{
    /**
     * The spec 013 fixtures, decoded.
     *
     * @return array<array-key, mixed>
     */
    private function fixtures(): array
    {
        $decoded = json_decode(
            (string) file_get_contents(base_path('specs/013-vision-systems-ui-landing/04-fixtures.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * One level of the fixtures as an array.
     *
     * @param  array<array-key, mixed>  $from
     * @return array<array-key, mixed>
     */
    private function level(array $from, string $key): array
    {
        $value = $from[$key] ?? null;
        $this->assertIsArray($value, "fixtures missing the {$key} level");

        return $value;
    }

    /**
     * C-01: GET / is the generic Vision Systems landing — the platform
     * is the subject, Vision Law is the first pack, the headline is the
     * fixtures' headline, and (013-D14(a)) the agent capability lines
     * appear only in their roadmap framing: the pre-fix present-tense
     * strings from before the fixture-fix commit must not render.
     */
    public function test_landing_is_generic_vision_systems_with_vision_law_first_pack(): void
    {
        $fixtures = $this->fixtures();
        $brand = $this->level($fixtures, 'brand');
        $landing = $this->level($fixtures, 'landing');
        $hero = $this->level($landing, 'hero');
        $packs = $this->level($landing, 'packs');
        $packList = $this->level($packs, 'list');
        $firstPack = $this->level($packList, '0');

        $response = $this->get('/');
        $response->assertOk();
        $response->assertSee((string) $brand['name']);
        $response->assertSee((string) $brand['tagline']);
        $response->assertSee((string) $hero['headline']);

        // Vision Law is the first pack, marked live; the pack order is
        // the fixtures' order.
        $response->assertSee((string) $firstPack['name']);
        $response->assertSee((string) $firstPack['status']);
        $names = [];
        foreach ($packList as $pack) {
            $this->assertIsArray($pack);
            $names[] = (string) $pack['name'];
        }
        $response->assertSeeInOrder($names);

        // The old legal-only positioning is gone.
        $response->assertDontSee('Enterprise legal platform');

        // 013-D14(a): the agent lines render only roadmap-framed.
        $response->assertSee('On the roadmap:');
        $response->assertSee('an agent on the roadmap');
        foreach ([
            // Step 04 body, pre-fix: the agent as a present capability.
            'The agent retrieves from that corpus and cites its pages.',
            // Hero sub, pre-fix: the agent as a shipped capability.
            'gives your team an agent that answers from the record',
            // Step 05 body, pre-fix: the unshipped checkpoint claim.
            'held at a checkpoint until a person approves the exact version',
            // Trust export line, pre-fix: the unshipped deletion claim.
            'Full export in open formats at any time, and deletion at contract end.',
        ] as $preFix) {
            $response->assertDontSee($preFix);
        }
    }

    /**
     * C-02 (rendered half): the server renders the root attribute with
     * the Ocean Deep default (03-contract.md §Theme structure), and the
     * nav switcher offers exactly the 20 non-light theme sets,
     * individually, in fixture order — the five light sets are defined
     * and tested but excluded from the production switcher (013-O02),
     * and no family labels group them (013-O04).
     */
    public function test_landing_default_theme_is_ocean_deep_and_switcher_lists_only_non_light_sets(): void
    {
        $fixtures = $this->fixtures();
        $themes = $this->level($fixtures, 'themes');
        $sets = $this->level($themes, 'sets');

        $expected = [];
        $light = [];
        foreach ($sets as $set) {
            $this->assertIsArray($set);
            if (($set['light'] ?? false) === true) {
                $light[] = (string) $set['slug'];
            } else {
                $expected[] = (string) $set['slug'];
            }
        }
        $this->assertCount(20, $expected);
        $this->assertCount(5, $light);

        $response = $this->get('/');
        $response->assertOk();
        $response->assertSee('<html lang="en"', false);
        $response->assertSee('data-vs-theme="ocean-deep"', false);
        $response->assertSee('<option value="ocean-deep" selected>', false);

        $html = $response->getContent();
        $this->assertIsString($html);
        preg_match_all('/<option value="([^"]+)"/', $html, $matches);
        $this->assertSame($expected, $matches[1], 'The switcher must offer exactly the non-light sets, in fixture order.');
        foreach ($light as $slug) {
            $response->assertDontSee('<option value="'.$slug.'"', false);
        }
    }

    /**
     * C-07 (landing surface): the rendered page carries none of the
     * fixtures' forbidden strings and none of the old landing's
     * placeholder / unshipped-claim defects, which the rebuild retired.
     */
    public function test_no_meta_text_on_rendered_surfaces(): void
    {
        $fixtures = $this->fixtures();
        $forbidden = $this->level($fixtures, 'forbidden_strings');

        $response = $this->get('/');
        $response->assertOk();
        foreach ($forbidden as $string) {
            $this->assertIsString($string);
            $response->assertDontSee($string);
        }

        foreach ([
            // The old landing's placeholder testimonial and its marker.
            'We replaced four systems with Vision Law.',
            'SYNTHETIC PLACEHOLDER — NOT A REAL QUOTE',
            'SYNTHETIC DATA ONLY',
            'AUDIT TRAIL · MAT-2026-001 · LIVE',
            // The old landing's unshipped identity claim (SSO is
            // roadmap language only in spec 013).
            'SSO & SCIM ready',
            // The old landing's remaining signature copy.
            'Prove it, don’t promise it.',
            'See what your practice is missing.',
            '© 2026 Vision Law. All rights reserved.',
            '/images/vision-wordmark.png',
        ] as $sentinel) {
            $response->assertDontSee($sentinel);
        }
    }

    /**
     * 013-D14 F3: the fixtures' sample illustration label renders
     * visibly with the hero vignette — ahead of the sample counts it
     * labels — together with every sample evidence row and count.
     */
    public function test_sample_label_renders_with_the_vignette_sample_data(): void
    {
        $fixtures = $this->fixtures();
        $landing = $this->level($fixtures, 'landing');
        $platform = $this->level($landing, 'platform');
        $label = $platform['sample_label'] ?? null;
        $this->assertIsString($label);

        $response = $this->get('/');
        $response->assertOk();
        $response->assertSee($label);

        $html = $response->getContent();
        $this->assertIsString($html);
        $labelPos = strpos($html, $label);
        $this->assertNotFalse($labelPos);

        $counts = $this->level($platform, 'sample_counts');
        foreach ($counts as $count) {
            $this->assertIsArray($count);
            $response->assertSee((string) $count['label']);
            $countPos = strpos($html, (string) $count['label']);
            $this->assertNotFalse($countPos);
            $this->assertLessThan($countPos, $labelPos, 'The illustration label must render with the vignette, ahead of its sample counts.');
        }

        $rows = $this->level($platform, 'sample_evidence_rows');
        foreach ($rows as $row) {
            $this->assertIsArray($row);
            $response->assertSee((string) $row['doc']);
            $response->assertSee((string) $row['anchor']);
        }
    }

    /**
     * The landing's sections render in the contract's order
     * (03-contract.md §Landing page section contract): platform, packs,
     * trust, CTA.
     */
    public function test_landing_sections_render_in_contract_order(): void
    {
        $response = $this->get('/');
        $response->assertOk();
        $response->assertSeeInOrder([
            'id="platform"',
            'id="packs"',
            'id="trust"',
            'id="cta"',
        ], false);
    }
}
