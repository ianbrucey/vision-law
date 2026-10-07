{{--
    <x-ui.toast> — session-flash toasts (spec 002 03-contract.md, mockup §08).

    No props in normal use — reads session('toast') as
    ['tone' => 'ok|bad|info', 'message' => '...'].

    Law (docs/UI_Standards.md): every mutation flashes a toast that names the
    CONSEQUENCE, not the action — and never repeats confidential content.
    The message is always escaped.

    Fail-closed: a missing or malformed flash renders nothing; an unknown
    tone renders as info.
--}}
@php
    $toast = session('toast');
    $toastTone = (is_array($toast) && in_array($toast['tone'] ?? null, ['ok', 'bad', 'info'], true))
        ? $toast['tone']
        : 'info';
    $toastToneClasses = [
        'ok' => 'bg-vl-ok-soft border-vl-ok',
        'bad' => 'bg-vl-bad-soft border-vl-bad',
        'info' => 'bg-vl-info-soft border-vl-info',
    ];
@endphp

@if (is_array($toast) && isset($toast['message']) && $toast['message'] !== '')
    <div
        role="status"
        {{ $attributes->merge(['class' => 'rounded-vl-control border px-4 py-3 text-[14.5px] text-vl-ink shadow-[0_1px_2px_rgba(31,42,55,0.06),0_4px_16px_rgba(31,42,55,0.06)] mb-2.5 max-w-[520px] ' . $toastToneClasses[$toastTone]]) }}
    ><strong>{{ $toast['message'] }}</strong></div>
@endif
