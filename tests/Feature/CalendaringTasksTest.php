<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CalendarItem;
use App\Models\ChecklistItem;
use App\Models\TaskDependency;
use App\Services\Calendaring\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\FixtureLoader;
use Tests\TestCase;

class CalendaringTasksTest extends TestCase
{
    use RefreshDatabase;

    private FixtureLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = FixtureLoader::loadCalendaringFixtures();
    }

    public function test_task_assignment_status_and_triage(): void
    {
        $attorney = $this->loader->user('user-attorney');
        $paralegal = $this->loader->user('user-paralegal');
        $viewer = $this->loader->user('user-viewer');
        $taskA = $this->loader->calendarItem('item-task-a');
        $triage = $this->loader->calendarItem('item-task-triage');

        // Fixture wiring: task-a starts assigned to paralegal.
        $this->assertSame((string) $paralegal->getKey(), (string) $taskA->assignee_id);

        // Reassign paralegal -> attorney: BOTH parties get a database
        // notification (C-06).
        $this->actingAs($attorney)->postJson(route('calendar.tasks.assign', $taskA), [
            'assignee_id' => (string) $attorney->getKey(),
        ])->assertOk()->assertJsonPath('assignee_id', (string) $attorney->getKey());

        $this->assertGreaterThanOrEqual(1, $paralegal->fresh()->notifications()->count(), 'old assignee notified');
        $this->assertGreaterThanOrEqual(1, $attorney->fresh()->notifications()->count(), 'new assignee notified');
        $paralegalNote = $paralegal->fresh()->notifications()->first();
        $this->assertSame((string) $taskA->getKey(), $paralegalNote->data['item_id'] ?? null);
        $this->assertTrue(AuditEvent::query()->where('event', 'calendar.task.assigned')
            ->where('payload->item_id', (string) $taskA->getKey())->exists());

        // Triage: unassigned confirmed task is visible to an authorized
        // user and invisible to the grantless viewer.
        $this->actingAs($attorney)->get(route('calendar.triage'))
            ->assertOk()->assertSee($triage->title);
        $this->actingAs($attorney)->getJson(route('calendar.triage'))
            ->assertOk()->assertJsonFragment(['id' => (string) $triage->getKey()]);

        $viewerPage = $this->actingAs($viewer)->get(route('calendar.triage'));
        $viewerPage->assertOk();
        $viewerPage->assertDontSee($triage->title);
        $viewerJson = $this->actingAs($viewer)->getJson(route('calendar.triage'));
        $viewerJson->assertOk();
        $this->assertSame([], $viewerJson->json('items'));

        // Completing a task records completed_by / completed_at.
        $this->actingAs($attorney)->postJson(route('calendar.tasks.status', $taskA), [
            'task_status' => 'completed',
        ])->assertOk()->assertJsonPath('task_status', 'completed');

        $done = CalendarItem::findOrFail($taskA->getKey());
        $this->assertNotNull($done->completed_at);
        $this->assertSame((string) $attorney->getKey(), (string) $done->completed_by);
        $this->assertSame('completed', $done->status);

        // Reopening clears the completion stamps and audits it.
        $reopened = TaskService::transitionStatus($done, 'in_progress', $attorney);
        $this->assertNull($reopened->completed_at);
        $this->assertNull($reopened->completed_by);
        $this->assertTrue(AuditEvent::query()->where('event', 'calendar.task.reopened')
            ->where('payload->item_id', (string) $taskA->getKey())->exists());
    }

    public function test_dependency_cycle_rejected_and_checklist_gate_enforced(): void
    {
        $attorney = $this->loader->user('user-attorney');
        $taskA = $this->loader->calendarItem('item-task-a');
        $taskB = $this->loader->calendarItem('item-task-b');

        // Fixture wiring: B depends on A, with 3 checklist entries
        // (2 required open, 1 optional done).
        $this->assertTrue(TaskDependency::query()
            ->where('predecessor_item_id', $taskA->getKey())
            ->where('successor_item_id', $taskB->getKey())->exists());
        $entries = ChecklistItem::query()->where('item_id', $taskB->getKey())->orderBy('position')->get();
        $this->assertCount(3, $entries);
        $this->assertSame(2, $entries->where('required', true)->where('done', false)->count());
        $this->assertSame(1, $entries->where('required', false)->where('done', true)->count());

        // Adding B -> A would close a cycle (A -> B exists): rejected 422.
        $this->actingAs($attorney)->postJson(route('calendar.tasks.dependencies.store', $taskA), [
            'predecessor_id' => (string) $taskB->getKey(),
        ])->assertStatus(422);
        $this->assertFalse(TaskDependency::query()
            ->where('predecessor_item_id', $taskB->getKey())
            ->where('successor_item_id', $taskA->getKey())->exists());

        // Duplicate edge also rejected.
        $this->actingAs($attorney)->postJson(route('calendar.tasks.dependencies.store', $taskB), [
            'predecessor_id' => (string) $taskA->getKey(),
        ])->assertStatus(422);

        // Impact preview: moving A past B's due date lists B with a
        // proposed due equal to A's new due date (zero lag).
        $newDue = $taskB->due_date->copy()->addDays(5)->toDateString();
        $preview = $this->actingAs($attorney)->postJson(route('calendar.tasks.dependencies.preview-move', $taskA), [
            'new_due_date' => $newDue,
        ]);
        $preview->assertOk();
        $impact = collect($preview->json('impact'));
        $row = $impact->firstWhere('item_id', (string) $taskB->getKey());
        $this->assertNotNull($row, 'preview must list the violating successor');
        $this->assertSame($taskB->due_date->toDateString(), $row['current_due']);
        $this->assertSame($newDue, $row['proposed_due']);

        // Apply the move: A and B land on the new date.
        $this->actingAs($attorney)->postJson(route('calendar.tasks.dependencies.apply-move', $taskA), [
            'new_due_date' => $newDue,
        ])->assertOk();
        $this->assertSame($newDue, CalendarItem::findOrFail($taskA->getKey())->due_date?->toDateString());
        $this->assertSame($newDue, CalendarItem::findOrFail($taskB->getKey())->due_date?->toDateString());

        // Checklist gate: completing B with open required items is refused.
        $this->actingAs($attorney)->postJson(route('calendar.tasks.status', $taskB), [
            'task_status' => 'completed',
        ])->assertStatus(422);
        $this->assertNotSame('completed', CalendarItem::findOrFail($taskB->getKey())->task_status);

        // With an override_reason from an editor it completes and the
        // override is audited with its reason.
        $this->actingAs($attorney)->postJson(route('calendar.tasks.status', $taskB), [
            'task_status' => 'completed',
            'override_reason' => 'Filing deadline today; checklist verified verbally',
        ])->assertOk()->assertJsonPath('task_status', 'completed');

        $audit = AuditEvent::query()->where('event', 'calendar.task.checklist_overridden')
            ->where('payload->item_id', (string) $taskB->getKey())->first();
        $this->assertNotNull($audit);
        $this->assertSame('Filing deadline today; checklist verified verbally', $audit->payload['reason'] ?? null);

        // Toggling the required items done lets a later task complete
        // without an override.
        $taskC = CalendarItem::create([
            'org_id' => (string) $taskB->org_id,
            'matter_id' => (string) $taskB->matter_id,
            'type' => 'task',
            'title' => 'Gate check task',
            'status' => 'confirmed',
            'task_status' => 'not_started',
            'due_date' => now()->addDays(3)->toDateString(),
            'timezone' => 'America/New_York',
        ]);
        $entry = ChecklistItem::create([
            'item_id' => (string) $taskC->getKey(),
            'label' => 'Only required step',
            'required' => true,
            'done' => false,
            'position' => 0,
        ]);
        $this->actingAs($attorney)->postJson(route('calendar.tasks.checklist.toggle', [$taskC, $entry]), [
            'done' => true,
        ])->assertOk()->assertJsonPath('done', true);
        $this->actingAs($attorney)->postJson(route('calendar.tasks.status', $taskC), [
            'task_status' => 'completed',
        ])->assertOk()->assertJsonPath('task_status', 'completed');
    }
}
