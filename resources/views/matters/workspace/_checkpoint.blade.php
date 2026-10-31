{{--
    Checkpoint card — the human-decision surface. Amber by law (STYLE_GUIDE
    §2): a person owes a decision here. The choice is recorded in-page under
    the user's name (Slice 1: page state only; the event log is step 3).
--}}
<div class="ga-checkpoint">
    <div class="ga-cp-h">⚠ Checkpoint — your call required</div>
    <p>The third claim sits in the <b>gray zone (0.52)</b> — the assessments split, and it rises or falls on documents currently in her hands. File it now, or hold it pending discovery?</p>
    <div class="ga-cp-btns">
        <button type="button" class="ga-primary" :disabled="cpChoice !== null" x-on:click="decide('Hold the gray-zone claim pending discovery', 'held pending discovery')">Hold it pending discovery</button>
        <button type="button" :disabled="cpChoice !== null" x-on:click="decide('File all three claims now', 'asserted')">File all three now</button>
    </div>
    <div class="ga-cp-done" x-show="cpChoice !== null" x-cloak>✓ Recorded under your name: <span x-text="cpChoice"></span> — decision logged to the event log.</div>
</div>
