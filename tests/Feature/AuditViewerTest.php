<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * Ticket 7 verdict tests — admin audit viewer (C-13).
 * Backend only (001-D06: no Blade); JSON endpoints + CSV download.
 *
 * - GET /admin/audit-events: filters (actor, object type, action, date
 *   range, free text), pagination, org-scoped (001-D13: system-org events
 *   are out of scope for the org viewer).
 * - GET /admin/audit-events/export: watermarked CSV behind password.confirm;
 *   audited as audit.exported.
 * - Non-admins: 403 {code:"forbidden"}, audited as audit.viewer.denied.
 */
class AuditViewerTest extends TestCase
{
    use RefreshDatabase;

    private function loginAs(string $email, string $password = FixtureLoader::DEFAULT_PASSWORD): void
    {
        $response = $this->postJson('/login', ['email' => $email, 'password' => $password]);
        $response->assertOk();

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === (string) config('session.cookie'));

        $this->assertNotNull($cookie);
        $this->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), $cookie->getValue());
    }

    /**
     * C-13: non-admins get 403 on the audit viewer (index and export), and
     * the denial is audited as audit.viewer.denied (03-contract.md
     * §Error catalog). Guests get 401.
     */
    public function test_audit_viewer_forbidden_for_non_admin(): void
    {
        $fixtures = FixtureLoader::load();
        $attorney = $fixtures->user('user_attorney_granted');
        $outside = $fixtures->user('user_outside');

        // — attorney (non-admin) —
        $this->loginAs((string) $attorney->email);

        $this->getJson('/admin/audit-events')
            ->assertForbidden()
            ->assertJson(['code' => 'forbidden']);

        $denied = AuditEvent::where('event', 'audit.viewer.denied')
            ->latest('created_at')
            ->firstOrFail();
        $this->assertSame((string) $attorney->getKey(), $denied->payload['actor_id']);

        // Export denies at RequireOrgAdmin BEFORE password.confirm: 403, not 423.
        $this->getJson('/admin/audit-events/export')
            ->assertForbidden()
            ->assertJson(['code' => 'forbidden']);

        $this->assertSame(2, AuditEvent::where('event', 'audit.viewer.denied')->count());

        // — outside counsel (non-admin) —
        $this->actingAs($outside);
        $this->getJson('/admin/audit-events')
            ->assertForbidden()
            ->assertJson(['code' => 'forbidden']);

        $this->assertSame(3, AuditEvent::where('event', 'audit.viewer.denied')->count());
    }

    public function test_audit_viewer_forbidden_for_guest(): void
    {
        FixtureLoader::load();

        $this->getJson('/admin/audit-events')->assertUnauthorized();
        $this->getJson('/admin/audit-events/export')->assertUnauthorized();
    }

    public function test_audit_viewer_index_filters_and_pagination_for_admin(): void
    {
        $fixtures = FixtureLoader::load();
        $admin = $fixtures->user('user_admin');
        $attorney = $fixtures->user('user_attorney_granted');
        $matter = $fixtures->matter('matter_001');

        AuditLogger::log('auth.login', $admin, ['actor_id' => (string) $admin->getKey(), 'ip' => '10.0.0.1']);
        AuditLogger::log('auth.login', $attorney, ['actor_id' => (string) $attorney->getKey(), 'ip' => '10.0.0.2']);
        AuditLogger::log(
            'matter.grant.created',
            $admin,
            ['actor_id' => (string) $admin->getKey(), 'note' => 'zebracorn-unique-xyz'],
            $matter
        );

        $this->actingAs($admin);

        // — unfiltered: all three org events, newest first —
        $index = $this->getJson('/admin/audit-events')->assertOk();
        $this->assertSame(3, $index->json('total'));
        $this->assertSame('matter.grant.created', $index->json('data.0.event'));
        $this->assertSame('Ada Admin', $index->json('data.0.actor.name'));
        $this->assertSame('admin@sterling.test', $index->json('data.0.actor.email'));
        $this->assertSame('matter', $index->json('data.0.object_type'));

        // — action filter —
        $byAction = $this->getJson('/admin/audit-events?action=auth.login')->assertOk();
        $this->assertSame(2, $byAction->json('total'));

        // — object_type filter —
        $byType = $this->getJson('/admin/audit-events?object_type=matter')->assertOk();
        $this->assertSame(1, $byType->json('total'));
        $this->assertSame('matter.grant.created', $byType->json('data.0.event'));

        // — actor filter: UUID and email-substring forms —
        $byActorId = $this->getJson('/admin/audit-events?actor='.$attorney->getKey())->assertOk();
        $this->assertSame(1, $byActorId->json('total'));
        $this->assertSame('Grace Granted', $byActorId->json('data.0.actor.name'));

        $byActorEmail = $this->getJson('/admin/audit-events?actor=grace@sterling')->assertOk();
        $this->assertSame(1, $byActorEmail->json('total'));

        // — free-text filter over payload —
        $byText = $this->getJson('/admin/audit-events?q=zebracorn-unique-xyz')->assertOk();
        $this->assertSame(1, $byText->json('total'));
        $this->assertSame('matter.grant.created', $byText->json('data.0.event'));

        // — date-range filter: today matches, tomorrow does not —
        $today = now()->format('Y-m-d');
        $tomorrow = now()->addDay()->format('Y-m-d');
        $this->assertSame(3, $this->getJson("/admin/audit-events?from={$today}&to={$today}")->json('total'));
        $this->assertSame(0, $this->getJson("/admin/audit-events?from={$tomorrow}")->json('total'));

        // — pagination —
        $paged = $this->getJson('/admin/audit-events?per_page=2')->assertOk();
        $this->assertSame(2, $paged->json('per_page'));
        $this->assertSame(3, $paged->json('total'));
        $this->assertCount(2, $paged->json('data'));

        // — invalid filter input → 422, contract error shape —
        $this->getJson('/admin/audit-events?per_page=500')
            ->assertStatus(422)
            ->assertJson(['code' => 'validation']);
    }

    /**
     * 001-D13: system-org events (e.g. unattributable login failures) are
     * out of scope for the org viewer — they never appear, even under a
     * free-text search that matches their payload.
     */
    public function test_audit_viewer_excludes_system_org_events(): void
    {
        $fixtures = FixtureLoader::load();
        $admin = $fixtures->user('user_admin');

        Organization::system();
        $systemEvent = AuditLogger::log(
            'auth.login.failed',
            null,
            ['ip' => '203.0.113.9', 'email_domain_digest' => 'system-probe-unique'],
            explicitOrgId: Organization::SYSTEM_ID
        );

        AuditLogger::log('auth.login', $admin, ['actor_id' => (string) $admin->getKey()]);

        $this->actingAs($admin);

        $index = $this->getJson('/admin/audit-events')->assertOk();
        $ids = collect($index->json('data'))->pluck('id')->all();
        $this->assertNotContains((string) $systemEvent->getKey(), $ids);

        // Even a free-text search matching the system payload must not
        // surface it in the org viewer.
        $search = $this->getJson('/admin/audit-events?q=system-probe-unique')->assertOk();
        $this->assertSame(0, $search->json('total'));
    }

    public function test_audit_export_requires_password_confirmation_and_is_watermarked(): void
    {
        $fixtures = FixtureLoader::load();
        $admin = $fixtures->user('user_admin');

        AuditLogger::log('auth.login', $admin, ['actor_id' => (string) $admin->getKey()]);

        $this->actingAs($admin);

        // Behind password.confirm: 423 before confirmation …
        $this->getJson('/admin/audit-events/export')->assertStatus(423);

        $this->postJson('/user/confirm-password', ['password' => FixtureLoader::DEFAULT_PASSWORD])
            ->assertCreated();

        // … then a watermarked CSV download.
        $export = $this->get('/admin/audit-events/export')->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $export->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $export->headers->get('Content-Disposition'));

        $csv = $export->streamedContent();
        $this->assertStringContainsString('# vision-law audit log export', $csv);
        $this->assertStringContainsString('# exported-by: admin@sterling.test', $csv);
        $this->assertStringContainsString('# org: Sterling & Associates LLP', $csv);
        $this->assertStringContainsString('# filters:', $csv);
        $this->assertStringContainsString(
            'id,created_at,event,actor_id,actor_email,object_type,object_id,matter_id,ip,payload',
            $csv
        );
        $this->assertStringContainsString('auth.login', $csv);

        // The export itself is audited as audit.exported (allow event).
        $exported = AuditEvent::where('event', 'audit.exported')->latest('created_at')->firstOrFail();
        $this->assertSame((string) $admin->getKey(), $exported->payload['actor_id']);
        $this->assertNotEmpty($exported->payload['watermark']);
        $this->assertSame(1, $exported->payload['row_count']);
    }

    public function test_audit_export_respects_filters(): void
    {
        $fixtures = FixtureLoader::load();
        $admin = $fixtures->user('user_admin');

        AuditLogger::log('auth.login', $admin, ['actor_id' => (string) $admin->getKey()]);
        AuditLogger::log('auth.logout', $admin, ['actor_id' => (string) $admin->getKey()]);

        $this->actingAs($admin);
        $this->postJson('/user/confirm-password', ['password' => FixtureLoader::DEFAULT_PASSWORD])
            ->assertCreated();

        $csv = $this->get('/admin/audit-events/export?action=auth.login')->assertOk()->streamedContent();
        $this->assertStringContainsString('auth.login', $csv);
        $this->assertStringNotContainsString('auth.logout', $csv);
    }
}
