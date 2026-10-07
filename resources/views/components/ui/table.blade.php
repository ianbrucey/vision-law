{{--
    <x-ui.table> — dense data tables (spec 002 03-contract.md, mockup §07).

    Named slots: head (the <th> cells), body (the <tr> rows). Whole-row links:
    callers wrap row content in <a> — the component never invents navigation.

    Visuals (tinted header row, hairline dividers) live in the .vl-table rules
    in resources/css/app.css — views never hardcode them. The wrapper scrolls
    horizontally when the table is wider than its card (phones ≤820px), so the
    page itself never scrolls sideways.
--}}
<div {{ $attributes->merge(['class' => 'overflow-x-auto rounded-vl-card border border-vl-line bg-vl-card']) }}>
    <table class="vl-table">
        @isset($head)
            <thead>
                <tr>{{ $head }}</tr>
            </thead>
        @endisset
        <tbody>{{ $body ?? '' }}</tbody>
    </table>
</div>
