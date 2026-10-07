{{--
    public/partials/hero.blade.php — the cinematic hero (spec 003 06-plan.md T-02).

    Rendered inside <x-layouts.public> on the landing page only. The 003-D01
    scoped exception: token utilities only (vl-dark-0, vl-dark-1, vl-neon,
    vl-neon-deep, vl-dark-text, vl-dark-mut), no raw hex, no inline styles.
    The h1 is the page's single heading — exempt from door 4 per the fixed
    marketing-layout precedent (06-plan.md T-02/T-07).
--}}
<header class="relative overflow-hidden text-center text-vl-dark-text bg-[radial-gradient(900px_420px_at_50%_-80px,color-mix(in_srgb,var(--vl-neon)_22%,transparent),transparent_70%),radial-gradient(700px_380px_at_12%_110%,color-mix(in_srgb,var(--vl-neon-deep)_16%,transparent),transparent_70%),linear-gradient(180deg,var(--vl-dark-0)_0%,var(--vl-dark-1)_100%)]">
    <div class="relative mx-auto max-w-[1120px] px-6 pt-[84px] pb-[96px] max-[640px]:pt-[60px] max-[640px]:pb-[70px] max-[640px]:px-5">
        <img src="/images/vision-wordmark.png" alt="Vision Law" class="block mx-auto mb-[34px] w-[82%] max-w-[620px] h-auto [filter:drop-shadow(0_0_34px_color-mix(in_srgb,var(--vl-neon)_35%,transparent))]">
        <p class="text-vl-neon text-[12px] font-bold tracking-[0.16em] uppercase mb-[18px]">Enterprise legal platform</p>
        <h1 class="font-serif font-normal leading-[1.18] text-white text-[32px] md:text-[46px] max-w-[820px] mx-auto mb-5">Every matter. Every document.<br><em class="not-italic text-vl-neon">Every deadline.</em></h1>
        <p class="text-[16px] md:text-[18px] text-vl-dark-mut max-w-[700px] mx-auto mb-9">Vision Law is the system of record for law firms, corporate legal departments, and government agencies — matter management, document intelligence, and drafting in one place, with an audit trail you can prove.</p>
        <div class="flex gap-4 justify-center flex-wrap">
            <a href="#" class="w-full sm:w-auto inline-flex items-center justify-center font-semibold no-underline rounded-[10px] border border-transparent min-h-[44px] text-white text-[16px] px-[34px] py-[15px] bg-[linear-gradient(180deg,var(--vl-neon),var(--vl-neon-deep))] shadow-[0_0_28px_color-mix(in_srgb,var(--vl-neon)_50%,transparent),inset_0_1px_0_color-mix(in_srgb,white_35%,transparent)]">Request a demo</a>
            <a href="/login" class="w-full sm:w-auto inline-flex items-center justify-center font-semibold no-underline rounded-[10px] min-h-[44px] text-vl-dark-text text-[16px] px-[34px] py-[15px] border border-[color-mix(in_srgb,var(--vl-dark-mut)_40%,transparent)] bg-[color-mix(in_srgb,white_3%,transparent)] hover:border-vl-neon hover:text-white">Sign in</a>
        </div>
        <p class="mt-[26px] text-[13.5px] text-vl-dark-mut">Deploys in your cloud or ours · No rip-and-replace required</p>
    </div>
</header>
