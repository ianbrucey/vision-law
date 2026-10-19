<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\DeadlineTemplateVersion;
use App\Services\Calendaring\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

/**
 * C-13 (009 T-10): template catalog and builder — the lifecycle
 * draft → pending_review → published → retired runs with
 * maker-checker publication, a published version is immutable
 * (edits fork a new draft version; the published content is
 * unchanged), and the catalog shows the DEMONSTRATION label and
 * content owner.
 */
class CalendaringTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private FixtureLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = FixtureLoader::loadCalendaringFixtures();
    }

    /**
     * A small valid step-rule content envelope (009-D19).
     *
     * @return array<string, mixed>
     */
    private function validRules(): array
    {
        return [
            'demonstration' => true,
            'holiday_pack' => 'ga-state-2026',
            'owner' => 'Justice Quest — demonstration content, not legal advice',
            'triggers' => [
                ['key' => 'service_date', 'kind' => 'date', 'label' => 'Date of service of process'],
            ],
            'items' => [
                ['key' => 'answer', 'title' => 'Answer due', 'type' => 'deadline', 'classification' => 'hard',
                    'rule' => ['anchor' => 'service_date', 'steps' => [
                        ['op' => 'add_calendar_days', 'n' => 30],
                        ['op' => 'adjust', 'mode' => 'next_court_day'],
                    ]]],
            ],
        ];
    }

    public function test_template_lifecycle_and_immutable_published_versions(): void
    {
        $attorney = $this->loader->user('user-attorney');
        $admin = $this->loader->user('user-admin');
        $rival = $this->loader->user('user-rival');

        // ── Fixture catalog: published v1 + draft v2, DEMONSTRATION. ──
        $template = $this->loader->deadlineTemplate('tpl-demo-litigation');
        $this->assertTrue((bool) $template->demonstration);
        $this->assertSame('Justice Quest — demonstration content, not legal advice', $template->owner_label);

        $v1 = DeadlineTemplateVersion::query()
            ->where('template_id', (string) $template->getKey())->where('version', 1)->firstOrFail();
        $v2 = DeadlineTemplateVersion::query()
            ->where('template_id', (string) $template->getKey())->where('version', 2)->firstOrFail();
        $this->assertSame('published', $v1->status);
        $this->assertSame('draft', $v2->status);
        $this->assertSame((string) $attorney->getKey(), (string) $v1->created_by);
        $this->assertSame((string) $admin->getKey(), (string) $v1->reviewed_by);
        $this->assertNotNull($v1->published_at);

        // Catalog pages render the demonstration label + owner.
        $this->actingAs($attorney)
            ->get(route('templates.deadlines.index'))
            ->assertOk()
            ->assertSee($template->name)
            ->assertSee('DEMONSTRATION')
            ->assertSee('demonstration content, not legal advice');
        $this->actingAs($attorney)
            ->get(route('templates.deadlines.show', $template))
            ->assertOk()
            ->assertSee($template->name)
            ->assertSee('DEMONSTRATION');

        // Cross-org: no existence signal.
        $this->actingAs($rival)
            ->get(route('templates.deadlines.show', $template))
            ->assertNotFound();
        $rivalIndex = $this->actingAs($rival)->getJson(route('templates.deadlines.index'));
        $rivalIndex->assertOk();
        $this->assertStringNotContainsString($template->name, (string) $rivalIndex->getContent());

        // ── Lifecycle over HTTP with maker-checker. ──
        $create = $this->actingAs($attorney)->postJson(route('templates.deadlines.store'), [
            'name' => 'Maker Checker Probe',
            'jurisdiction_label' => 'Georgia',
            'owner_label' => 'Justice Quest — demonstration content, not legal advice',
        ]);
        $create->assertCreated();
        $probeId = $create->json('id');
        $this->assertIsString($probeId);

        $versionCreate = $this->actingAs($attorney)->postJson(
            route('templates.deadlines.versions.store', $probeId),
            ['rules' => $this->validRules()]
        );
        $versionCreate->assertCreated()->assertJsonPath('status', 'draft');
        $probeVersionId = $versionCreate->json('id');
        $this->assertIsString($probeVersionId);

        // Publish is allowed ONLY from pending_review.
        $this->actingAs($admin)->postJson(
            route('templates.deadlines.versions.publish', [$probeId, $probeVersionId])
        )->assertStatus(422);

        $this->actingAs($attorney)->postJson(
            route('templates.deadlines.versions.submit', [$probeId, $probeVersionId])
        )->assertOk()->assertJsonPath('status', 'pending_review');

        // Maker-checker: the creator cannot publish their own version.
        $this->actingAs($attorney)->postJson(
            route('templates.deadlines.versions.publish', [$probeId, $probeVersionId])
        )->assertStatus(422);
        $this->assertSame('pending_review', DeadlineTemplateVersion::findOrFail($probeVersionId)->status);

        // A second user publishes; reviewer + timestamp are stamped.
        $published = $this->actingAs($admin)->postJson(
            route('templates.deadlines.versions.publish', [$probeId, $probeVersionId])
        );
        $published->assertOk()->assertJsonPath('status', 'published');
        $published->assertJsonPath('reviewed_by', (string) $admin->getKey());
        $this->assertNotNull(DeadlineTemplateVersion::findOrFail($probeVersionId)->published_at);

        // Audit trail for the catalog lifecycle.
        $this->assertTrue(AuditEvent::query()->where('event', 'calendar.template.created')
            ->where('payload->template_id', $probeId)->exists());
        $this->assertTrue(AuditEvent::query()->where('event', 'calendar.template.published')
            ->where('payload->version_id', $probeVersionId)->exists());

        // Retire works from published only.
        $this->actingAs($admin)->postJson(
            route('templates.deadlines.versions.retire', [$probeId, $probeVersionId])
        )->assertOk()->assertJsonPath('status', 'retired');
        $this->actingAs($admin)->postJson(
            route('templates.deadlines.versions.retire', [$probeId, $probeVersionId])
        )->assertStatus(422);

        // ── Invalid rules are a 422 at save (cycle + unknown op). ──
        $cycleRules = [
            'holiday_pack' => 'ga-state-2026',
            'items' => [
                ['key' => 'a', 'title' => 'A', 'rule' => ['anchor' => 'b', 'steps' => [['op' => 'add_calendar_days', 'n' => 1]]]],
                ['key' => 'b', 'title' => 'B', 'rule' => ['anchor' => 'a', 'steps' => [['op' => 'add_calendar_days', 'n' => 1]]]],
            ],
        ];
        $this->actingAs($attorney)->postJson(
            route('templates.deadlines.versions.store', (string) $template->getKey()),
            ['rules' => $cycleRules]
        )->assertStatus(422);

        // ── Published versions are immutable: editing forks a draft. ──
        $rulesBefore = $v1->rules;
        $checksumBefore = hash('sha256', json_encode($rulesBefore, JSON_THROW_ON_ERROR));

        $revised = $this->validRules();
        $revised['items'][0]['title'] = 'Answer due (revised)';

        $fork = TemplateService::updateVersionRules($attorney, $v1, $revised);

        $this->assertNotSame((string) $v1->getKey(), (string) $fork->getKey());
        $this->assertSame('draft', $fork->status);
        $this->assertSame(3, $fork->version);
        $this->assertSame($revised, $fork->rules);

        $v1After = DeadlineTemplateVersion::findOrFail((string) $v1->getKey());
        $this->assertSame('published', $v1After->status);
        $this->assertSame($rulesBefore, $v1After->rules);
        $this->assertSame($checksumBefore, hash('sha256', json_encode($v1After->rules, JSON_THROW_ON_ERROR)));

        // Retiring a draft is refused.
        $this->actingAs($admin)->postJson(
            route('templates.deadlines.versions.retire', [(string) $template->getKey(), (string) $fork->getKey()])
        )->assertStatus(422);

        // ── Current published is derived: publishing the fork makes
        // it current WITHOUT retiring v1 automatically. ──
        $this->assertSame(1, TemplateService::currentPublished($template)?->version);

        TemplateService::submit($attorney, $fork);
        TemplateService::publish($admin, $fork->fresh() ?? $fork);

        $this->assertSame(3, TemplateService::currentPublished($template)?->version);
        $this->assertSame('published', DeadlineTemplateVersion::findOrFail((string) $v1->getKey())->status,
            'publishing a newer version retires nothing automatically');
    }
}
