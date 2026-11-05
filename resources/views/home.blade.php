{{--
    home.blade.php — the authenticated app home (spec 010 T-02 data
    contract; Spec 012 home redesign, 2026-11-04).

    The Glass/Aurora dashboard in layouts/dashboard: a today strip and
    the deadlines module, real matter cards with a working filter, the
    recent-activity and security modules — all assembled by
    HomeController from existing data (010 C-03/C-09 scoping unchanged) —
    and the front-desk chat panel as the grid's right-most column. The
    chat is wired live (Spec 012 chat slice, 2026-11-05) and renders
    product copy only — no preview/sample/wiring commentary.

    When the user's organization has no matters at all, the dashboard is
    replaced by the guided empty state (010 C-04) — no placeholder
    widgets, no front desk.

    Expects: $user, $orgName, $matters (Collection<Matter>), $matterCount,
    $activeMatterCount, $activity (Collection<AuditEvent>), $orgHasMatters,
    $canCreateMatter, $isOrgAdmin, $twoFactorConfirmed, $passkeyCount,
    and $deadlines (009 module data incl. dueToday, or null when the user
    has no accessible matters — the module is then omitted, per 010 C-04).
--}}
<x-layouts.dashboard title="Home · Vision Law">
    @php
        // Matter lifecycle labels mirror the canonical map
        // (App\View\Components\Ui\Status::MAP['matter']); tones are
        // translated to the Glass/Aurora tag palette.
        $stageMap = [
            'INTAKE' => ['Intake', 'ga-tag-acc'],
            'ACTIVE' => ['Active', 'ga-tag-ok'],
            'DISCOVERY' => ['Discovery', 'ga-tag-acc'],
            'PRE_TRIAL' => ['Pre-trial', 'ga-tag-warn'],
            'TRIAL_SETTLEMENT' => ['Trial / settlement', 'ga-tag-warn'],
            'CLOSED' => ['Closed', 'ga-tag-mut'],
            'RETENTION_HOLD' => ['Retention hold', 'ga-tag-warn'],
            'DISPOSITION' => ['Disposition', 'ga-tag-bad'],
        ];
        // Calendar-item labels mirror Status::MAP['calendar_item'].
        $itemStatusMap = [
            'draft' => ['Draft', 'ga-tag-warn'],
            'confirmed' => ['Confirmed', 'ga-tag-ok'],
            'at_risk' => ['At risk', 'ga-tag-bad'],
            'completed' => ['Completed', 'ga-tag-ok'],
            'superseded' => ['Superseded', 'ga-tag-mut'],
            'canceled' => ['Canceled', 'ga-tag-mut'],
        ];
        // Home-local activity describer (010-D05 — the 006 timeline's
        // sentence map stays untouched). Unknown events fall through to
        // a humanized event name rather than raw enum text.
        $describe = function (\App\Models\AuditEvent $event): string {
            return match ($event->event) {
                'matter.created' => 'created the matter',
                'matter.updated' => 'updated the matter',
                'matter.comment_added' => 'commented on the matter',
                'matter.transition' => 'changed the matter state',
                'document.uploaded' => 'filed a document',
                default => str_replace('_', ' ', str_replace('matter.', '', (string) $event->event)),
            };
        };
    @endphp

    @if (! $orgHasMatters)
        <div class="ga-dash-welcome">
            <h2>Welcome to Vision Law</h2>
            <p>Vision Law is your firm&rsquo;s home for matters, documents, and deadlines &mdash; every file, change, and decision kept in one secure, audited place. Create your first matter to get started.</p>
            <div class="ga-dash-welcome-btns">
                @if ($canCreateMatter)
                    <a class="ga-btn ga-btn-primary" href="{{ route('matters.create') }}">Create a matter</a>
                @endif
                @if ($isOrgAdmin)
                    <a class="ga-btn" href="{{ route('admin.invitations.index') }}">Invite your team</a>
                @endif
            </div>
        </div>
    @else
        <div class="ga-dash-grid" data-ga-dash-grid>
            <div class="ga-dash-content">
                <section data-dash-panel="today">
                    @if ($deadlines !== null)
                        <div class="ga-today">
                            <div class="ga-tile ga-tile-violet"><div class="ga-tile-n">{{ $deadlines['dueToday'] }}</div><div class="ga-tile-l">Due today</div></div>
                            <div class="ga-tile ga-tile-violet"><div class="ga-tile-n">{{ $deadlines['dueWithin7'] }}</div><div class="ga-tile-l">Due within 7 days</div></div>
                            <div class="ga-tile ga-tile-green"><div class="ga-tile-n">{{ $activeMatterCount }}</div><div class="ga-tile-l">Active matters</div></div>
                        </div>

                        <section class="ga-dash-card">
                            <div class="ga-dash-cardhead">
                                <h2>Deadlines &amp; agenda</h2>
                                <a href="{{ route('calendar.index') }}">Open calendar</a>
                            </div>

                            <div class="ga-stats">
                                <div class="ga-stat"><div class="ga-stat-v">{{ $deadlines['overdue'] }}</div><div class="ga-stat-l">Overdue</div></div>
                                <div class="ga-stat"><div class="ga-stat-v">{{ $deadlines['dueWithin7'] }}</div><div class="ga-stat-l">Due within 7 days</div></div>
                                <div class="ga-stat"><div class="ga-stat-v">{{ $deadlines['unackedMandatory'] }}</div><div class="ga-stat-l">Unacknowledged mandatory reminders</div></div>
                            </div>

                            <div class="ga-dllist">
                                @forelse ($deadlines['upcoming'] as $deadlineItem)
                                    @php
                                        [$itemStatusLabel, $itemStatusTone] = $itemStatusMap[$deadlineItem->status] ?? ['Unknown', 'ga-tag-mut'];
                                        $itemDate = $deadlineItem->getAttribute('home_date');
                                        $itemOverdue = (bool) $deadlineItem->getAttribute('home_overdue');
                                    @endphp
                                    <div class="ga-dlrow">
                                        <a class="ga-dltitle" href="{{ route('calendar.items.show', $deadlineItem) }}">{{ $deadlineItem->title }}</a>
                                        <span class="ga-tag {{ $itemStatusTone }}">{{ $itemStatusLabel }}</span>
                                        <span class="ga-dlmatter">{{ $deadlineItem->matter?->title }}</span>
                                        <span class="ga-dlwhen @if ($itemOverdue) ga-dl-over @endif">{{ $itemDate ? fmtDate($itemDate) : 'Undated' }}{{ $itemOverdue ? ' · Overdue' : '' }}</span>
                                    </div>
                                @empty
                                    <p class="ga-dash-empty">No upcoming confirmed items on your matters.</p>
                                @endforelse
                            </div>
                        </section>
                    @endif
                </section>

                <section data-dash-panel="matters">
                    <div class="ga-dash-sechead">
                        <h2>My matters</h2>
                        <span class="ga-dash-count">{{ $matterCount }} {{ \Illuminate\Support\Str::plural('matter', $matterCount) }} you can access</span>
                        <a class="ga-dash-viewall" href="{{ route('matters.index') }}">View all matters</a>
                    </div>

                    <div class="ga-mtools">
                        <input type="search" class="ga-msearch" data-dash-search placeholder="Filter matters…" aria-label="Filter matters">
                        <select class="ga-mselect" data-dash-stage aria-label="Filter by stage">
                            <option value="">All stages</option>
                            @foreach ($stageMap as $stageValue => $stageInfo)
                                <option value="{{ $stageValue }}">{{ $stageInfo[0] }}</option>
                            @endforeach
                        </select>
                        @if ($canCreateMatter)
                            <a class="ga-btn ga-btn-primary" href="{{ route('matters.create') }}">＋ New matter</a>
                        @endif
                    </div>

                    @if ($matters->isEmpty())
                        <p class="ga-dash-empty">No matters are shared with you yet. When a teammate grants you access to a matter, it will appear here.</p>
                    @else
                        <div class="ga-mcards">
                            @foreach ($matters as $matter)
                                @php
                                    [$stageLabel, $stageTone] = $stageMap[$matter->lifecycle_state] ?? ['Unknown', 'ga-tag-mut'];
                                @endphp
                                <a class="ga-mcard" href="{{ route('matters.show', $matter) }}" data-dash-card data-stage="{{ $matter->lifecycle_state }}" data-search="{{ strtolower($matter->title.' '.$matter->matter_number.' '.($matter->client_name ?? '')) }}">
                                    <span class="ga-mnum">{{ $matter->matter_number }}@if ($matter->restricted) <span class="ga-tag ga-tag-warn">Restricted</span>@endif</span>
                                    <span class="ga-mtitle">{{ $matter->title }}</span>
                                    <span class="ga-msub">{{ $matter->client_name ?: \Illuminate\Support\Str::headline((string) $matter->matter_type) }}</span>
                                    <span class="ga-mfoot"><span class="ga-tag {{ $stageTone }}">{{ $stageLabel }}</span><span class="ga-mupd">Updated {{ fmtRelative($matter->updated_at) }}</span></span>
                                </a>
                            @endforeach
                            @if ($canCreateMatter)
                                <a class="ga-mcard ga-mcard-new" href="{{ route('matters.create') }}"><span class="ga-plus" aria-hidden="true">＋</span>New matter</a>
                            @endif
                        </div>
                        <p class="ga-dash-filterempty" data-dash-filterempty hidden>No matters match your filter.</p>
                    @endif

                    <section class="ga-dash-card">
                        <div class="ga-dash-cardhead">
                            <h2>Recent activity</h2>
                        </div>
                        @forelse ($activity as $event)
                            <div class="ga-actrow">
                                <p><strong>{{ $event->actor?->name ?? 'System' }}</strong> {{ $describe($event) }} &mdash; <span class="ga-dlmatter">{{ $event->matter?->title }}</span></p>
                                <span class="ga-actwhen" title="{{ fmtDT($event->created_at) }}">{{ fmtRelative($event->created_at) }}</span>
                            </div>
                        @empty
                            <p class="ga-dash-empty">No recent activity on your matters yet.</p>
                        @endforelse
                    </section>

                    <section class="ga-dash-card">
                        <div class="ga-dash-cardhead">
                            <h2>Security status</h2>
                        </div>
                        <p class="ga-secline">Two-factor authentication <strong>{{ $twoFactorConfirmed ? 'On' : 'Not set up' }}</strong></p>
                        <p class="ga-secline">Passkeys <strong>{{ $passkeyCount }} {{ \Illuminate\Support\Str::plural('passkey', $passkeyCount) }} registered</strong></p>
                        <a class="ga-btn" href="{{ route('two-factor.settings') }}">Security settings</a>
                    </section>

                    <p class="ga-dash-footnote">Cards are the browse path; the front desk is the ask path. Both land in the same matter workspace.</p>
                </section>
            </div>

            <div class="ga-dash-drag" data-dash-drag title="Drag to resize the chat panel" aria-hidden="true"></div>

            <section class="ga-dash-chat" data-dash-panel="chat" aria-label="Front desk — agent chat">
                @include('home._frontdesk', ['routeMatter' => $matters->first()])
            </section>
        </div>
    @endif
</x-layouts.dashboard>
