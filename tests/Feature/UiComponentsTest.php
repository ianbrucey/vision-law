<?php

namespace Tests\Feature;

use App\View\Components\Ui\Status;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\ViewException;
use InvalidArgumentException;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 002 — UI foundation, tickets T-02 (primitive form components), T-03
 * (display components), T-04 (composite components), T-05 (app shell +
 * patterns catalogue) and T-07 (accessibility audit). Verdicts C-02
 * (complete)/C-03/C-04/C-06 (complete), C-07, C-08, C-09 and C-10 live here.
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

    // ------------------------------------------------------------------
    // Ticket 4 — composite components
    // ------------------------------------------------------------------

    /**
     * C-02 (COMPLETE): the full T-04 inventory renders with defaults,
     * no exception.
     */
    public function test_composite_components_render_with_defaults(): void
    {
        $table = Blade::render(
            '<x-ui.table><x-slot:head><th>Document</th><th>Status</th></x-slot:head>'
            .'<x-slot:body><tr><td>Fee agreement</td><td>Final</td></tr></x-slot:body></x-ui.table>'
        );
        $this->assertStringContainsString('<table', $table);
        $this->assertStringContainsString('vl-table', $table);
        $this->assertStringContainsString('<th>Document</th>', $table);
        $this->assertStringContainsString('overflow-x-auto', $table);

        $modal = Blade::render(
            '<x-ui.modal id="demo-modal" title="Delete draft versions?">'
            .'Body copy.<x-slot:footer><x-ui.button variant="danger" size="sm">Delete</x-ui.button></x-slot:footer></x-ui.modal>'
        );
        $this->assertStringContainsString('role="dialog"', $modal);
        $this->assertStringContainsString('aria-modal="true"', $modal);
        $this->assertStringContainsString('vl-open-modal', $modal);
        $this->assertStringContainsString('vl-close-modal', $modal);
        $this->assertStringContainsString('x-trap', $modal);
        $this->assertStringContainsString('x-cloak', $modal);
        $this->assertStringContainsString('demo-modal-title', $modal);
        $this->assertStringContainsString('Delete draft versions?', $modal);

        // Toast with no flash renders nothing (fail-closed, never an empty box).
        $this->assertSame('', trim(Blade::render('<x-ui.toast />')));

        $empty = Blade::render(
            '<x-ui.empty title="No documents yet" actionHref="/upload" actionLabel="Upload your first document">'
            .'Uploaded files and generated drafts will appear here.</x-ui.empty>'
        );
        $this->assertStringContainsString('No documents yet', $empty);
        $this->assertStringContainsString('border-dashed', $empty);
        $this->assertStringContainsString('href="/upload"', $empty);
        $this->assertStringContainsString('Upload your first document', $empty);

        $subNav = Blade::render('<x-ui.sub-nav :items="$items" />', [
            'items' => [
                ['label' => 'Overview', 'href' => '#overview', 'active' => true],
                ['label' => 'Documents', 'href' => '#documents', 'active' => false],
            ],
        ]);
        $this->assertStringContainsString('aria-label="Sections"', $subNav);
        $this->assertStringContainsString('aria-current="page"', $subNav);
        $this->assertStringContainsString('border-vl-brass', $subNav);

        $paginator = new LengthAwarePaginator(range(1, 30), 30, 15, 1, ['path' => '/docs']);
        $pagination = Blade::render('<x-ui.pagination :paginator="$paginator" />', ['paginator' => $paginator]);
        $this->assertStringContainsString('aria-label="Pagination"', $pagination);
        $this->assertStringContainsString('aria-current="page"', $pagination);
        $this->assertStringContainsString('page=2', $pagination);

        // A single page renders nothing.
        $single = new LengthAwarePaginator(range(1, 5), 5, 15, 1, ['path' => '/docs']);
        $this->assertSame('', trim(Blade::render('<x-ui.pagination :paginator="$paginator" />', ['paginator' => $single])));
    }

    /**
     * C-10: toast renders from session flash — tone styling, escaped message,
     * fail-closed on unknown tone.
     */
    public function test_toast_renders_from_session_flash(): void
    {
        session()->flash('toast', ['tone' => 'ok', 'message' => 'Draft exported — PDF saved.']);
        $html = Blade::render('<x-ui.toast />');
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('Draft exported', $html);
        $this->assertStringContainsString('bg-vl-ok-soft', $html);

        // Unknown tone fails closed to info; the message is escaped, never raw HTML.
        session()->flash('toast', ['tone' => 'wat', 'message' => '<script>alert(1)</script>']);
        $html = Blade::render('<x-ui.toast />');
        $this->assertStringContainsString('bg-vl-info-soft', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * Composite components with required props fail loud on missing input.
     */
    public function test_composite_components_fail_loud_on_missing_required_props(): void
    {
        foreach ([
            '<x-ui.modal title="T">Body</x-ui.modal>' => 'requires an "id"',
            '<x-ui.modal id="m">Body</x-ui.modal>' => 'requires a "title"',
            '<x-ui.empty>Body</x-ui.empty>' => 'requires a "title"',
            '<x-ui.sub-nav />' => 'requires "items"',
            '<x-ui.pagination />' => 'requires a "paginator"',
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

    // ------------------------------------------------------------------
    // Ticket 5 — app shell + patterns catalogue
    // ------------------------------------------------------------------

    /**
     * C-07: the app shell renders — wordmark, org/user-menu slots, skip link,
     * 1120px container, toast mount point, Alpine drawer hooks.
     */
    public function test_app_shell_layout_renders(): void
    {
        $html = Blade::render(
            '<x-layouts.app title="Shell check">'
            .'<x-slot:org>Sterling &amp; Associates</x-slot:org>'
            .'<x-slot:userMenu>G. Granted</x-slot:userMenu>'
            .'<x-slot:nav><a href="#a">Matters</a></x-slot:nav>'
            .'<x-slot:content><p>Body copy</p></x-slot:content>'
            .'</x-layouts.app>'
        );

        $this->assertStringContainsString('<title>Shell check</title>', $html);
        $this->assertStringContainsString('Vision Law', $html);
        $this->assertStringContainsString('Sterling &amp; Associates', $html);
        $this->assertStringContainsString('G. Granted', $html);
        $this->assertStringContainsString('Skip to main content', $html);
        $this->assertStringContainsString('href="#vl-main"', $html);
        $this->assertStringContainsString('<main id="vl-main"', $html);
        $this->assertStringContainsString('max-w-[1120px]', $html);
        $this->assertStringContainsString('drawerOpen', $html);
        $this->assertStringContainsString('Body copy', $html);

        // Toast mount point: a flashed toast renders inside the shell.
        session()->flash('toast', ['tone' => 'ok', 'message' => 'Shell toast check.']);
        $html = Blade::render(
            '<x-layouts.app title="Shell toast"><x-slot:content><p>Hi</p></x-slot:content></x-layouts.app>'
        );
        $this->assertStringContainsString('Shell toast check.', $html);
    }

    /**
     * C-08: /_patterns renders the catalogue in local and 404s in production.
     */
    public function test_patterns_route_renders_in_local_and_404s_in_production(): void
    {
        // app()->environment() reads the container 'env' binding (set at
        // bootstrap), not config('app.env') — set the binding directly.
        app()->instance('env', 'local');

        // The route sits behind auth like every other non-guest route
        // (AuthenticatedByDefaultTest door), so sign in first.
        $loader = FixtureLoader::load();
        $this->actingAs($loader->user('user_attorney_granted'))
            ->get('/_patterns')
            ->assertOk()
            ->assertSee('UI patterns', false)
            ->assertSee('Synthetic document', false)
            ->assertSee('role="dialog"', false)
            ->assertSee('aria-label="Pagination"', false);

        app()->instance('env', 'production');
        $this->actingAs($loader->user('user_attorney_granted'))
            ->get('/_patterns')
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Ticket 7 — accessibility audit
    // ------------------------------------------------------------------

    /**
     * C-06 (complete): label[for] matches control id for all four controls —
     * including custom id overrides and error states (aria-describedby).
     */
    public function test_all_form_controls_have_associated_labels_complete(): void
    {
        // Custom id override propagates to both the label and the control.
        $cases = [
            '<x-ui.field name="title" label="Matter title" id="custom-title" />',
            '<x-ui.select name="status" label="Status" id="custom-status" :options="$options" />',
            '<x-ui.textarea name="notes" label="Notes" id="custom-notes" />',
            '<x-ui.checkbox name="privileged" label="Privileged" id="custom-privileged" />',
        ];

        foreach ($cases as $template) {
            $html = Blade::render($template, ['options' => ['open' => 'Open']]);

            preg_match('/<label[^>]*\bfor="([^"]+)"/', $html, $labelMatch);
            preg_match('/<(?:input|select|textarea)[^>]*\bid="([^"]+)"/', $html, $controlMatch);

            $this->assertNotEmpty($labelMatch, "No label[for] found in: {$template}");
            $this->assertNotEmpty($controlMatch, "No control id found in: {$template}");
            $this->assertSame($labelMatch[1], $controlMatch[1], "label[for] does not match control id in: {$template}");
            $this->assertStringStartsWith('custom-', $labelMatch[1], "Custom id not applied in: {$template}");
        }

        // Error state keeps the association and wires aria-describedby to the
        // error node under the same id family.
        $html = Blade::render('<x-ui.field name="title" label="Matter title" error="Required." />');
        $this->assertStringContainsString('<label for="title"', $html);
        $this->assertStringContainsString('id="title"', $html);
        $this->assertStringContainsString('aria-describedby="title-error"', $html);
        $this->assertStringContainsString('id="title-error"', $html);
    }

    /**
     * T-07: the focus-visible contract from UI_Standards ("2px --vl-info
     * outline on all interactive elements") is declared in the base CSS and
     * survives the Vite build into the compiled bundle.
     */
    public function test_focus_visible_styles_render_on_interactive_elements(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/:focus-visible\s*\{[^}]*outline:\s*2px\s+solid\s+var\(--vl-info\)/',
            $css,
            'Base CSS must declare a 2px var(--vl-info) :focus-visible outline.'
        );

        // The compiled asset keeps the rule — no silent loss in the build.
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $cssAsset = $manifest['resources/css/app.css']['file'] ?? null;
        $this->assertNotNull($cssAsset, 'Vite manifest must reference the compiled CSS.');
        $compiled = (string) file_get_contents(public_path('build/'.$cssAsset));
        $this->assertStringContainsString(':focus-visible{outline:2px solid var(--vl-info)', $compiled);
    }

    /**
     * T-07: contrast audit — parse the @theme hex values from app.css,
     * compute WCAG 2.1 contrast vs white, and verify no regression vs the
     * 05-ui.md recorded pairs (all ≥ 4.5:1 for text).
     */
    public function test_contrast_pairs_meet_aa_and_match_recorded_values(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $tokens = [];
        foreach (['ink', 'text', 'mut', 'brass', 'ok', 'bad', 'info'] as $name) {
            preg_match('/--color-vl-'.$name.':\s*(#[0-9a-fA-F]{6})/', $css, $match);
            $this->assertNotEmpty($match, "Token --color-vl-{$name} not found in @theme.");
            $tokens[$name] = $match[1];
        }

        $recorded = [
            'ink' => 14.5, 'text' => 14.5, 'mut' => 5.5, 'brass' => 4.7,
            'ok' => 5.0, 'bad' => 5.9, 'info' => 6.8,
        ];

        foreach ($recorded as $name => $expected) {
            $ratio = $this->contrastRatio($tokens[$name], '#FFFFFF');
            $this->assertGreaterThanOrEqual(4.5, $ratio, "--vl-{$name} on white is below WCAG AA 4.5:1.");
            $this->assertEqualsWithDelta($expected, $ratio, 0.2, "--vl-{$name} drifted from the recorded {$expected}:1.");
        }
    }

    private function contrastRatio(string $foreground, string $background): float
    {
        $l1 = $this->relativeLuminance($foreground);
        $l2 = $this->relativeLuminance($background);
        [$high, $low] = $l1 >= $l2 ? [$l1, $l2] : [$l2, $l1];

        return ($high + 0.05) / ($low + 0.05);
    }

    private function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = array_map(
            fn (string $pair): float => hexdec($pair) / 255,
            [substr($hex, 0, 2), substr($hex, 2, 2), substr($hex, 4, 2)]
        );

        $linear = array_map(
            fn (float $channel): float => $channel <= 0.03928
                ? $channel / 12.92
                : pow(($channel + 0.055) / 1.055, 2.4),
            $channels
        );

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    /**
     * T-07 keyboard walk: the modal traps focus while open, Escape closes it,
     * and it is a properly labelled dialog (x-trap comes from the Alpine
     * focus plugin bundled in T-01).
     */
    public function test_modal_traps_focus_and_closes_on_escape(): void
    {
        $html = Blade::render(
            '<x-ui.modal id="audit-modal" title="Delete draft"><p>Body.</p>'
            .'<x-slot:footer><x-ui.button variant="danger">Delete</x-ui.button></x-slot:footer></x-ui.modal>'
        );

        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('aria-labelledby="audit-modal-title"', $html);
        $this->assertStringContainsString('x-trap="open"', $html, 'Focus containment must come from x-trap.');
        $this->assertStringContainsString('x-on:keydown.escape.window', $html, 'Escape must close the modal.');
    }

    /**
     * T-07 keyboard walk: the mobile nav drawer is keyboard-operable —
     * Escape closes, focus is trapped, and the icon-only buttons expose
     * accessible names.
     */
    public function test_mobile_drawer_is_keyboard_operable(): void
    {
        $html = Blade::render(
            '<x-layouts.app title="Drawer check"><x-slot:nav><a href="#a">Matters</a></x-slot:nav>'
            .'<x-slot:content><p>Body</p></x-slot:content></x-layouts.app>'
        );

        $this->assertStringContainsString('x-on:keydown.escape.window="drawerOpen = false"', $html);
        $this->assertStringContainsString('x-trap="drawerOpen"', $html);
        $this->assertStringContainsString('aria-label="Site navigation"', $html);
        $this->assertStringContainsString('aria-label="Open navigation"', $html, 'Icon-only drawer toggle needs an accessible name.');
        $this->assertStringContainsString('aria-label="Close navigation"', $html, 'Icon-only drawer close needs an accessible name.');
    }

    /**
     * T-07: the skip link targets the main-content landmark — asserted on the
     * live /_patterns page in local env (the env binding is restored after).
     */
    public function test_skip_link_targets_main_content_landmark(): void
    {
        $previousEnv = app('env');
        app()->instance('env', 'local');

        try {
            $loader = FixtureLoader::load();
            $html = $this->actingAs($loader->user('user_attorney_granted'))
                ->get('/_patterns')
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString('href="#vl-main"', $html);
            $this->assertStringContainsString('Skip to main content', $html);
            $this->assertStringContainsString('<main id="vl-main"', $html);
        } finally {
            app()->instance('env', $previousEnv);
        }
    }
}
