{{--
    public/landing.blade.php — the marketing landing page (spec 003 06-plan.md T-02).

    Sections in mockup order (03-site-mockup.html, approved 2026-10-07):
    nav, cinematic hero, trust strip, platform grid (#platform), auditability
    (#audit) with the synthetic audit-trail card, synthetic testimonial
    (#customers), final CTA, footer.

    The nav/hero/CTA/footer sections use the 003-D01 scoped exception: the
    @theme tokens vl-dark-0, vl-dark-1, vl-neon, vl-neon-deep, vl-dark-text,
    vl-dark-mut — token utilities only, no raw hex, no inline styles, and no
    Tailwind dark-mode variants anywhere. The light sections use the decided
    navy/brass/paper tokens.

    Anonymous only: no user, org, or session data of any kind (C-04).
--}}
<x-layouts.public title="Vision Law — Enterprise legal platform">
    @include('public.partials.nav')
    @include('public.partials.hero')

    {{-- Trust strip --}}
    <section class="bg-vl-dark-0 border-t border-[color-mix(in_srgb,var(--vl-neon)_16%,transparent)]" aria-label="Security highlights">
        <div class="mx-auto max-w-[1120px] px-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-2 py-[30px] max-[640px]:py-[22px] max-[640px]:px-5">
            <div class="flex gap-3 items-start px-3 py-1.5">
                <div class="flex-none w-[34px] h-[34px] rounded-[9px] flex items-center justify-center bg-[color-mix(in_srgb,var(--vl-neon)_10%,transparent)] border border-[color-mix(in_srgb,var(--vl-neon)_30%,transparent)] text-vl-neon text-[17px]" aria-hidden="true">◈</div>
                <div>
                    <p class="text-white text-[14px] font-semibold">Encryption everywhere</p>
                    <p class="text-vl-dark-mut text-[13px]">In transit and at rest, on every record</p>
                </div>
            </div>
            <div class="flex gap-3 items-start px-3 py-1.5">
                <div class="flex-none w-[34px] h-[34px] rounded-[9px] flex items-center justify-center bg-[color-mix(in_srgb,var(--vl-neon)_10%,transparent)] border border-[color-mix(in_srgb,var(--vl-neon)_30%,transparent)] text-vl-neon text-[17px]" aria-hidden="true">≣</div>
                <div>
                    <p class="text-white text-[14px] font-semibold">Append-only audit trail</p>
                    <p class="text-vl-dark-mut text-[13px]">Rows can never be updated or deleted</p>
                </div>
            </div>
            <div class="flex gap-3 items-start px-3 py-1.5">
                <div class="flex-none w-[34px] h-[34px] rounded-[9px] flex items-center justify-center bg-[color-mix(in_srgb,var(--vl-neon)_10%,transparent)] border border-[color-mix(in_srgb,var(--vl-neon)_30%,transparent)] text-vl-neon text-[17px]" aria-hidden="true">⬢</div>
                <div>
                    <p class="text-white text-[14px] font-semibold">SSO &amp; SCIM ready</p>
                    <p class="text-vl-dark-mut text-[13px]">Plugs into your identity provider</p>
                </div>
            </div>
            <div class="flex gap-3 items-start px-3 py-1.5">
                <div class="flex-none w-[34px] h-[34px] rounded-[9px] flex items-center justify-center bg-[color-mix(in_srgb,var(--vl-neon)_10%,transparent)] border border-[color-mix(in_srgb,var(--vl-neon)_30%,transparent)] text-vl-neon text-[17px]" aria-hidden="true">⛨</div>
                <div>
                    <p class="text-white text-[14px] font-semibold">Role-based access</p>
                    <p class="text-vl-dark-mut text-[13px]">Matter-level grants with expiry</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Platform --}}
    <section id="platform" class="py-[88px] max-[640px]:py-[60px]">
        <div class="mx-auto max-w-[1120px] px-6">
            <p class="text-[12px] font-bold tracking-[0.16em] uppercase text-vl-brass">Platform</p>
            <h2 class="font-serif font-normal text-[27px] md:text-[34px] leading-[1.18] text-vl-ink mt-[14px] mb-3">Built for the way legal teams actually work</h2>
            <p class="text-vl-mut text-[17px] max-w-[680px] mb-11">One structured record per matter — clients, documents, deadlines, drafts, and communications — instead of five systems that don’t talk to each other.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                <div class="bg-vl-card border border-vl-line rounded-[14px] p-7">
                    <div class="w-11 h-11 rounded-[11px] bg-vl-ink text-white flex items-center justify-center text-[20px] mb-[18px]" aria-hidden="true">▤</div>
                    <h3 class="text-[17px] font-semibold text-vl-ink mb-2">Matter management</h3>
                    <p class="text-vl-mut text-[14.5px]">Every client, case, and contract in one structured record. No more spreadsheet archaeology.</p>
                </div>
                <div class="bg-vl-card border border-vl-line rounded-[14px] p-7">
                    <div class="w-11 h-11 rounded-[11px] bg-vl-ink text-white flex items-center justify-center text-[20px] mb-[18px]" aria-hidden="true">◫</div>
                    <h3 class="text-[17px] font-semibold text-vl-ink mb-2">Document intelligence</h3>
                    <p class="text-vl-mut text-[14.5px]">Uploads become searchable, cited, and versioned — OCR, chunking, and semantic search built in.</p>
                </div>
                <div class="bg-vl-card border border-vl-line rounded-[14px] p-7">
                    <div class="w-11 h-11 rounded-[11px] bg-vl-ink text-white flex items-center justify-center text-[20px] mb-[18px]" aria-hidden="true">◷</div>
                    <h3 class="text-[17px] font-semibold text-vl-ink mb-2">Deadline engine</h3>
                    <p class="text-vl-mut text-[14.5px]">Court rules and matter templates generate deadlines automatically, with escalation before things slip.</p>
                </div>
                <div class="bg-vl-card border border-vl-line rounded-[14px] p-7">
                    <div class="w-11 h-11 rounded-[11px] bg-vl-ink text-white flex items-center justify-center text-[20px] mb-[18px]" aria-hidden="true">✒</div>
                    <h3 class="text-[17px] font-semibold text-vl-ink mb-2">Drafting copilot</h3>
                    <p class="text-vl-mut text-[14.5px]">Agents draft from your templates and your documents. Every paragraph traceable to its source.</p>
                </div>
                <div class="bg-vl-card border border-vl-line rounded-[14px] p-7">
                    <div class="w-11 h-11 rounded-[11px] bg-vl-ink text-white flex items-center justify-center text-[20px] mb-[18px]" aria-hidden="true">⇄</div>
                    <h3 class="text-[17px] font-semibold text-vl-ink mb-2">Outside-counsel sharing</h3>
                    <p class="text-vl-mut text-[14.5px]">Share matters with co-counsel under explicit, expiring grants. Nothing implicit, ever.</p>
                </div>
                <div class="bg-vl-card border border-vl-line rounded-[14px] p-7">
                    <div class="w-11 h-11 rounded-[11px] bg-vl-ink text-white flex items-center justify-center text-[20px] mb-[18px]" aria-hidden="true">⛉</div>
                    <h3 class="text-[17px] font-semibold text-vl-ink mb-2">Retention &amp; legal holds</h3>
                    <p class="text-vl-mut text-[14.5px]">Policy-driven retention with litigation holds that actually hold — and disposition you can defend.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Auditability --}}
    <section id="audit" class="py-[88px] max-[640px]:py-[60px] bg-vl-card border-y border-vl-line">
        <div class="mx-auto max-w-[1120px] px-6">
            <p class="text-[12px] font-bold tracking-[0.16em] uppercase text-vl-brass">Auditability</p>
            <h2 class="font-serif font-normal text-[27px] md:text-[34px] leading-[1.18] text-vl-ink mt-[14px] mb-3">Prove it, don’t promise it.</h2>
            <p class="text-vl-mut text-[17px] max-w-[680px] mb-11">General counsel and CIOs don’t buy features — they buy defensibility. Vision Law is engineered so the answer to “who touched this?” is always one query away.</p>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-start">
                <ul class="list-none m-0 p-0">
                    <li class="py-[18px] border-b border-vl-line">
                        <p class="text-[15.5px] font-semibold text-vl-ink mb-1">Immutable audit log</p>
                        <p class="text-vl-mut text-[14.5px]">Every view, edit, share, and export is recorded in an append-only store — enforced at the database level, not just the application.</p>
                    </li>
                    <li class="py-[18px] border-b border-vl-line">
                        <p class="text-[15.5px] font-semibold text-vl-ink mb-1">Matter-level permissions</p>
                        <p class="text-vl-mut text-[14.5px]">Outside counsel sees only what they’re explicitly granted, for only as long as they’re granted it. Grants expire; access doesn’t linger.</p>
                    </li>
                    <li class="py-[18px]">
                        <p class="text-[15.5px] font-semibold text-vl-ink mb-1">Your data, exportable</p>
                        <p class="text-vl-mut text-[14.5px]">Full matter export in open formats at any time. Your records are yours — no lock-in, no hostage data.</p>
                    </li>
                </ul>
                <div class="bg-vl-ink rounded-[14px] p-2 text-vl-dark-text overflow-hidden" role="img" aria-label="Synthetic sample of the Vision Law audit trail for matter MAT-2026-001">
                    <p class="px-5 pt-4 pb-3 text-[12px] font-bold tracking-[0.12em] text-vl-dark-mut">AUDIT TRAIL · MAT-2026-001 · LIVE</p>
                    <div class="flex gap-3 px-5 py-3 border-t border-white/10 text-[13.5px] items-baseline">
                        <span class="text-vl-dark-mut text-[12px] whitespace-nowrap tabular-nums">14:02:11</span>
                        <span><strong class="text-white font-semibold">G. Granted</strong> viewed <strong class="text-white font-semibold">Complaint — filed stamp</strong></span>
                        <span class="ml-auto text-vl-neon text-[11.5px] font-mono whitespace-nowrap">#a3f9…c1</span>
                    </div>
                    <div class="flex gap-3 px-5 py-3 border-t border-white/10 text-[13.5px] items-baseline">
                        <span class="text-vl-dark-mut text-[12px] whitespace-nowrap tabular-nums">13:47:03</span>
                        <span><strong class="text-white font-semibold">P. Paralegal</strong> uploaded <strong class="text-white font-semibold">Deposition outline</strong> (v3)</span>
                        <span class="ml-auto text-vl-neon text-[11.5px] font-mono whitespace-nowrap">#77b2…e9</span>
                    </div>
                    <div class="flex gap-3 px-5 py-3 border-t border-white/10 text-[13.5px] items-baseline">
                        <span class="text-vl-dark-mut text-[12px] whitespace-nowrap tabular-nums">11:15:58</span>
                        <span><strong class="text-white font-semibold">System</strong> deadline generated: <strong class="text-white font-semibold">Discovery cutoff</strong></span>
                        <span class="ml-auto text-vl-neon text-[11.5px] font-mono whitespace-nowrap">#f0c4…2a</span>
                    </div>
                    <div class="flex gap-3 px-5 py-3 border-t border-white/10 text-[13.5px] items-baseline">
                        <span class="text-vl-dark-mut text-[12px] whitespace-nowrap tabular-nums">09:31:20</span>
                        <span><strong class="text-white font-semibold">G. Granted</strong> granted <strong class="text-white font-semibold">R. Rivera (co-counsel)</strong> 30-day access</span>
                        <span class="ml-auto text-vl-neon text-[11.5px] font-mono whitespace-nowrap">#9d1e…74</span>
                    </div>
                    <p class="px-5 py-3.5 text-[12.5px] text-vl-dark-mut border-t border-white/10">Append-only · hash-chained · retained per policy. Synthetic sample.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Testimonial (synthetic placeholder) --}}
    <section id="customers" class="py-[88px] max-[640px]:py-[60px] text-center">
        <div class="mx-auto max-w-[1120px] px-6">
            <p class="text-[12px] font-bold tracking-[0.16em] uppercase text-vl-brass">Customers</p>
            <blockquote class="font-serif text-[21px] md:text-[27px] leading-[1.45] text-vl-ink max-w-[800px] mx-auto my-[26px]">“We replaced four systems with Vision Law. For the first time in my career, I can answer ‘who touched this document’ in ten seconds.”</blockquote>
            <p class="text-vl-mut text-[14.5px]">General Counsel, Am Law 200 firm</p>
            <p class="mt-[10px]"><span class="inline-block text-[11.5px] tracking-[0.06em] border border-dashed border-vl-brass text-vl-brass rounded-[999px] px-3 py-[3px]">SYNTHETIC PLACEHOLDER — NOT A REAL QUOTE</span></p>
        </div>
    </section>

    {{-- Final CTA --}}
    <section class="text-center text-vl-dark-text bg-[radial-gradient(800px_360px_at_50%_120%,color-mix(in_srgb,var(--vl-neon)_20%,transparent),transparent_70%),linear-gradient(180deg,var(--vl-dark-1),var(--vl-dark-0))]">
        <div class="mx-auto max-w-[1120px] px-6 py-[88px]">
            <img src="/images/vision-emblem.png" alt="Vision Law emblem" class="h-16 w-auto mx-auto mb-[26px] [filter:drop-shadow(0_0_22px_color-mix(in_srgb,var(--vl-neon)_40%,transparent))]">
            <h2 class="text-white font-serif font-normal text-[27px] md:text-[34px] leading-[1.18] mb-[14px]">See what your practice is missing.</h2>
            <p class="text-vl-dark-mut mb-8 text-[17px]">Get a guided walkthrough on your own matters — or your agency’s.</p>
            <a href="#" class="inline-flex items-center justify-center font-semibold no-underline rounded-[10px] border border-transparent min-h-[44px] text-white text-[16px] px-[34px] py-[15px] bg-[linear-gradient(180deg,var(--vl-neon),var(--vl-neon-deep))] shadow-[0_0_28px_color-mix(in_srgb,var(--vl-neon)_50%,transparent),inset_0_1px_0_color-mix(in_srgb,white_35%,transparent)]">Request a demo</a>
        </div>
    </section>

    {{-- Footer --}}
    <footer class="bg-vl-dark-0 text-vl-dark-mut text-[14px]">
        <div class="mx-auto max-w-[1120px] px-6 pt-14 pb-8">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr] gap-8 mb-10">
                <div>
                    <img src="/images/vision-emblem.png" alt="Vision Law emblem" class="h-[30px] w-auto mb-3">
                    <p>The enterprise legal platform for firms, corporate legal departments, and government agencies.</p>
                </div>
                <nav aria-label="Platform">
                    <h3 class="text-white text-[13px] font-semibold tracking-[0.1em] uppercase mb-[14px]">Platform</h3>
                    <a href="#" class="block text-vl-dark-mut no-underline mb-[9px] hover:text-white">Matter management</a>
                    <a href="#" class="block text-vl-dark-mut no-underline mb-[9px] hover:text-white">Document intelligence</a>
                    <a href="#" class="block text-vl-dark-mut no-underline mb-[9px] hover:text-white">Deadline engine</a>
                    <a href="#" class="block text-vl-dark-mut no-underline mb-[9px] hover:text-white">Drafting copilot</a>
                </nav>
                <nav aria-label="Company">
                    <h3 class="text-white text-[13px] font-semibold tracking-[0.1em] uppercase mb-[14px]">Company</h3>
                    <a href="#" class="block text-vl-dark-mut no-underline mb-[9px] hover:text-white">About</a>
                    <a href="#" class="block text-vl-dark-mut no-underline mb-[9px] hover:text-white">Security</a>
                    <a href="#" class="block text-vl-dark-mut no-underline mb-[9px] hover:text-white">Customers</a>
                    <a href="#" class="block text-vl-dark-mut no-underline mb-[9px] hover:text-white">Contact</a>
                </nav>
                <nav aria-label="Legal">
                    <h3 class="text-white text-[13px] font-semibold tracking-[0.1em] uppercase mb-[14px]">Legal</h3>
                    <a href="#" class="block text-vl-dark-mut no-underline mb-[9px] hover:text-white">Privacy</a>
                    <a href="#" class="block text-vl-dark-mut no-underline mb-[9px] hover:text-white">Terms</a>
                    <a href="#" class="block text-vl-dark-mut no-underline mb-[9px] hover:text-white">DPA</a>
                </nav>
            </div>
            <div class="border-t border-white/10 pt-[22px] flex gap-[18px] flex-wrap items-center text-[12.5px]">
                <span>© 2026 Vision Law. All rights reserved.</span>
                <span class="ml-auto bg-[color-mix(in_srgb,var(--vl-brass)_18%,transparent)] text-vl-brass border border-[color-mix(in_srgb,var(--vl-brass)_50%,transparent)] text-[11px] tracking-[0.08em] px-[10px] py-[3px] rounded-[999px]">SYNTHETIC DATA ONLY</span>
            </div>
        </div>
    </footer>
</x-layouts.public>
