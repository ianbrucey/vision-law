<?php

use App\Http\Controllers\Admin\AuditEventController as AdminAuditEventController;
use App\Http\Controllers\Admin\InvitationController as AdminInvitationController;
use App\Http\Controllers\Admin\TeamController as AdminTeamController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorQrCodeImageController;
use App\Http\Controllers\Auth\TwoFactorSettingsController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentEditorController;
use App\Http\Controllers\DocumentLogController;
use App\Http\Controllers\DocumentPreviewController;
use App\Http\Controllers\DocumentUploadController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\MatterController;
use App\Http\Controllers\MatterGrantController;
use App\Http\Controllers\PartyController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TemplateController;
use App\Http\Middleware\RequireOrgAdmin;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

// ── Spec 003 T-05: public site + auth views ──
// Fortify is headless (config/fortify.php: views => false), so it registers
// no GET view routes -- these are app-level. The POST backends stay
// Fortify's, untouched (see the auth block below).
Route::get('/', function () {
    return view('public.landing');
});

Route::get('/login', [LoginController::class, 'create'])
    ->middleware(['guest:'.config('fortify.guard')])
    ->name('login');

// Same FORTIFY_REGISTRATION flag gate as the POST route below (001-D09):
// with the flag off, neither the view nor the endpoint is registered (404).
if (Features::enabled(Features::registration())) {
    Route::get('/register', [RegisteredUserController::class, 'create'])
        ->middleware(['guest:'.config('fortify.guard')])
        ->name('register');
}

// ── Auth (Fortify headless backend; 03-contract.md §Routes guest list) ──
// Fortify registers its auth POST/PUT/DELETE routes itself — headless mode
// means no GET view routes (verified T-01). The routes below are app-level
// overrides, registered after Fortify's so they take precedence:
//
// - POST /register: Fortify's contract, but a fresh registration does NOT
//   auto-login — unverified accounts cannot log in (C-01). Registered only
//   when the FORTIFY_REGISTRATION flag is on (001-D09).
// - POST /forgot-password: generic success whether or not the email belongs
//   to an account — no account enumeration (C-04).
// - POST /two-factor-challenge: Fortify's challenge with the brute-force
//   lockout applied (C-03).
// - GET /reset-password/{token}: named route only, so the reset notification
//   can generate its URL; the headless API renders no reset form.
if (Features::enabled(Features::registration())) {
    Route::post('/register', [RegisteredUserController::class, 'store'])
        ->middleware(['guest:'.config('fortify.guard')])
        ->name('register.store');
}

Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware(['guest:'.config('fortify.guard'), 'throttle:6,1'])
    ->name('password.email');

Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
    ->middleware(['guest:'.config('fortify.guard'), 'throttle:10,1'])
    ->name('two-factor.login.store');

// ── Spec 005 T-02: 2FA challenge page (005-D02) ──
// GET two-factor-challenge, named two-factor.login — the name Fortify's
// post-password redirect targets. The guard is Fortify's own rule: only
// with a live challenged-user session ($request->hasChallengedUser());
// otherwise redirect to login (U-2FA-05). Deliberately no guest
// middleware: a signed-in user without a challenged session must land
// on login, not the authenticated home.
Route::get('/two-factor-challenge', function (TwoFactorLoginRequest $request) {
    if (! $request->hasChallengedUser()) {
        return redirect()->route('login');
    }

    return view('auth.two-factor-challenge', [
        'email' => $request->challengedUser()->email,
    ]);
})->name('two-factor.login');

// ── Spec 005 T-01: 2FA enrollment page (005-D02) ──
// GET user/two-factor, named two-factor.settings. Fortify is headless
// (views => false), so the POST/DELETE backends stay Fortify's; this is the
// app-level view route. In setup mode (005-D01) RestrictToTwoFactorSetup
// limits the session to this page and its actions.
Route::get('user/two-factor', [TwoFactorSettingsController::class, 'index'])
    ->middleware(['auth:'.config('fortify.guard')])
    ->name('two-factor.settings');

// --- Spec 005 T-03: QR-as-image + confirm-password view ---
// two-factor.qr-image: Fortify's two-factor.qr-code returns JSON {svg, url},
// not an image, so 005-D04's <img> points here instead (an <img> cannot
// render JSON). Serves Fortify's QR SVG bytes as image/svg+xml -- the
// secret/otpauth bytes travel in this image response only, never in the
// enrollment page's HTML source. Middleware mirrors Fortify's own QR route.
// password.confirm: Fortify skips the GET view route in headless mode
// (views => false); the password.confirm gate (2FA disable/regenerate and
// friends) needs it to be completable via UI. No new auth logic --
// ConfirmablePasswordController and the ConfirmPasswordViewResponse
// contract are Fortify's; the view is registered via
// Fortify::confirmPasswordView() in FortifyServiceProvider.
Route::get('user/two-factor-qr-code.svg', [TwoFactorQrCodeImageController::class, 'show'])
    ->middleware(['auth:'.config('fortify.guard'), 'password.confirm'])
    ->name('two-factor.qr-image');

Route::get('user/confirm-password', [ConfirmablePasswordController::class, 'show'])
    ->middleware(['auth:'.config('fortify.guard')])
    ->name('password.confirm');

Route::get('/reset-password/{token}', function () {
    return response()->json([
        'message' => 'Password reset is handled by the client application: POST /reset-password with token, email and new password.',
    ]);
})->middleware(['guest:'.config('fortify.guard')])->name('password.reset');

// ── Ticket 5: RBAC admin, invitations, session management (backend only,
// no Blade per 001-D06) ──

// Invitation landing + accept (auth). The token is the credential, so the
// landing page is reachable by guests and signed-in users alike (004-D08):
// after signing in via the ?next= link, an existing invitee lands back on
// the accept page instead of being bounced to /home by guest middleware.
// A different signed-in user is rejected generically (C-05); acceptance
// still enforces the email/org match rule.
Route::get('/invitations/{token}', [InvitationController::class, 'show'])
    ->name('invitations.show');
Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])
    ->middleware(['auth:'.config('fortify.guard')])
    ->name('invitations.accept');

// Session management (C-07): list is self-service; destructive actions sit
// behind password.confirm.
Route::middleware(['auth:'.config('fortify.guard')])->group(function (): void {
    Route::get('/sessions', [SessionController::class, 'index'])->name('sessions.index');
    Route::delete('/sessions', [SessionController::class, 'destroyAll'])
        ->middleware('password.confirm')
        ->name('sessions.destroyAll');
    Route::delete('/sessions/{id}', [SessionController::class, 'destroy'])
        ->middleware('password.confirm')
        ->name('sessions.destroy');
});

// Admin section (03-contract.md §Routes): org_admin only — 403 otherwise,
// audited as user.admin.denied. Every query is scoped to the admin's org,
// so the system org (001-D13) can never appear nor receive users.
Route::middleware(['auth:'.config('fortify.guard'), RequireOrgAdmin::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
        Route::match(['put', 'patch'], '/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');

        Route::get('/teams', [AdminTeamController::class, 'index'])->name('teams.index');
        Route::post('/teams', [AdminTeamController::class, 'store'])->name('teams.store');
        Route::get('/teams/{team}', [AdminTeamController::class, 'show'])->name('teams.show');
        Route::match(['put', 'patch'], '/teams/{team}', [AdminTeamController::class, 'update'])->name('teams.update');
        Route::delete('/teams/{team}', [AdminTeamController::class, 'destroy'])->name('teams.destroy');

        Route::get('/invitations', [AdminInvitationController::class, 'index'])->name('invitations.index');
        Route::post('/invitations', [AdminInvitationController::class, 'store'])->name('invitations.store');
        Route::delete('/invitations/{invitation}', [AdminInvitationController::class, 'destroy'])->name('invitations.destroy');
    });

// ── Ticket 7: audit viewer (backend only, no Blade per 001-D06) ──
// Denials on these routes are audited as audit.viewer.denied (03-contract.md
// §Error catalog), distinct from the generic user.admin.denied on the other
// admin routes. The export additionally sits behind password.confirm.
// Queries are scoped to the admin's org (001-D13: system-org events are out
// of scope for the org viewer).
Route::middleware(['auth:'.config('fortify.guard'), RequireOrgAdmin::class.':audit.viewer.denied'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/audit-events', [AdminAuditEventController::class, 'index'])->name('audit-events.index');
        Route::get('/audit-events/export', [AdminAuditEventController::class, 'export'])
            ->middleware('password.confirm')
            ->name('audit-events.export');
    });

// === 006 matter routes ===
// Spec 006 T-02 owns this section: matter CRUD + lifecycle (index, create,
// store, show, edit, update, destroy, restore, transition, close, summary).
// Tickets 3/4 APPEND their own delimited sections AFTER this one — do not
// scatter 006 routes elsewhere.
//
// Auth note: every route requires authentication. Matter-scoped routes use
// RequireMatterAccess with the contract action levels (Ticket 6):
// :view < :comment < :edit < :manage (grant ≈ manage). POST
// /matters/{matter}/restore resolves soft-deleted rows, which
// RequireMatterAccess cannot see, so it carries no matter middleware — the
// controller enforces org_admin + same-org scoping before any data access.
Route::middleware(['auth:'.config('fortify.guard')])->group(function (): void {
    Route::get('/matters', [MatterController::class, 'index'])->name('matters.index');
    Route::get('/matters/create', [MatterController::class, 'create'])->name('matters.create');
    Route::post('/matters', [MatterController::class, 'store'])->name('matters.store');

    Route::get('/matters/{matter}', [MatterController::class, 'show'])
        ->middleware('matter.access:view')
        ->name('matters.show');
    Route::get('/matters/{matter}/edit', [MatterController::class, 'edit'])
        ->middleware('matter.access:edit')
        ->name('matters.edit');
    Route::patch('/matters/{matter}', [MatterController::class, 'update'])
        ->middleware('matter.access:edit')
        ->name('matters.update');
    Route::delete('/matters/{matter}', [MatterController::class, 'destroy'])
        ->middleware('matter.access:manage')
        ->name('matters.destroy');
    Route::post('/matters/{matter}/restore', [MatterController::class, 'restore'])
        ->name('matters.restore');
    Route::post('/matters/{matter}/transition', [MatterController::class, 'transition'])
        ->middleware('matter.access:manage')
        ->name('matters.transition');
    Route::post('/matters/{matter}/close', [MatterController::class, 'close'])
        ->middleware('matter.access:manage')
        ->name('matters.close');
    Route::get('/matters/{matter}/summary', [MatterController::class, 'summary'])
        ->middleware('matter.access:view')
        ->name('matters.summary');
});

// === 006 matter routes — Tickets 3/4 ===
// Spec 006 T-03/T-04 own this section: parties, comments, links, document
// log, assignments, search, timeline reads. Appended AFTER T-02's
// `// === 006 matter routes ===` section — do not scatter 006 routes
// elsewhere.
//
// Action levels (Ticket 6): :view < :comment < :edit < :manage
// (grant ≈ manage). Comment routes: store carries :comment at the
// middleware (the 006-D13 floor moved out of the controller); update and
// destroy keep :view at the middleware because the contract's "author
// (24h) or :manage" rule needs the comment row — a viewer must still edit
// their own comment within 24h, which a pure middleware gate cannot
// express — so the author-or-manage check stays in CommentController.
//
// Search lives at GET /search/matters (006-D12): /matters/{matter} is
// registered earlier in this file and would swallow /matters/search.
Route::middleware(['auth:'.config('fortify.guard')])->group(function (): void {
    Route::get('/search/matters', [MatterController::class, 'search'])->name('search.matters');

    Route::post('/matters/{matter}/parties', [PartyController::class, 'store'])
        ->middleware('matter.access:edit')
        ->name('matters.parties.store');
    Route::delete('/matters/{matter}/parties/{party}', [PartyController::class, 'destroy'])
        ->middleware('matter.access:edit')
        ->name('matters.parties.destroy');

    Route::post('/matters/{matter}/comments', [CommentController::class, 'store'])
        ->middleware('matter.access:comment')
        ->name('matters.comments.store');
    Route::patch('/matters/{matter}/comments/{comment}', [CommentController::class, 'update'])
        ->middleware('matter.access:view')
        ->name('matters.comments.update');
    Route::delete('/matters/{matter}/comments/{comment}', [CommentController::class, 'destroy'])
        ->middleware('matter.access:view')
        ->name('matters.comments.destroy');
    Route::post('/matters/{matter}/timeline/read', [CommentController::class, 'markRead'])
        ->middleware('matter.access:view')
        ->name('matters.timeline.read');

    Route::get('/matters/{matter}/links', [LinkController::class, 'index'])
        ->middleware('matter.access:view')
        ->name('matters.links.index');
    Route::post('/matters/{matter}/links', [LinkController::class, 'store'])
        ->middleware('matter.access:edit')
        ->name('matters.links.store');
    Route::delete('/matters/{matter}/links/{link}', [LinkController::class, 'destroy'])
        ->middleware('matter.access:edit')
        ->name('matters.links.destroy');

    Route::post('/matters/{matter}/document-log', [DocumentLogController::class, 'store'])
        ->middleware('matter.access:edit')
        ->name('matters.document-log.store');
    Route::patch('/matters/{matter}/document-log/{log}', [DocumentLogController::class, 'update'])
        ->middleware('matter.access:edit')
        ->name('matters.document-log.update');

    Route::post('/matters/{matter}/assignments', [AssignmentController::class, 'store'])
        ->middleware('matter.access:manage')
        ->name('matters.assignments.store');
    Route::delete('/matters/{matter}/assignments/{grant}', [AssignmentController::class, 'destroy'])
        ->middleware('matter.access:manage')
        ->name('matters.assignments.destroy');
});

// ── Ticket 6: matter grants (backend only, no Blade per 001-D06) ──
// RequireMatterAccess resolves the matter and authorizes BEFORE any data
// access; controllers read the authorized matter from request attributes.
Route::middleware(['auth:'.config('fortify.guard')])->group(function (): void {
    Route::post('/matters/{matter}/grants', [MatterGrantController::class, 'store'])
        ->middleware('matter.access:grant')
        ->name('matters.grants.store');

    Route::delete('/matters/{matter}/grants/{grant}', [MatterGrantController::class, 'destroy'])
        ->middleware('matter.access:grant')
        ->name('matters.grants.destroy');
});

// ── Spec 002 T-05: /_patterns catalogue (local only) ──
// Renders every x-ui primitive in every variant/state with synthetic data —
// the living drift detector (docs/UI_Standards.md Governance). 404s outside
// the local environment (C-08). Implemented as an env-gated abort inside the
// route (rather than conditional registration) so the suite can assert both
// states; externally it is unreachable in production either way. Carries auth
// like every other non-guest route (AuthenticatedByDefaultTest door) — in
// production an authenticated hit still 404s on the env gate.
Route::get('/_patterns', function () {
    abort_unless(app()->environment('local'), 404);

    $documents = collect(range(1, 42))->map(fn (int $i): array => [
        'title' => "Synthetic document {$i}",
        'status' => ['draft', 'final', 'superseded'][$i % 3],
        'modified' => 'Oct '.(1 + ($i % 6)).', 2026',
        'by' => $i % 2 === 0 ? 'G. Granted' : 'P. Paralegal',
    ]);
    $perPage = 15;
    $pageItems = $documents->forPage(2, $perPage)->values();
    $paginator = new LengthAwarePaginator($pageItems, $documents->count(), $perPage, 2, ['path' => '/_patterns']);

    // Rendered by <x-ui.toast /> in the patterns page (re-flashed per tone so
    // every tone is demonstrated from the real session-flash path).
    $toastDemos = [
        ['tone' => 'ok', 'message' => 'Draft exported — PDF saved to Sterling v. Apex › Documents.'],
        ['tone' => 'bad', 'message' => 'Upload failed — contract-scan.pdf exceeds the 50 MB limit.'],
        ['tone' => 'info', 'message' => 'Deadline recalculated — discovery cutoff moved to Nov 12.'],
    ];

    return view('patterns', [
        'documents' => $pageItems,
        'paginator' => $paginator,
        'toastDemos' => $toastDemos,
    ]);
})->middleware('auth:'.config('fortify.guard'))->name('patterns');
// === 006 matter routes — Ticket 6 ===
// Spec 006 T-06 owns this section: the immutable activity feed (C-07;
// 006-D04 — a read model over audit_events, newest-first, filterable by
// type/actor, paginated). Authorization is :view — every actor who can see
// the matter can read its feed; the feed never reveals more than the
// matter's own audit rows.
Route::middleware(['auth:'.config('fortify.guard')])->group(function (): void {
    Route::get('/matters/{matter}/feed', [MatterController::class, 'feed'])
        ->middleware('matter.access:view')
        ->name('matters.feed');
});

// === Spec 007 T-04: editor + templates (stationery) ===
// Built-in editor (authoring from scratch, DOC-09/10) and the template
// library (merge-field stationery only, DOC-22/23 — 007-D03: no drafting
// intelligence). Editor routes nest under /matters/{matter} and carry
// RequireMatterAccess before any data access; the generate flow resolves
// its target matter in TemplateController (contract §Templates).
Route::middleware(['auth:'.config('fortify.guard')])->group(function (): void {
    // Editor (authored/generated documents; uploaded binaries are 422 here)
    Route::get('/matters/{matter}/documents/authored/create', [DocumentEditorController::class, 'create'])
        ->middleware('matter.access:edit')
        ->name('documents.authored.create');

    Route::post('/matters/{matter}/documents/authored', [DocumentEditorController::class, 'store'])
        ->middleware('matter.access:edit')
        ->name('documents.authored.store');

    Route::get('/matters/{matter}/documents/{document}/edit', [DocumentEditorController::class, 'edit'])
        ->middleware('matter.access:edit')
        ->name('documents.editor.edit');

    // Autosave draft every 30s: ephemeral, NOT a version, no audit row.
    Route::post('/matters/{matter}/documents/{document}/draft', [DocumentEditorController::class, 'draft'])
        ->middleware('matter.access:edit')
        ->name('documents.draft.update');

    // Explicit Save: publishes a new immutable version (HTML + PDF rendition).
    Route::post('/matters/{matter}/documents/{document}/publish', [DocumentEditorController::class, 'publish'])
        ->middleware('matter.access:edit')
        ->name('documents.versions.publish');

    // Templates (org-level stationery; publishing needs template_editor role)
    Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
    Route::get('/templates/create', [TemplateController::class, 'create'])->name('templates.create');
    Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');
    Route::get('/templates/{template}', [TemplateController::class, 'show'])->name('templates.show');
    Route::get('/templates/{template}/edit', [TemplateController::class, 'edit'])->name('templates.edit');
    Route::patch('/templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
    Route::post('/templates/{template}/publish', [TemplateController::class, 'publish'])->name('templates.publish');
    Route::get('/templates/{template}/generate', [TemplateController::class, 'generateForm'])->name('templates.generate.form');
    Route::post('/templates/{template}/generate', [TemplateController::class, 'generate'])->name('templates.generate');
});

// === 007 document routes — T-02: upload pipeline + malware scan ===
// Spec 007 T-02 owns this section: single-shot upload, chunked/resumable
// upload sessions, and the ClamAV scan gate. Later 007 tickets append their
// own sections below — do not interleave.
//
// Action levels: every upload route carries matter.access:edit (uploads
// create content); chunk-session routes additionally require session
// ownership (enforced in DocumentUploadController: 403 + audit otherwise).
Route::middleware(['auth:'.config('fortify.guard')])->group(function (): void {
    Route::post('/matters/{matter}/documents/upload', [DocumentUploadController::class, 'store'])
        ->middleware('matter.access:edit')
        ->name('documents.upload');
    Route::post('/matters/{matter}/documents/uploads/init', [DocumentUploadController::class, 'init'])
        ->middleware('matter.access:edit')
        ->name('documents.uploads.init');
    Route::get('/matters/{matter}/documents/uploads/{session}', [DocumentUploadController::class, 'show'])
        ->middleware('matter.access:edit')
        ->name('documents.uploads.show');
    Route::put('/matters/{matter}/documents/uploads/{session}/chunks/{n}', [DocumentUploadController::class, 'chunk'])
        ->middleware('matter.access:edit')
        ->where('n', '[0-9]+')
        ->name('documents.uploads.chunk');
    Route::post('/matters/{matter}/documents/uploads/{session}/complete', [DocumentUploadController::class, 'complete'])
        ->middleware('matter.access:edit')
        ->name('documents.uploads.complete');
    Route::delete('/matters/{matter}/documents/uploads/{session}', [DocumentUploadController::class, 'cancel'])
        ->middleware('matter.access:edit')
        ->name('documents.uploads.cancel');
});

// ── Spec 007 T-03: metadata, previews, download ──
// Preview page + signed-URL byte serving + exact-bytes download
// (DOC-04/05/06/07/08). The page route carries matter.access:view; the
// byte routes (preview/file, download) are authorized by HMAC-signed
// URLs (15-min, scoped to the user) with permission re-checked on every
// request — including range requests — so revoked access kills
// outstanding URLs. Quarantined documents → 403 'quarantined'.
Route::middleware(['auth:'.config('fortify.guard')])->group(function (): void {
    Route::get('/matters/{matter}/documents/{document}/preview', [DocumentPreviewController::class, 'show'])
        ->middleware('matter.access:view')
        ->name('documents.preview');
    Route::get('/matters/{matter}/documents/{document}/preview/file', [DocumentPreviewController::class, 'file'])
        ->name('documents.preview.file');
    Route::get('/matters/{matter}/documents/{document}/download', [DocumentPreviewController::class, 'download'])
        ->name('documents.download');
});

// ── Spec 007 T-05: folders, filing, trash, document list ──
// Per-matter folder tree + document filing endpoints (DOC-11/12/13/14).
// Every route carries RequireMatterAccess BEFORE any data access; the
// authorized matter is read from request attributes, never re-resolved.
// Document action levels (:view < :edit < :manage) map onto the 006
// AccessControl ladder (01-archaeology.md: EXTEND, names identical).
//
// Route-order note: /documents/trash and /documents/bulk/* are registered
// BEFORE /documents/{document} so the literal segments are not swallowed
// by the {document} placeholder.
Route::middleware(['auth:'.config('fortify.guard')])->group(function (): void {
    // Folders (DOC-11): tree, create/rename/move, delete empty-only.
    Route::get('/matters/{matter}/folders', [FolderController::class, 'index'])
        ->middleware('matter.access:view')
        ->name('folders.index');
    Route::post('/matters/{matter}/folders', [FolderController::class, 'store'])
        ->middleware('matter.access:edit')
        ->name('folders.store');
    Route::patch('/matters/{matter}/folders/{folder}', [FolderController::class, 'update'])
        ->middleware('matter.access:edit')
        ->name('folders.update');
    Route::delete('/matters/{matter}/folders/{folder}', [FolderController::class, 'destroy'])
        ->middleware('matter.access:edit')
        ->name('folders.destroy');

    // Per-matter trash (DOC-11): :manage only.
    Route::get('/matters/{matter}/documents/trash', [DocumentController::class, 'trash'])
        ->middleware('matter.access:manage')
        ->name('documents.trash');

    // Bulk filing (DOC-11): move/tag/download-as-ZIP.
    Route::post('/matters/{matter}/documents/bulk/move', [DocumentController::class, 'bulkMove'])
        ->middleware('matter.access:edit')
        ->name('documents.bulk.move');
    Route::post('/matters/{matter}/documents/bulk/tag', [DocumentController::class, 'bulkTag'])
        ->middleware('matter.access:edit')
        ->name('documents.bulk.tag');
    Route::post('/matters/{matter}/documents/bulk/download', [DocumentController::class, 'bulkDownload'])
        ->middleware('matter.access:view')
        ->name('documents.bulk.download');

    // Document list (DOC-14; C-09 list half): faceted + keyword, 50/page.
    Route::get('/matters/{matter}/documents', [DocumentController::class, 'index'])
        ->middleware('matter.access:view')
        ->name('documents.index');

    // Saved views (per-user).
    Route::get('/matters/{matter}/document-views', [DocumentController::class, 'indexViews'])
        ->middleware('matter.access:view')
        ->name('document-views.index');
    Route::post('/matters/{matter}/document-views', [DocumentController::class, 'storeView'])
        ->middleware('matter.access:view')
        ->name('document-views.store');
    Route::delete('/matters/{matter}/document-views/{view}', [DocumentController::class, 'destroyView'])
        ->middleware('matter.access:view')
        ->name('document-views.destroy');

    // Filing (DOC-11/12/13).
    Route::get('/matters/{matter}/documents/{document}', [DocumentController::class, 'show'])
        ->middleware('matter.access:view')
        ->name('documents.show');
    Route::patch('/matters/{matter}/documents/{document}', [DocumentController::class, 'update'])
        ->middleware('matter.access:edit')
        ->name('documents.update');
    Route::delete('/matters/{matter}/documents/{document}', [DocumentController::class, 'destroy'])
        ->middleware('matter.access:manage')
        ->name('documents.destroy');
    Route::delete('/matters/{matter}/documents/{document}/permanent', [DocumentController::class, 'destroyPermanent'])
        ->middleware('matter.access:manage')
        ->name('documents.destroy.permanent');
    Route::post('/matters/{matter}/documents/{document}/restore', [DocumentController::class, 'restore'])
        ->middleware('matter.access:manage')
        ->name('documents.restore');
    Route::post('/matters/{matter}/documents/{document}/move', [DocumentController::class, 'move'])
        ->middleware('matter.access:edit')
        ->name('documents.move');
});
