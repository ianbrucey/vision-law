{{--
    public/partials/footer.blade.php — Vision Systems footer (spec 013,
    03-contract.md §Landing page section contract §7).

    Brand + the fixtures' footer columns and closing line
    (landing.footer). Vision Law is named as a pack; no customer logos,
    no metrics. Column links point at the in-page sections they name;
    the rest follow the existing placeholder convention.
--}}
@php
    $footer = $landing['footer'];
    $footerHrefs = [
        'Platform' => [
            'Ingestion & OCR' => '#platform',
            'Page-anchored store' => '#platform',
            'Grounded agent' => '#platform',
            'Audit & retention' => '#trust',
        ],
        'Packs' => [
            'Vision Law' => '#packs',
            'Permitting & Code' => '#packs',
            'Your field' => '#packs',
        ],
    ];
@endphp
<footer class="vsl-footer">
    <div class="vsl-footer-grid">
        <div>
            <span class="vsl-brand">{{ $brand['name'] }}</span>
        </div>
        @foreach ($footer['columns'] as $column)
            <div>
                <h3 class="vsl-footer-h">{{ $column['title'] }}</h3>
                @foreach ($column['links'] as $link)
                    <a class="vsl-footer-a" href="{{ $footerHrefs[$column['title']][$link] ?? '#' }}">{{ $link }}</a>
                @endforeach
            </div>
        @endforeach
    </div>
    <p class="vsl-footer-line">{{ $footer['line'] }}</p>
</footer>
