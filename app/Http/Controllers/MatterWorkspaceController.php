<?php

namespace App\Http\Controllers;

use App\Models\Matter;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Spec 012 Slice 1 — the matter workspace (Glass/Aurora) UX surface.
 *
 * Front-end only, per the approved build sequence (step 1 of 5): this
 * renders the agent-workflow workspace — position rail, Battlefield /
 * Evidence / Narrative / Outline & Draft panels, and the agent chat with
 * its checkpoint cards — so the UX can be confirmed before anything is
 * built behind it. The case content is SAMPLE data held in this view
 * layer (a fictionalized wage-claim pattern ported from the Spec 012
 * mockups); it is not seeded Brain data, and no Brain schema exists yet
 * (step 3). Chat persistence arrives with step 2.
 *
 * Authorization: the route carries RequireMatterAccess (:view), the same
 * gate as the matter detail screen; the authorized matter rides on the
 * request attributes and is never re-resolved here.
 */
class MatterWorkspaceController extends Controller
{
    public function __invoke(Request $request): View
    {
        $matter = $request->attributes->get('matter');

        if (! $matter instanceof Matter) {
            abort(404);
        }

        $user = $request->user();
        $initials = collect(explode(' ', (string) ($user->name ?? '')))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => strtoupper(substr($part, 0, 1)))
            ->implode('');

        return view('matters.workspace', [
            'matter' => $matter,
            'initials' => $initials === '' ? '?' : $initials,
            'demo' => self::demoData(),
        ]);
    }

    /**
     * Sample case content for the UX preview, ported from the Spec 012
     * mockups (design/mockups/agent-workflow.html, mobile-workspace.html).
     * Static placeholder data for the view layer only — never treat it as
     * a real case record.
     *
     * @return array<string, mixed>
     */
    private static function demoData(): array
    {
        return [
            'posture' => 'Offensive',
            'mode' => 'Pleading',
            'stage_index' => 9, // Assessment (0-based) of 14
            'stages' => [
                'Intake', 'Case summary', 'Intention & strategy', 'Claims & defenses',
                'Controlling law', 'Elements & gates', 'Research', 'Fact cartography',
                'Mapping', 'Assessment', 'Computation', 'Simulation', 'Outline', 'Draft',
            ],
            'protocol' => [
                'name' => 'Assessment',
                'in_hand' => 'brain queries · CourtListener · assessment writer',
                'withheld' => 'drafting · outline (locked until computation)',
            ],
            'counts' => [
                ['value' => '14', 'label' => 'documents'],
                ['value' => '34', 'label' => 'facts'],
                ['value' => '3', 'label' => 'claims (1 held)'],
                ['value' => '11', 'label' => 'elements'],
                ['value' => '7 / 2 / 2', 'label' => 'elements ✓ / contested / missing'],
                ['value' => '3', 'label' => 'open gaps'],
            ],
            'strip_counts' => [
                ['value' => '3', 'label' => 'claims'],
                ['value' => '34', 'label' => 'facts'],
                ['value' => '14', 'label' => 'docs'],
            ],
            'gaps' => [
                '<b>#1 leverage:</b> Can she document the alleged breakage? Flips the penalty claim. <i>Discovery target.</i>',
                '<b>#2:</b> Exact final-pay date — drives penalty day count.',
                '<b>#3:</b> Full text thread export (screenshots partial).',
            ],
            'claims' => [
                [
                    'title' => 'Claim 1 — Unpaid wages',
                    'band' => 'strong',
                    'band_label' => 'STRONG',
                    'score_label' => 'characterization',
                    'score' => '0.78',
                    'cog' => false,
                    'dimmed' => false,
                    'elements' => [
                        ['state' => 'ok', 'state_label' => 'ESTABLISHED', 'title' => 'Employment relationship', 'note' => 'Job posting · text thread · payment history'],
                        ['state' => 'warn', 'state_label' => 'DISPUTED', 'title' => 'Wages earned & unpaid (~$200)', 'note' => 'She asserts breakage offsets — no documentation in record'],
                        ['state' => 'warn', 'state_label' => 'OPEN', 'title' => 'No lawful offset', 'note' => 'Turns on Gap #1'],
                    ],
                ],
                [
                    'title' => 'Claim 2 — Waiting-time penalties',
                    'band' => 'viable',
                    'band_label' => 'VIABLE',
                    'score_label' => 'characterization',
                    'score' => '0.66',
                    'cog' => true,
                    'dimmed' => false,
                    'elements' => [
                        ['state' => 'ok', 'state_label' => 'SATISFIED', 'title' => 'Unpaid past the 72-hour mark', 'note' => 'Final pay was short when it came'],
                        ['state' => 'warn', 'state_label' => '★ CENTER OF GRAVITY', 'title' => 'Willfulness', 'note' => 'Her good-faith-dispute defense attacks HERE. Assessment split — see Agent panel →'],
                    ],
                ],
                [
                    'title' => 'Claim 3 — (held)',
                    'band' => 'gray',
                    'band_label' => 'HELD BY YOU',
                    'score_label' => 'gray zone',
                    'score' => '0.52',
                    'cog' => false,
                    'dimmed' => true,
                    'elements' => [
                        ['state' => 'warn', 'state_label' => 'GRAY ZONE', 'title' => 'Routed to you at the Assessment checkpoint', 'note' => 'Decision recorded in the Agent panel.'],
                    ],
                ],
            ],
            'evidence' => [
                [
                    'icon' => 'TXT', 'title' => 'Text thread — Romine (screenshots, 23 images)',
                    'sub' => 'Message thread · Oct 2026 · demand + response captured',
                    'tags' => [['label' => 'evidence'], ['label' => 'willfulness', 'hot' => true], ['label' => 'gap #3: export pending', 'hot' => true], ['label' => 'provenance: document']],
                    'status' => 'extracted', 'status_tone' => 'ok',
                ],
                [
                    'icon' => 'WEB', 'title' => 'Caregiver job posting (Craigslist, archived)',
                    'sub' => 'Posting · proves employment relationship + rate',
                    'tags' => [['label' => 'evidence'], ['label' => 'element: employment'], ['label' => 'provenance: public record']],
                    'status' => 'extracted', 'status_tone' => 'ok',
                ],
                [
                    'icon' => 'PAY', 'title' => 'Payment records — final pay',
                    'sub' => 'Financial · shows $300 deducted at final payment',
                    'tags' => [['label' => 'evidence'], ['label' => 'gap #2: exact date', 'hot' => true], ['label' => 'element: unpaid wages']],
                    'status' => 'partial', 'status_tone' => 'warn',
                ],
                [
                    'icon' => 'PDF', 'title' => 'Demand letter draft (yours, unsent)',
                    'sub' => 'Correspondence · prepared by agent, awaiting your approval',
                    'tags' => [['label' => 'draft'], ['label' => 'nothing leaves without you']],
                    'status' => 'stored', 'status_tone' => 'mut',
                ],
            ],
            'narrative' => [
                'client' => '“I worked for her as a caregiver. I quit because she kept cursing at me after I gave notice. On my final pay she took about $300 off, saying I broke things — she never said that while I worked there. After I left I texted her for the rest — about $200 — and told her I’d ask the court if she didn’t pay. She laugh-reacted…”',
                'brief' => 'Wage dispute, former caregiver vs. employer, California. Two live claims (unpaid wages; waiting-time penalties), one held. Battlefield turns on a single factual question: whether the employer can document the alleged breakage she used to justify the deduction.',
            ],
            'outline' => [
                ['key' => 'Outline — node 1', 'html' => 'Introduction &amp; parties <small>(facts #1–#6, established)</small>'],
                ['key' => 'Outline — node 2', 'html' => 'The employment &amp; the final pay <small>(elements: employment ✓, unpaid wages — lead with strongest facts)</small>'],
                ['key' => 'Outline — node 3', 'html' => 'Willfulness — pre-answer the good-faith attack <small>(center of gravity — largest section, per simulation)</small>'],
                ['key' => 'Outline — node 4', 'html' => 'Relief &amp; penalty arithmetic <small>(computed remedy model)</small>'],
            ],
            'passes' => [
                ['done' => true, 'label' => 'Citation verification — every authority re-read at source'],
                ['done' => true, 'label' => 'Fact check — every assertion traced to a fact row + provenance'],
                ['done' => false, 'label' => 'Adversarial review — runs at Draft protocol'],
                ['done' => false, 'label' => 'Prose review — runs at Draft protocol'],
            ],
            'messages' => [
                ['kind' => 'sys', 'who' => null, 'html' => 'Intake protocol completed · 14 documents · narrative recorded'],
                ['kind' => 'agent', 'who' => 'Agent · Strategy protocol', 'html' => 'Posture confirmed: <b>offensive</b>. You want the unpaid balance and the statutory penalty, and you’re open to filing if she won’t pay. Three candidate claims found — two look strong, one is genuinely borderline.'],
                ['kind' => 'user', 'who' => 'You', 'html' => 'Run the assessment. Tell me where we actually stand.'],
                ['kind' => 'sys', 'who' => null, 'html' => 'Protocol change: STRATEGY → ASSESSMENT · tool set updated'],
                ['kind' => 'agent', 'who' => 'Agent · Assessment protocol', 'html' => 'Done. Claim 1 (unpaid wages) computes <b>strong — 0.78</b>. Claim 2 (penalties) is <b>viable — 0.66</b>, and it hinges on one element: <b>willfulness</b>. Her breakage story is a good-faith-dispute attack aimed exactly there. The single most valuable missing fact in this case: <b>whether she can document the breakage</b>. If she can’t, Claim 2 strengthens; receipts or photos would weaken it. That’s discovery target #1.'],
            ],
            'quick_replies' => ['Hold it', 'Explain the risk first', 'Upload the full text export'],
        ];
    }
}
