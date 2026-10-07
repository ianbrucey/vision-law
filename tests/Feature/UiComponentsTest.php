<?php

namespace Tests\Feature;

use App\View\Components\Ui\Status;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\ViewException;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Spec 002 — UI foundation, tickets T-02 (primitive form components) and T-03
 * (display components). Verdicts C-02/C-03/C-04/C-06 (partial) and C-09 live here.
 */
class UiComponentsTest extends TestCase
{
    // ------------------------------------------------------------------
    // Ticket 2 — primitive form components
    // ------------------------------------------------------------------

    /**
     * C-02 (partial): each of the five T-02 components renders with defaults,
     * no exception.
     */
    public function test_primitives_render_with_defaults(): void
    {
        $this->assertStringContainsString('<button', Blade::render('<x-ui.button>Save</x-ui.button>'));
        $this->assertStringContainsString('<input', Blade::render('<x-ui.field name="title" label="Matter title" />'));
        $this->assertStringContainsString('<select', Blade::render('<x-ui.select name="status" label="Status" :options="[]" />'));
        $this->assertStringContainsString('type="checkbox"', Blade::render('<x-ui.checkbox name="privileged" label="Privileged" />'));
        $this->assertStringContainsString('<textarea', Blade::render('<x-ui.textarea name="notes" label="Notes" />'));
    }

    /**
     * C-03: button variants, sizes, href, disabled — and the fail-closed rule
     * (unknown variant renders as secondary, never throws).
     */
    public function test_button_variants_and_sizes_render(): void
    {
        $this->assertStringContainsString('bg-vl-ink', Blade::render('<x-ui.button variant="primary">Save</x-ui.button>'));
        $this->assertStringContainsString('bg-vl-card', Blade::render('<x-ui.button variant="secondary">Cancel</x-ui.button>'));
        $this->assertStringContainsString('bg-vl-bad', Blade::render('<x-ui.button variant="danger">Delete</x-ui.button>'));
        $this->assertStringContainsString('bg-transparent', Blade::render('<x-ui.button variant="ghost">Preview</x-ui.button>'));

        // Fail-closed: unknown variant falls back to secondary (no exception).
        $fallback = Blade::render('<x-ui.button variant="wat">Mystery</x-ui.button>');
        $this->assertStringContainsString('bg-vl-card', $fallback);
        $this->assertStringNotContainsString('wat', $fallback);

        // Sizes.
        $this->assertStringContainsString('min-h-9', Blade::render('<x-ui.button size="sm">Small</x-ui.button>'));
        $this->assertStringContainsString('min-h-11', Blade::render('<x-ui.button>Default</x-ui.button>'));

        // Disabled.
        $this->assertStringContainsString('disabled', Blade::render('<x-ui.button disabled>Off</x-ui.button>'));

        // href renders <a> styled as a button; type is ignored.
        $link = Blade::render('<x-ui.button href="/matters" type="submit">Go</x-ui.button>');
        $this->assertStringContainsString('<a ', $link);
        $this->assertStringContainsString('href="/matters"', $link);
        $this->assertStringNotContainsString('<button', $link);

        // type passes through on <button>.
        $this->assertStringContainsString('type="submit"', Blade::render('<x-ui.button type="submit">Save</x-ui.button>'));
    }

    /**
     * C-04: form controls render their label, preserve old() values, and show
     * inline errors (explicit prop and $errors fallback).
     */
    public function test_form_controls_render_label_old_value_and_errors(): void
    {
        // Label + control association.
        $html = Blade::render('<x-ui.field name="title" label="Matter title" />');
        $this->assertStringContainsString('<label for="title"', $html);
        $this->assertStringContainsString('Matter title', $html);
        $this->assertStringContainsString('name="title"', $html);

        // old() value fallback. old() resolves through the current request's
        // session, so attach the session store to the request first.
        $this->withOldInput(['title' => 'Old title', 'status' => 'on_hold', 'notes' => 'Old notes', 'privileged' => '1']);
        $this->assertStringContainsString(
            'value="Old title"',
            Blade::render('<x-ui.field name="title" label="Matter title" />')
        );
        $select = Blade::render(
            '<x-ui.select name="status" label="Status" :options="$options" />',
            ['options' => ['open' => 'Open', 'on_hold' => 'On hold']]
        );
        $this->assertStringContainsString('value="on_hold" selected', $select);
        $this->assertStringContainsString(
            '>Old notes<',
            Blade::render('<x-ui.textarea name="notes" label="Notes" />')
        );
        $this->assertStringContainsString(
            'checked',
            Blade::render('<x-ui.checkbox name="privileged" label="Privileged" />')
        );

        // Explicit value prop wins over old().
        $this->assertStringContainsString(
            'value="Prop value"',
            Blade::render('<x-ui.field name="title" label="Matter title" value="Prop value" />')
        );

        // Explicit error prop.
        $html = Blade::render('<x-ui.field name="title" label="Matter title" error="Inline error." />');
        $this->assertStringContainsString('Inline error.', $html);
        $this->assertStringContainsString('border-vl-bad', $html);
        $this->assertStringContainsString('role="alert"', $html);

        // $errors fallback (as shared by ShareErrorsFromSession in production).
        view()->share('errors', (new ViewErrorBag)->put('default', new MessageBag(['title' => 'Use the format MAT-2026-001.'])));
        $html = Blade::render('<x-ui.field name="title" label="Matter title" />');
        $this->assertStringContainsString('Use the format MAT-2026-001.', $html);
        $this->assertStringContainsString('border-vl-bad', $html);
        $html = Blade::render('<x-ui.select name="title" label="Title" :options="$options" />', ['options' => ['a' => 'A']]);
        $this->assertStringContainsString('Use the format MAT-2026-001.', $html);

        // Placeholder renders as a disabled first option.
        $html = Blade::render('<x-ui.select name="status" label="Status" placeholder="Choose a status…" :options="$options" />', ['options' => ['a' => 'A']]);
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString('Choose a status…', $html);

        // Optional marker.
        $this->assertStringContainsString('(optional)', Blade::render('<x-ui.field name="ref" label="Client reference" optional />'));

        // Help text.
        $this->assertStringContainsString(
            'The caption as it appears on filings.',
            Blade::render('<x-ui.field name="title" label="Matter title" help="The caption as it appears on filings." />')
        );
    }

    /**
     * C-06 (partial): every T-02 form control's rendered label[for] matches its
     * control's id.
     */
    public function test_all_form_controls_have_associated_labels(): void
    {
        $cases = [
            '<x-ui.field name="title" label="Matter title" />',
            '<x-ui.select name="status" label="Status" :options="$options" />',
            '<x-ui.textarea name="notes" label="Notes" />',
            '<x-ui.checkbox name="privileged" label="Privileged" />',
        ];

        foreach ($cases as $template) {
            $html = Blade::render($template, ['options' => ['open' => 'Open']]);

            preg_match('/<label[^>]*\bfor="([^"]+)"/', $html, $labelMatch);
            preg_match('/<(?:input|select|textarea)[^>]*\bid="([^"]+)"/', $html, $controlMatch);

            $this->assertNotEmpty($labelMatch, "No label[for] found in: {$template}");
            $this->assertNotEmpty($controlMatch, "No control id found in: {$template}");
            $this->assertSame($labelMatch[1], $controlMatch[1], "label[for] does not match control id in: {$template}");
        }
    }

    /**
     * Fail-loud: missing name/label throws; select rejects non-array options.
     * (Blade wraps render exceptions in (possibly nested) ViewExceptions; the
     * original InvalidArgumentException is preserved at the root of the chain.)
     */
    public function test_form_wrappers_fail_loud_on_missing_label_name_or_options(): void
    {
        try {
            Blade::render('<x-ui.field name="title" />');
            $this->fail('Expected an exception for a label-less field.');
        } catch (ViewException $e) {
            $this->assertInstanceOf(InvalidArgumentException::class, $this->rootCause($e));
            $this->assertStringContainsString('requires both "name" and "label"', $e->getMessage());
        }
    }

    public function test_select_fails_loud_on_non_array_options(): void
    {
        try {
            Blade::render('<x-ui.select name="status" label="Status" options="nope" />');
            $this->fail('Expected an exception for non-array options.');
        } catch (ViewException $e) {
            $this->assertInstanceOf(InvalidArgumentException::class, $this->rootCause($e));
            $this->assertStringContainsString('requires "options" as an array', $e->getMessage());
        }
    }

    /** Walk the previous-exception chain to its root. */
    private function rootCause(\Throwable $e): \Throwable
    {
        while ($e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }

        return $e;
    }

    /**
     * Attach old input to the current request's session so the old() helper
     * inside components resolves exactly as it does in a real request.
     */
    private function withOldInput(array $input): void
    {
        $store = app('session')->driver();
        $store->put('_old_input', $input);
        app('request')->setLaravelSession($store);
    }

    // ------------------------------------------------------------------
    // Ticket 3 — display components
    // ------------------------------------------------------------------

    /**
     * C-02 (partial): each of the six T-03 display components renders with
     * defaults, no exception.
     */
    public function test_display_components_render_with_defaults(): void
    {
        $this->assertStringContainsString('vl-card', Blade::render('<x-ui.card>Body</x-ui.card>'));
        $this->assertStringContainsString('<h1', Blade::render('<x-ui.page-header title="Sterling v. Apex Construction" />'));
        $this->assertStringContainsString('Archived', Blade::render('<x-ui.chip>Archived</x-ui.chip>'));
        $this->assertStringContainsString('Body', Blade::render('<x-ui.banner tone="info">Body</x-ui.banner>'));
        $this->assertStringContainsString('<dl', Blade::render('<x-ui.kv :items="$items" />', ['items' => [['label' => 'L', 'value' => 'V']]]));
        $this->assertStringContainsString('14', Blade::render('<x-ui.stat value="14" label="Open matters" />'));
        $this->assertStringContainsString('Active', Blade::render('<x-ui.status type="matter" value="active" />'));
    }

    public function test_page_header_renders_single_h1_with_slots(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui.page-header title="Sterling v. Apex Construction" subtitle="Breach of contract.">
                <x-slot:crumbs><a href="#">Matters</a> › Sterling</x-slot:crumbs>
                <x-slot:actions><x-ui.button size="sm">Upload</x-ui.button></x-slot:actions>
                <x-slot:subnav><nav>tabs</nav></x-slot:subnav>
            </x-ui.page-header>
            BLADE);

        $this->assertSame(1, substr_count($html, '<h1'), 'page-header must render exactly one h1');
        $this->assertStringContainsString('vl-page-title', $html);
        $this->assertStringContainsString('Sterling v. Apex Construction', $html);
        $this->assertStringContainsString('Breach of contract.', $html);
        $this->assertStringContainsString('aria-label="Breadcrumb"', $html);
        $this->assertStringContainsString('Upload', $html);
        $this->assertStringContainsString('<nav>tabs</nav>', $html);
    }

    public function test_card_renders_title_and_header_actions(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui.card title="Matter detail">
                <x-slot:header-actions><x-ui.chip tone="info">Active</x-ui.chip></x-slot:header-actions>
                Body content.
            </x-ui.card>
            BLADE);

        $this->assertStringContainsString('<h2', $html);
        $this->assertStringContainsString('Matter detail', $html);
        $this->assertStringContainsString('Active', $html);
        $this->assertStringContainsString('Body content.', $html);
        $this->assertStringContainsString('vl-card-flush', Blade::render('<x-ui.card :padded="false">x</x-ui.card>'));
    }

    public function test_banner_tones_and_fail_closed(): void
    {
        $this->assertStringContainsString('bg-vl-info-soft', Blade::render('<x-ui.banner tone="info">x</x-ui.banner>'));
        $this->assertStringContainsString('bg-vl-ok-soft', Blade::render('<x-ui.banner tone="success">x</x-ui.banner>'));
        $this->assertStringContainsString('bg-vl-brass-soft', Blade::render('<x-ui.banner tone="warn" title="Privileged — do not forward">x</x-ui.banner>'));
        $this->assertStringContainsString('bg-vl-bad-soft', Blade::render('<x-ui.banner tone="danger">x</x-ui.banner>'));

        // Fail-closed: unknown tone renders as info, never throws.
        $html = Blade::render('<x-ui.banner tone="wat">x</x-ui.banner>');
        $this->assertStringContainsString('bg-vl-info-soft', $html);

        $html = Blade::render('<x-ui.banner tone="warn" title="Headline">Body</x-ui.banner>');
        $this->assertStringContainsString('<strong', $html);
        $this->assertStringContainsString('Headline', $html);
    }

    public function test_chip_tones_and_fail_closed(): void
    {
        $this->assertStringContainsString('bg-vl-ok-soft', Blade::render('<x-ui.chip tone="ok">Executed</x-ui.chip>'));
        $this->assertStringContainsString('bg-vl-brass-soft', Blade::render('<x-ui.chip tone="warn">Needs review</x-ui.chip>'));
        $this->assertStringContainsString('bg-vl-bad-soft', Blade::render('<x-ui.chip tone="bad">Overdue</x-ui.chip>'));
        $this->assertStringContainsString('bg-vl-paper', Blade::render('<x-ui.chip tone="neutral">Archived</x-ui.chip>'));
        $this->assertStringContainsString('bg-vl-info-soft', Blade::render('<x-ui.chip tone="info">In discovery</x-ui.chip>'));

        // Fail-closed: unknown tone renders neutral, never throws.
        $html = Blade::render('<x-ui.chip tone="wat">x</x-ui.chip>');
        $this->assertStringContainsString('bg-vl-paper', $html);
    }

    public function test_kv_renders_items_escaped(): void
    {
        $html = Blade::render('<x-ui.kv :items="$items" />', [
            'items' => [
                ['label' => 'Matter number', 'value' => 'MAT-2026-001'],
                ['label' => 'Client', 'value' => '<b>Bold</b>'],
            ],
        ]);

        $this->assertStringContainsString('<dt', $html);
        $this->assertStringContainsString('MAT-2026-001', $html);
        $this->assertStringNotContainsString('<b>Bold</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt;', $html);
    }

    /**
     * C-09: every row of the canonical Status map renders its label in the
     * right chip tone; unknown values render a neutral "Unknown" chip —
     * never raw enum text, never an exception.
     */
    public function test_status_component_maps_known_statuses_and_fails_closed(): void
    {
        $toneClasses = [
            'ok' => 'bg-vl-ok-soft',
            'warn' => 'bg-vl-brass-soft',
            'bad' => 'bg-vl-bad-soft',
            'neutral' => 'bg-vl-paper',
            'info' => 'bg-vl-info-soft',
        ];

        foreach (Status::MAP as $type => $rows) {
            foreach ($rows as $value => $row) {
                // Unit-level: the class holds the canonical map.
                $component = new Status($type, $value);
                $this->assertSame($row['label'], $component->label, "label for {$type}/{$value}");
                $this->assertSame($row['tone'], $component->tone, "tone for {$type}/{$value}");

                // Render-level: label inside the right chip tone.
                $html = Blade::render('<x-ui.status type="'.$type.'" value="'.$value.'" />');
                $this->assertStringContainsString($row['label'], $html, "rendered label for {$type}/{$value}");
                $this->assertStringContainsString($toneClasses[$row['tone']], $html, "rendered tone for {$type}/{$value}");

                // Never raw enum text when the label differs from the value.
                if ($row['label'] !== $value) {
                    $this->assertStringNotContainsString('>'.$value.'<', $html, "raw enum text leaked for {$type}/{$value}");
                }
            }
        }

        // Fail-closed: unknown values render a neutral "Unknown" chip.
        foreach (['matter', 'document', 'draft'] as $type) {
            $html = Blade::render('<x-ui.status type="'.$type.'" value="not_a_real_status" />');
            $this->assertStringContainsString('Unknown', $html);
            $this->assertStringContainsString('bg-vl-paper', $html);
            $this->assertStringNotContainsString('not_a_real_status', $html);
        }

        // Unknown type also fails closed.
        $html = Blade::render('<x-ui.status type="wat" value="nope" />');
        $this->assertStringContainsString('Unknown', $html);
        $this->assertStringContainsString('bg-vl-paper', $html);
    }

    /**
     * Display components with required props fail loud on missing input.
     */
    public function test_display_components_fail_loud_on_missing_required_props(): void
    {
        foreach ([
            '<x-ui.page-header />' => 'requires a "title"',
            '<x-ui.banner>Body</x-ui.banner>' => 'requires a "tone"',
            '<x-ui.kv />' => 'requires "items"',
            '<x-ui.stat label="L" />' => 'requires "value" and "label"',
        ] as $template => $message) {
            try {
                Blade::render($template);
                $this->fail("Expected an exception for: {$template}");
            } catch (ViewException $e) {
                $this->assertInstanceOf(InvalidArgumentException::class, $this->rootCause($e));
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }
}
