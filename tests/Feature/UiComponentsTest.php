<?php

namespace Tests\Feature;

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
}
