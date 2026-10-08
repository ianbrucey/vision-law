{{--
    matters/index.blade.php — spec 006 T-05 (05-ui.md §Matter list, mockup §01).

    Trigram search box + AND-combined filter chips (state) and selects
    (type, assignee), x-ui.table, x-ui.empty-state, pagination. Results are
    permission-scoped server-side before pagination — ungranted matters never
    appear, not even as rows.

    Expects: $matters (LengthAwarePaginator<Matter>), $q, $state, $type,
    $assignee ('me'|null), $canCreate, $orgName, $stateOptions, $typeOptions.
--}}
<x-layouts.app title="Matters · Vision Law">
    <x-ui.page-header
        title="Matters"
        :subtitle="$matters->total() . ' ' . \Illuminate\Support\Str::plural('matter', $matters->total()) . ($orgName ? ' · ' . $orgName : '')"
    >
        @if ($canCreate)
            <x-slot name="actions">
                <x-ui.button variant="primary" href="{{ route('matters.create') }}">New matter</x-ui.button>
            </x-slot>
        @endif
    </x-ui.page-header>

    @php
        // Base query preserved across chip navigation (everything except the
        // dimension the chips control).
        $chipBase = array_filter([
            'q' => $q !== '' ? $q : null,
            'type' => $type,
            'assignee' => $assignee,
        ]);
        $stateChips = [['value' => null, 'label' => 'All states']];
        foreach ($stateOptions as $chipValue => $chipLabel) {
            $stateChips[] = ['value' => $chipValue, 'label' => $chipLabel];
        }
    @endphp

    <form method="GET" action="{{ route('matters.index') }}" class="mb-6" role="search">
        <div class="flex flex-col gap-3 min-[821px]:flex-row min-[821px]:items-end">
            <x-ui.field
                name="q"
                label="Search matters"
                type="search"
                :value="$q"
                placeholder="Search title, matter number, client, party…"
                class="grow max-w-none! mb-0!"
            />
            <x-ui.select
                name="type"
                label="Matter type"
                :options="['' => 'All types'] + $typeOptions"
                :value="$type ?? ''"
                class="mb-0! min-[821px]:w-52"
            />
            <x-ui.select
                name="assignee"
                label="Assignee"
                :options="['' => 'Anyone', 'me' => 'Assigned to me']"
                :value="$assignee ?? ''"
                class="mb-0! min-[821px]:w-52"
            />
            <x-ui.button variant="secondary" type="submit">Search</x-ui.button>
        </div>
        <div class="mt-3 flex flex-wrap gap-2" role="group" aria-label="Filter by lifecycle state">
            @foreach ($stateChips as $chip)
                @php
                    $chipIsActive = $state === $chip['value'];
                    $chipHref = $chip['value'] === null
                        ? route('matters.index', $chipBase)
                        : route('matters.index', $chipBase + ['state' => $chip['value']]);
                @endphp
                <a href="{{ $chipHref }}" class="no-underline" @if ($chipIsActive) aria-current="true" @endif>
                    @if ($chipIsActive)
                        <x-ui.chip class="outline outline-2 outline-offset-2 outline-vl-ink">{{ $chip['label'] }}</x-ui.chip>
                    @else
                        <x-ui.chip>{{ $chip['label'] }}</x-ui.chip>
                    @endif
                </a>
            @endforeach
        </div>
    </form>

    @if ($matters->total() === 0)
        @if ($q !== '' || $state !== null || $type !== null || $assignee !== null)
            <x-ui.empty
                title="No matters match these filters"
                actionHref="{{ route('matters.index') }}"
                actionLabel="Clear filters"
            >
                Try a different search term or widen the filters.
            </x-ui.empty>
        @else
            <x-ui.empty
                title="No matters yet"
                :actionHref="$canCreate ? route('matters.create') : null"
                :actionLabel="$canCreate ? 'New matter' : null"
            >
                Create your first matter to begin tracking work.
            </x-ui.empty>
        @endif
    @else
        <x-ui.card padded="false" class="mb-2">
            <x-ui.table>
                <x-slot name="head">
                    <th scope="col">Matter no.</th>
                    <th scope="col">Title</th>
                    <th scope="col">Client</th>
                    <th scope="col">State</th>
                    <th scope="col">Updated</th>
                </x-slot>
                <x-slot name="body">
                    @foreach ($matters as $matter)
                        <tr>
                            <td class="whitespace-nowrap">
                                <a href="{{ route('matters.show', $matter) }}" class="font-semibold text-vl-ink underline decoration-vl-line underline-offset-2 hover:decoration-vl-ink">{{ $matter->matter_number }}</a>
                            </td>
                            <td><strong>{{ $matter->title }}</strong></td>
                            <td>{{ $matter->client_name }}</td>
                            <td><x-ui.status type="matter" :value="$matter->lifecycle_state" /></td>
                            <td class="whitespace-nowrap text-vl-mut">
                                {{ fmtDate($matter->updated_at) }}
                                <span class="block text-[12.5px]">{{ fmtRelative($matter->updated_at) }}</span>
                            </td>
                        </tr>
                    @endforeach
                </x-slot>
            </x-ui.table>
        </x-ui.card>
        <x-ui.pagination :paginator="$matters" />
    @endif
</x-layouts.app>
