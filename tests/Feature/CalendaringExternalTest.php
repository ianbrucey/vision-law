<?php

namespace Tests\Feature;

use App\Mail\CalendarInviteMail;
use App\Models\AuditEvent;
use App\Models\CalendarItem;
use App\Services\Calendaring\ItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * C-17 (009 T-14, Phase A external coordination): guest scope +
 * revocation, ICS export/feed scoping, and the invite
 * REQUEST → UPDATE → CANCEL lifecycle on one stable UID.
 */
class CalendaringExternalTest extends TestCase
{
    use RefreshDatabase;

    private FixtureLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = FixtureLoader::loadCalendaringFixtures();
    }

    public function test_guest_coordination_scope_and_revocation(): void
    {
        $attorney = $this->loader->user('user-attorney');
        $admin = $this->loader->user('user-admin');
        $matter1 = $this->loader->matter('matter-1');
        $hard = $this->loader->calendarItem('item-hard-deadline');
        $restrictedItem = $this->loader->calendarItem('item-internal-note');

        // --- ICS export: authorized items only -------------------------
        // A rival-org item exists; it must never appear.
        $rivalMatter = $this->loader->matter('matter-rival');
        CalendarItem::create([
            'org_id' => (string) $rivalMatter->org_id,
            'matter_id' => (string) $rivalMatter->getKey(),
            'type' => 'deadline',
            'title' => 'Rival export sentinel',
            'status' => 'confirmed',
            'due_date' => now()->addDays(2)->toDateString(),
            'timezone' => 'UTC',
        ]);

        $export = $this->actingAs($attorney)->get(route('calendar.export'));
        $export->assertOk();
        $export->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        $body = (string) $export->getContent();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $body);
        $this->assertStringContainsString('Summary judgment response due', $body);
        $this->assertStringContainsString('UID:'.(string) $hard->getKey().'@vision.justicequest.pro', $body);
        // The DST event exports as the UTC instant: 09:00 EST = 14:00 UTC.
        $this->assertStringContainsString('DTSTART:20261101T140000Z', $body);
        $this->assertStringContainsString('DTEND:20261101T160000Z', $body);
        $this->assertStringNotContainsString('Rival export sentinel', $body);
        // Draft items are not external items.
        $this->assertStringNotContainsString('Unconfirmed draft deadline', $body);
        // CRLF line endings per RFC 5545.
        $this->assertStringContainsString("\r\n", $body);

        // The grantless org admin's export omits the restricted item.
        $adminBody = (string) $this->actingAs($admin)->get(route('calendar.export'))->assertOk()->getContent();
        $this->assertStringNotContainsString($restrictedItem->title, $adminBody);
        $this->assertStringContainsString('Summary judgment response due', $adminBody);

        // --- Subscription feed: issue, fetch, revoke -------------------
        $feed = $this->actingAs($attorney)->postJson(route('calendar.feeds.store'));
        $feed->assertCreated();
        $feedToken = (string) $feed->json('token');
        $this->assertSame(48, strlen($feedToken));
        // Only the hash is stored.
        $this->assertDatabaseMissing('calendar_feeds', ['token_hash' => $feedToken]);
        $this->assertDatabaseHas('calendar_feeds', ['token_hash' => hash('sha256', $feedToken)]);

        $feedBody = (string) $this->get('/feeds/calendar/'.$feedToken.'.ics')->assertOk()->getContent();
        $this->assertStringContainsString('Summary judgment response due', $feedBody);
        $this->assertStringNotContainsString('Rival export sentinel', $feedBody);

        // Revocation kills the URL immediately.
        $this->actingAs($attorney)->deleteJson(route('calendar.feeds.destroy', $feed->json('id')))->assertOk();
        $this->get('/feeds/calendar/'.$feedToken.'.ics')->assertNotFound();
        // Unknown tokens also 404 with no signal.
        $this->get('/feeds/calendar/'.str_repeat('x', 48).'.ics')->assertNotFound();

        // Fixture feeds: the active attorney feed works, the revoked
        // paralegal feed 404s.
        $activeToken = $this->loader->calendaringToken('feed-attorney');
        $this->assertNotNull($activeToken);
        $fixtureBody = (string) $this->get('/feeds/calendar/'.$activeToken.'.ics')->assertOk()->getContent();
        $this->assertStringContainsString('Summary judgment response due', $fixtureBody);
        $revokedToken = $this->loader->calendaringToken('feed-revoked');
        $this->assertNotNull($revokedToken);
        $this->get('/feeds/calendar/'.$revokedToken.'.ics')->assertNotFound();

        // --- Guest share: only allowlisted fields render ---------------
        $secret = ItemService::create($matter1, [
            'type' => 'event',
            'title' => 'Settlement conference',
            'description' => 'CONFIDENTIAL-INTERNAL-NOTE',
            'location' => 'Secret Room 9',
            'starts_at' => '2026-12-05T15:00:00Z',
            'ends_at' => '2026-12-05T16:00:00Z',
            'timezone' => 'America/New_York',
            'status' => 'confirmed',
        ], $attorney);

        $share = $this->actingAs($attorney)->postJson(route('calendar.shares.store', $secret), [
            'email' => 'r.rivera@outside.example',
            'fields' => ['title', 'date'],
        ]);
        $share->assertCreated();
        $shareToken = (string) $share->json('token');

        $guest = $this->get('/guest/calendar/'.$shareToken);
        $guest->assertOk();
        $guest->assertSee('Settlement conference');
        $guest->assertSee('2026-12-05');
        // Not allowlisted → never rendered.
        $guest->assertDontSee('Secret Room 9');
        $guest->assertDontSee('CONFIDENTIAL-INTERNAL-NOTE');
        // The matter title is NOT in the allowlist — never rendered.
        $guest->assertDontSee('Sterling v. Apex Construction');

        // A share WITH location/description in its allowlist shows them.
        $full = $this->actingAs($attorney)->postJson(route('calendar.shares.store', $secret), [
            'email' => 'r.rivera@outside.example',
            'fields' => ['title', 'date', 'time', 'location', 'description'],
        ]);
        $full->assertCreated();
        $fullGuest = $this->get('/guest/calendar/'.$full->json('token'));
        $fullGuest->assertOk();
        $fullGuest->assertSee('Secret Room 9');
        $fullGuest->assertSee('CONFIDENTIAL-INTERNAL-NOTE');
        $fullGuest->assertDontSee('Sterling v. Apex Construction');

        // Fixture share (active) renders the shared item.
        $fixtureShareToken = $this->loader->calendaringToken('share-active');
        $this->assertNotNull($fixtureShareToken);
        $this->get('/guest/calendar/'.$fixtureShareToken)
            ->assertOk()
            ->assertSee('Summary judgment response due')
            ->assertDontSee('Sterling v. Apex Construction');

        // Revoking the share 404s the guest URL immediately.
        $this->actingAs($attorney)->deleteJson(route('calendar.shares.revoke', $share->json('id')))->assertOk();
        $this->get('/guest/calendar/'.$shareToken)->assertNotFound();

        // Expired shares 404 too.
        $expired = $this->actingAs($attorney)->postJson(route('calendar.shares.store', $secret), [
            'email' => 'r.rivera@outside.example',
            'fields' => ['title'],
            'expires_at' => now()->subDay()->toIso8601String(),
        ]);
        $expired->assertCreated();
        $this->get('/guest/calendar/'.$expired->json('token'))->assertNotFound();

        // Fixture revoked share 404s.
        $revokedShareToken = $this->loader->calendaringToken('share-revoked');
        $this->assertNotNull($revokedShareToken);
        $this->get('/guest/calendar/'.$revokedShareToken)->assertNotFound();

        // Audit: feed/share lifecycle events exist, keyed by id only.
        foreach (['calendar.feed.created', 'calendar.feed.revoked', 'calendar.share.created', 'calendar.share.revoked', 'calendar.export.performed'] as $event) {
            $this->assertTrue(
                AuditEvent::query()->where('event', $event)->exists(),
                "missing audit event {$event}"
            );
        }
    }

    public function test_ics_invite_update_cancel_lifecycle(): void
    {
        Mail::fake();

        $attorney = $this->loader->user('user-attorney');
        $item = $this->loader->calendarItem('item-event-dst');
        $uid = 'UID:'.(string) $item->getKey().'@vision.justicequest.pro';

        // Invite: REQUEST, SEQUENCE = current version, one UID.
        $this->actingAs($attorney)->postJson(route('calendar.items.invite', $item), [
            'email' => 'r.rivera@outside.example',
        ])->assertOk()->assertJsonPath('method', 'REQUEST');

        Mail::assertSent(CalendarInviteMail::class, function (CalendarInviteMail $mail) use ($uid) {
            $this->assertCount(1, $mail->attachments());
            $this->assertStringContainsString($uid, $mail->ics);
            $this->assertStringContainsString('METHOD:REQUEST', $mail->ics);

            return $mail->hasTo('r.rivera@outside.example');
        });
        $firstIcs = $this->sentInviteIcs();
        $this->assertStringContainsString($uid, $firstIcs);
        $this->assertStringContainsString('SEQUENCE:1', $firstIcs);

        // Update the item (version bumps) and re-invite: same UID,
        // rising SEQUENCE — an update, not a duplicate.
        $fresh = $item->fresh();
        ItemService::update($fresh, [
            'location' => 'Court reporting suite B',
            'version' => (int) $fresh->version,
        ], $attorney);

        $this->actingAs($attorney)->postJson(route('calendar.items.invite', $item), [
            'email' => 'r.rivera@outside.example',
        ])->assertOk();
        $secondIcs = $this->sentInviteIcs(1);
        $this->assertStringContainsString($uid, $secondIcs);
        $this->assertStringContainsString('SEQUENCE:2', $secondIcs);
        $this->assertStringContainsString('METHOD:REQUEST', $secondIcs);

        // Cancel the item and send the cancellation: same UID,
        // SEQUENCE rises again, METHOD:CANCEL + STATUS:CANCELLED.
        $fresh = $item->fresh();
        ItemService::transition($fresh, 'cancel', $attorney, 'Deposition called off');

        $this->actingAs($attorney)->postJson(route('calendar.items.invite', $item), [
            'email' => 'r.rivera@outside.example',
        ])->assertOk()->assertJsonPath('method', 'CANCEL');
        $thirdIcs = $this->sentInviteIcs(2);
        $this->assertStringContainsString($uid, $thirdIcs);
        $this->assertStringContainsString('SEQUENCE:3', $thirdIcs);
        $this->assertStringContainsString('METHOD:CANCEL', $thirdIcs);
        $this->assertStringContainsString('STATUS:CANCELLED', $thirdIcs);

        Mail::assertSentCount(3);
    }

    /**
     * Extract the .ics payload of the n-th sent CalendarInviteMail.
     */
    private function sentInviteIcs(int $index = 0): string
    {
        $sent = Mail::sent(CalendarInviteMail::class);
        $mail = $sent[$index] ?? null;
        $this->assertNotNull($mail, "invite mail #{$index} was not sent");

        return (string) $mail->ics;
    }
}
