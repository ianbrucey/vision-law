{{--
    <x-vs.composer> — the message composer: a full-width field plus an
    action strip in which attach and voice are first-class citizens and
    send is the one primary action (spec 013, 03-contract.md §Component
    contract; design/component-library.html §8; the shipped composer in
    the spec 012 workspace is the structural model).

    Props: name (field name, default message), placeholder, value,
           state (empty|typing|sending|disabled — when omitted it is
           derived: disabled wins, then sending, then typing when a
           value is present, else empty), action (form action URL; the
           form is inert without one), attach (bool, default true),
           voice (bool, default true), label (accessible field label).

    The state is presentation only: in the empty state the send button
    is disabled (nothing to send); in sending it pulses on the shared
    motion tokens; in disabled the whole strip is inert. Behaviour
    (growing the field, submit handling) belongs to the consuming view.
--}}
@props([
    'name' => 'message',
    'placeholder' => 'Message the agent…',
    'value' => '',
    'state' => null,
    'action' => null,
    'attach' => true,
    'voice' => true,
    'label' => 'Message the agent',
])

@php
    $state = $state ?? ($value !== '' && $value !== null ? 'typing' : 'empty');
    $inert = in_array($state, ['disabled', 'sending'], true);
    $classes = 'vs-composer vs-composer--' . $state;
@endphp

<form @if ($action) action="{{ $action }}" method="POST" @endif {{ $attributes->merge(['class' => $classes]) }}>
    @if ($action)
        @csrf
    @endif
    @if ($attach)
        <label class="vs-iconbtn" title="Attach a document">
            <span aria-hidden="true">＋</span>
            <input type="file" name="{{ $name }}_attachment" class="vs-fileinput" aria-label="Attach a document" @disabled($inert)>
        </label>
    @endif
    <textarea name="{{ $name }}" rows="1" class="vs-composer__field" placeholder="{{ $placeholder }}" aria-label="{{ $label }}" @disabled($state === 'disabled') @readonly($state === 'sending')>{{ $value }}</textarea>
    @if ($voice)
        <button type="button" class="vs-iconbtn vs-iconbtn--acc" title="Speak instead of typing" aria-label="Speak instead of typing" @disabled($inert)><span aria-hidden="true">🎙</span></button>
    @endif
    <button type="submit" class="vs-send" aria-label="Send message" @disabled($inert || $state === 'empty')><span aria-hidden="true">↑</span></button>
</form>
