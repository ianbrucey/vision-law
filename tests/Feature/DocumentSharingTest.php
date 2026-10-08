<?php

namespace Tests\Feature;

use App\Exceptions\AccessDeniedException;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentBlob;
use App\Models\DocumentGrant;
use App\Models\DocumentShare;
use App\Models\MatterGrant;
use App\Services\DocumentAccess;
use App\Services\DocumentExportService;
use App\Services\DocumentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;
use ZipArchive;

/**
 * Spec 007 T-08 verdicts: sharing — grants, links, export (C-12, DOC-24/25/26).
 *
 * - test_sharing: grant viewer to user → can preview, not edit; external
 *   link with password → 5 wrong passwords → lockout; revoke → link dead;
 *   export package manifest verifies (recomputed SHA-256s + signature);
 *   expired link → 410/404 with no content leak.
 * - test_document_access_composition: effective = max(matter role, grant);
 *   matter-access revocation kills document access at read time.
 */
class DocumentSharingTest extends TestCase
{
    use RefreshDatabase;

    private FixtureLoader $loader;

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

        $this->loader = FixtureLoader::loadDocumentFixtures();

        $this->actingAs($this->loader->docUser('g.grant@sterling.example'));
    }

    // ------------------------------------------------------------------
    // C-12 — sharing verdict
    // ------------------------------------------------------------------

    public function test_sharing(): void
    {
        $matter = $this->loader->docMatter('MAT-2026-001');
        $admin = $this->loader->docUser('g.grant@sterling.example');
        $viewer = $this->loader->docUser('v.viewer@sterling.example');
        $doc = Document::query()
            ->where('matter_id', $matter->getKey())
            ->where('title', 'Complaint — filed stamp')
            ->firstOrFail();

        // The viewer needs matter access for the document grant to mean
        // anything (a grant without matter access is inert by design).
        MatterGrant::create([
            'org_id' => $matter->org_id,
            'matter_id' => $matter->getKey(),
            'user_id' => $viewer->getKey(),
            'role' => 'viewer',
            'granted_by' => $admin->getKey(),
        ]);

        // ── Grant viewer → can preview, cannot edit ──
        $grantResponse = $this->postJson(
            route('documents.grants.store', [$matter, $doc]),
            ['user_id' => (string) $viewer->getKey(), 'level' => 'viewer']
        );
        $grantResponse->assertCreated();
        $this->assertAuditLogged('document.shared', (string) $doc->getKey());

        $this->actingAs($viewer);
        $this->get(route('documents.preview', [$matter, $doc]))->assertOk();
        $this->patchJson(route('documents.update', [$matter, $doc]), ['title' => 'Hacked'])
            ->assertForbidden();

        // Grant list is visible per document (dialog renders the grantee).
        $this->actingAs($admin);
        $this->get(route('documents.share', [$matter, $doc]))
            ->assertOk()
            ->assertSee('People with access')
            ->assertSee('V. Viewer')
            ->assertSee('Secure link');

        // ── External link with password: 5 wrong → lockout ──
        $linkResponse = $this->postJson(route('documents.links.store', [$matter, $doc]), [
            'expires_in_days' => 7,
            'password' => 'link-password-123',
            'allow_download' => true,
        ]);
        $linkResponse->assertCreated();
        $url = (string) $linkResponse->json('data.url');
        $token = basename($url);
        $this->assertNotEmpty($token);

        // Leak sentinel: the token and its hash never reach the audit log.
        $this->assertTokenNeverLogged($token);

        // Password gate renders — without leaking the document title.
        $gate = $this->get($url);
        $gate->assertOk()->assertSee('password', false);
        $gate->assertDontSee($doc->title, false);

        for ($i = 1; $i <= 4; $i++) {
            $this->postJson($url, ['password' => "wrong-password-{$i}"])->assertForbidden();
        }
        // 5th wrong password → 15-minute lockout.
        $this->postJson($url, ['password' => 'wrong-password-5'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'share_locked_out');
        // Still locked, even with the right password.
        $this->postJson($url, ['password' => 'link-password-123'])->assertStatus(429);
        $this->assertGreaterThanOrEqual(
            5,
            AuditEvent::query()->where('event', 'document.share.denied')->count()
        );

        // Lockout expires → a fresh set of attempts: one wrong password
        // is 403 again (not an instant re-lock), then the right one unlocks.
        Carbon::setTestNow(now()->addMinutes(16));
        $this->postJson($url, ['password' => 'wrong-again'])->assertForbidden();
        $unlock = $this->post($url, ['password' => 'link-password-123']);
        $unlock->assertRedirect($url);
        Carbon::setTestNow();

        $landing = $this->get($url);
        $landing->assertOk()->assertSee($doc->title, false);

        // Download serves the PINNED version's bytes with its checksum.
        $pinned = DocumentShare::query()
            ->where('token_hash', hash('sha256', $token))
            ->firstOrFail();
        $download = $this->post($url.'/download');
        $download->assertOk();
        $download->assertHeader('X-Checksum-Sha256', (string) $pinned->version->blob->sha256);
        $this->assertSame(
            $this->blobBytes($pinned->version->blob),
            $download->streamedContent()
        );
        $this->assertGreaterThanOrEqual(
            2,
            AuditEvent::query()->where('event', 'document.share.accessed')->count()
        );

        // ── Revoke → link dead (identical 404 to an unknown token) ──
        $this->actingAs($admin);
        $this->deleteJson(route('documents.links.destroy', [$matter, $doc, $pinned]))
            ->assertOk();
        $this->assertAuditLogged('document.share.revoked', (string) $doc->getKey());

        $this->get($url)->assertNotFound();
        $this->post($url.'/download')->assertNotFound();
        $this->get('/s/'.str_repeat('a', 48))->assertNotFound();

        // ── Expired link fixture → 404 with no content leak ──
        $expiredToken = $this->loader->expiredShareToken();
        $this->assertNotEmpty($expiredToken);
        $expired = $this->get('/s/'.$expiredToken);
        $expired->assertNotFound();
        $expired->assertDontSee('Fee agreement', false);

        // ── Encrypted export: manifest verifies ──
        $feeAgreement = Document::query()
            ->where('matter_id', $matter->getKey())
            ->where('title', 'Fee agreement (executed)')
            ->firstOrFail();

        $export = $this->post(route('documents.export', [$matter]), [
            'document_ids' => [(string) $doc->getKey(), (string) $feeAgreement->getKey()],
            'password' => 'export-password-123',
        ]);
        $export->assertOk();
        $export->assertHeader('Content-Type', 'application/zip');

        $exported = AuditEvent::query()->where('event', 'document.exported')->latest('id')->first();
        $this->assertNotNull($exported);
        /** @var array<string, mixed> $exportPayload */
        $exportPayload = $exported->payload;
        $this->assertContains((string) $doc->getKey(), $exportPayload['document_ids']);
        $this->assertSame(2, $exportPayload['file_count']);

        $zipPath = tempnam(sys_get_temp_dir(), 'vl-export-test').'.zip';
        file_put_contents($zipPath, $export->streamedContent());

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($zipPath));

            // Encrypted: a wrong password yields no bytes.
            $zip->setPassword('definitely-wrong-password');
            $this->assertFalse($zip->getFromName('manifest.json'));

            $zip->setPassword('export-password-123');

            $manifestRaw = $zip->getFromName('manifest.json');
            $this->assertIsString($manifestRaw);
            /** @var array<string, mixed> $manifest */
            $manifest = json_decode($manifestRaw, true, 512, JSON_THROW_ON_ERROR);

            // Recompute every file's SHA-256 from the extracted bytes.
            $hashes = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($name === 'manifest.json') {
                    continue;
                }
                $bytes = $zip->getFromIndex($i);
                $this->assertIsString($bytes);
                $hashes[$name] = hash('sha256', $bytes);
            }

            $this->assertCount(2, $hashes);
            $this->assertTrue(app(DocumentExportService::class)->verify($manifest, $hashes));

            // Tampered bytes fail verification.
            $tampered = $hashes;
            $first = array_key_first($tampered);
            $tampered[$first] = str_repeat('0', 64);
            $this->assertFalse(app(DocumentExportService::class)->verify($manifest, $tampered));
        } finally {
            $zip->close();
            unlink($zipPath);
        }
    }

    // ------------------------------------------------------------------
    // Effective = max(matter role, grant); revocation kills access
    // ------------------------------------------------------------------

    public function test_document_access_composition(): void
    {
        $matter = $this->loader->docMatter('MAT-2026-001');
        $admin = $this->loader->docUser('g.grant@sterling.example');
        $viewer = $this->loader->docUser('v.viewer@sterling.example');
        $doc = Document::query()
            ->where('matter_id', $matter->getKey())
            ->where('title', 'Complaint — filed stamp')
            ->firstOrFail();

        $matterGrant = MatterGrant::create([
            'org_id' => $matter->org_id,
            'matter_id' => $matter->getKey(),
            'user_id' => $viewer->getKey(),
            'role' => 'viewer',
            'granted_by' => $admin->getKey(),
        ]);

        // No document grant: matter viewer → view yes, edit no.
        DocumentAccess::authorize($viewer, 'view', $doc);
        $this->expectDenied(403, fn () => DocumentAccess::authorize($viewer, 'edit', $doc));

        // Editor grant upgrades THIS document (max of the two).
        DocumentGrant::create([
            'document_id' => $doc->getKey(),
            'user_id' => $viewer->getKey(),
            'level' => 'editor',
            'created_by' => $admin->getKey(),
        ]);
        DocumentAccess::authorize($viewer, 'edit', $doc);
        // A grant never confers :manage.
        $this->expectDenied(403, fn () => DocumentAccess::authorize($viewer, 'manage', $doc));

        // Matter-access revocation kills document access at read time —
        // the grant row still exists, but the check re-reads matter access.
        $matterGrant->delete();
        $this->assertNotNull(DocumentGrant::query()
            ->where('document_id', $doc->getKey())
            ->where('user_id', $viewer->getKey())
            ->first());
        $this->expectDenied(404, fn () => DocumentAccess::authorize($viewer, 'view', $doc));

        // And over HTTP the preview goes dark too (no existence leak).
        $this->actingAs($viewer);
        $this->get(route('documents.preview', [$matter, $doc]))->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function expectDenied(int $status, callable $fn): void
    {
        try {
            $fn();
        } catch (AccessDeniedException $e) {
            $this->assertSame($status, $e->httpStatus);

            return;
        }

        $this->fail('Expected AccessDeniedException was not thrown.');
    }

    private function assertAuditLogged(string $event, string $documentId): void
    {
        $this->assertTrue(
            AuditEvent::query()
                ->where('event', $event)
                ->where('payload->document_id', $documentId)
                ->exists(),
            "Expected audit event {$event} for document {$documentId}."
        );
    }

    /**
     * Leak sentinel: neither the raw token nor its hash may appear in any
     * audit payload (brief §Security classification).
     */
    private function assertTokenNeverLogged(string $token): void
    {
        $hash = hash('sha256', $token);
        $payloads = AuditEvent::query()->pluck('payload');

        foreach ($payloads as $payload) {
            $json = is_string($payload) ? $payload : json_encode($payload);
            $this->assertStringNotContainsString($token, (string) $json);
            $this->assertStringNotContainsString($hash, (string) $json);
        }
    }

    private function blobBytes(DocumentBlob $blob): string
    {
        $stream = app(DocumentStore::class)->get($blob);
        $bytes = '';
        while (! $stream->eof()) {
            $bytes .= $stream->read(1024 * 1024);
        }

        return $bytes;
    }
}
