<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentRendition;
use App\Models\DocumentTemplate;
use App\Models\DocumentVersion;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Spec 007 T-04 verdicts: editor + templates (stationery only, 007-D03).
 *
 * - test_editor_publish (C-07): type, autosave draft (no version created),
 *   Save → v1 with HTML + PDF rendition; edit → v2; metadata title change
 *   → still v2.
 * - test_template_generate (C-11): create template with {{client_name}} →
 *   generate for matter → v1 with merged value, linked to template version;
 *   publish without template-editor role → 403.
 */
class DocumentEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Blob bytes go to a temp dir in tests — never the real store.
        Config::set('filesystems.disks.documents', [
            'driver' => 'local',
            'root' => sys_get_temp_dir().'/visionlaw-test-documents',
            'throw' => true,
            'report' => false,
        ]);
        Config::set('document.disk', 'documents');
        Storage::forgetDisk('documents');
    }

    public function test_editor_publish(): void
    {
        $fixtures = FixtureLoader::load();
        $admin = $fixtures->user('user_attorney_granted');
        $matter = $fixtures->matter('matter_001');

        $this->actingAs($admin);

        // Editor views render.
        $this->get(route('documents.authored.create', $matter))->assertOk();
        $this->get(route('templates.index'))->assertOk();

        // Create the authored document shell (no version yet).
        $auditBefore = AuditEvent::count();

        $response = $this->post(route('documents.authored.store', $matter), [
            'title' => 'Draft motion',
            'description' => 'First draft',
        ]);
        $response->assertRedirect();

        /** @var Document $document */
        $document = Document::where('title', 'Draft motion')->firstOrFail();
        $this->assertSame('authored', $document->kind);
        $this->assertSame('processing', $document->status);
        $this->assertSame(0, $document->versions()->count());
        $this->assertSame($auditBefore + 1, AuditEvent::count());
        $this->assertTrue(
            AuditEvent::where('event', 'document.created')
                ->where('matter_id', $matter->getKey())
                ->exists()
        );

        $this->get(route('documents.editor.edit', [$matter, $document]))->assertOk();

        // Autosave draft: no version created, no audit row.
        $versionsBefore = AuditEvent::where('event', 'document.version.created')->count();

        $this->postJson(route('documents.draft.update', [$matter, $document]), [
            'content' => '<p>unsaved draft text</p>',
        ])->assertOk()->assertJson(['ok' => true]);

        $document->refresh();
        $this->assertSame(0, $document->versions()->count());
        $this->assertSame('<p>unsaved draft text</p>', $document->draft_content);
        $this->assertNotNull($document->draft_updated_at);
        $this->assertSame(
            $versionsBefore,
            AuditEvent::where('event', 'document.version.created')->count(),
            'Autosave must not create a version or audit row.'
        );

        // Explicit Save → v1 with structured HTML + generated PDF rendition.
        $this->post(route('documents.versions.publish', [$matter, $document]), [
            'content' => '<h1>Motion</h1><p>Body text.</p>',
            'change_note' => 'First draft',
        ])->assertRedirect();

        $document->refresh();
        $this->assertSame(1, $document->versions()->count());
        $this->assertSame('ready', $document->status);
        $this->assertNull($document->draft_content);
        $this->assertNull($document->draft_updated_at);

        /** @var DocumentVersion $v1 */
        $v1 = $document->versions()->firstOrFail();
        $this->assertSame(1, $v1->version_number);
        $this->assertSame('First draft', $v1->change_note);
        $this->assertSame((string) $v1->getKey(), (string) $document->current_version_id);

        /** @var DocumentRendition $rendition */
        $rendition = DocumentRendition::where('version_id', $v1->getKey())
            ->where('kind', 'pdf')
            ->firstOrFail();
        $this->assertSame('application/pdf', $rendition->blob->mime_sniffed);
        $this->assertStringStartsWith('%PDF', (string) file_get_contents(
            Storage::disk('documents')->path($rendition->blob->storage_path)
        ));

        $this->assertTrue(
            AuditEvent::where('event', 'document.version.created')
                ->whereJsonContains('payload->document_id', (string) $document->getKey())
                ->whereJsonContains('payload->version_number', 1)
                ->exists()
        );

        // Content edit → v2 (gapless, immutable history).
        $this->post(route('documents.versions.publish', [$matter, $document]), [
            'content' => '<h1>Motion</h1><p>Revised body.</p>',
        ])->assertRedirect();

        $document->refresh();
        $this->assertSame(2, $document->versions()->count());
        $this->assertSame(2, $document->currentVersion->version_number);
        $this->assertSame(1, DocumentRendition::where('version_id', $v1->getKey())->count());

        // Metadata title change → still v2 (metadata edits never version).
        $document->update(['title' => 'Draft motion — renamed']);
        $this->assertSame(2, $document->fresh()->versions()->count());
        $this->assertSame(2, $document->fresh()->currentVersion->version_number);

        // Uploaded binaries are 422 in the editor (upload-new-version instead).
        $uploaded = Document::create([
            'org_id' => $matter->org_id,
            'matter_id' => $matter->getKey(),
            'title' => 'Binary upload',
            'kind' => 'uploaded',
            'status' => 'ready',
            'created_by' => $admin->getKey(),
        ]);

        $this->get(route('documents.editor.edit', [$matter, $uploaded]))
            ->assertStatus(422)
            ->assertJson(['code' => 'not_editable']);
    }

    public function test_template_generate(): void
    {
        $fixtures = FixtureLoader::load();
        $admin = $fixtures->user('user_attorney_granted');
        $matter = $fixtures->matter('matter_001');

        $this->actingAs($admin);

        // Create a template with a {{client_name}} merge field.
        $response = $this->post(route('templates.store'), [
            'name' => 'Engagement letter T-04',
            'body_html' => '<p>Dear {{client_name}},</p><p>Fee: {{fee_amount}}.</p>',
            'field_definitions' => json_encode([
                ['name' => 'client_name', 'label' => 'Client name', 'type' => 'matter_field', 'source' => 'client_name', 'required' => true],
                ['name' => 'fee_amount', 'label' => 'Fee amount', 'type' => 'number', 'required' => true],
            ]),
        ]);
        $response->assertRedirect();

        /** @var DocumentTemplate $template */
        $template = DocumentTemplate::where('name', 'Engagement letter T-04')->firstOrFail();
        $this->assertSame('draft', $template->status);
        $this->assertSame(1, $template->version);
        $this->assertTrue(
            AuditEvent::where('event', 'template.created')->exists()
        );

        // Publish WITHOUT the template_editor role → 403.
        $this->assertFalse($admin->hasRole('template_editor'));
        $this->post(route('templates.publish', $template))->assertForbidden();
        $this->assertSame('draft', $template->fresh()->status);

        // Grant the role, publish, bump the version with an edit.
        $role = Role::where('org_id', $admin->org_id)
            ->where('name', 'template_editor')
            ->firstOrFail();
        $admin->assignRole($role);

        $this->post(route('templates.publish', $template))->assertRedirect();
        $template->refresh();
        $this->assertSame('published', $template->status);
        $this->assertTrue(
            AuditEvent::where('event', 'template.published')->exists()
        );

        $this->patch(route('templates.update', $template), [
            'name' => 'Engagement letter T-04',
            'body_html' => '<p>Dear {{client_name}},</p><p>Fee: {{fee_amount}}.</p>',
            'field_definitions' => json_encode([
                ['name' => 'client_name', 'label' => 'Client name', 'type' => 'matter_field', 'source' => 'client_name', 'required' => true],
                ['name' => 'fee_amount', 'label' => 'Fee amount', 'type' => 'number', 'required' => true],
            ]),
        ])->assertRedirect();
        $this->assertSame(2, $template->fresh()->version);

        // Generate form: matter_field pre-filled from the matter record.
        $form = $this->get(route('templates.generate.form', $template).'?matter_id='.$matter->getKey());
        $form->assertOk();
        $form->assertSee($matter->client_name, false);

        // Generate → authored v1 with merged value, linked to template version.
        $response = $this->post(route('templates.generate', $template), [
            'matter_id' => $matter->getKey(),
            'title' => 'Engagement letter T-04 — Acme',
            'fields' => [
                'client_name' => 'Acme Corp',
                'fee_amount' => '5000',
            ],
        ]);
        $response->assertRedirect();

        /** @var Document $document */
        $document = Document::where('title', 'Engagement letter T-04 — Acme')->firstOrFail();
        $this->assertSame('generated', $document->kind);
        $this->assertSame((string) $template->getKey(), (string) $document->template_id);
        $this->assertSame(2, $document->template_version);
        $this->assertSame(1, $document->versions()->count());

        $html = (string) $document->currentVersion->blob
            ->fresh()->storage_path;
        $contents = (string) file_get_contents(Storage::disk('documents')->path($html));
        $this->assertStringContainsString('Dear Acme Corp,', $contents);
        $this->assertStringContainsString('Fee: 5000.', $contents);
        $this->assertStringNotContainsString('{{client_name}}', $contents);

        $this->assertTrue(
            AuditEvent::where('event', 'template.used')
                ->whereJsonContains('payload->template_version', 2)
                ->exists()
        );

        // Missing required field (no form value, no pre-fill, no default) → 422.
        // postJson: the same validation path, asserting the contract's 422
        // status (an HTML post would 302-redirect with flashed errors).
        $this->postJson(route('templates.generate', $template), [
            'matter_id' => $matter->getKey(),
            'title' => 'Engagement letter T-04 — missing',
            'fields' => ['client_name' => 'Acme Corp'],
        ])->assertStatus(422)
            // The app renders validation failures as {code: validation,
            // details: {...}} (custom exception shape) — assert the
            // contract's field_required:{name} marker is present.
            ->assertJsonPath('code', 'validation')
            ->assertJsonFragment(["Field 'fee_amount' is required (field_required:fee_amount)."]);

        // Actor with no matter grant → 404 (no existence leak).
        $outsider = $fixtures->user('user_attorney_nogrant');
        $this->actingAs($outsider);
        $this->post(route('templates.generate', $template), [
            'matter_id' => $matter->getKey(),
            'title' => 'Nope',
            'fields' => ['client_name' => 'X', 'fee_amount' => '1'],
        ])->assertNotFound();
    }
}
