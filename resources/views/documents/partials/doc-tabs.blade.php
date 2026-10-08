{{--    documents/partials/doc-tabs.blade.php — spec 007 T-10.

    Per-document section tabs (Preview | Versions | Share | Activity),
    rendered under the page header via <x-ui.sub-nav>. The Share tab only
    appears for actors who can manage the document (DocumentAccess :manage)
    — everyone else would 403 on it.

    Expects: $matter, $document, $activeTab
             ('preview'|'versions'|'share'|'activity'), $canShare (bool).
--}}
<x-ui.sub-nav :items="array_values(array_filter([
    ['label' => 'Preview', 'href' => route('documents.preview', [$matter, $document]), 'active' => $activeTab === 'preview'],
    ['label' => 'Versions', 'href' => route('documents.versions.index', [$matter, $document]), 'active' => $activeTab === 'versions'],
    $canShare ? ['label' => 'Share', 'href' => route('documents.share', [$matter, $document]), 'active' => $activeTab === 'share'] : null,
    ['label' => 'Activity', 'href' => route('documents.activity', [$matter, $document]), 'active' => $activeTab === 'activity'],
]))" />
