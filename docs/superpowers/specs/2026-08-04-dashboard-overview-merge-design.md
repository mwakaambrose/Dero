# Merging the dashboard and overview screens

## Problem

A team has two landing screens that overlap:

- `/{team}/dashboard` — `DashboardController` renders `resources/js/pages/dashboard.tsx`:
  three placeholder tiles and a pending-invitations modal. This is where the app
  actually lands users. `config/fortify.php` points `home` at it, the login,
  passkey and email-verification responses redirect through it, invitation
  accept and decline call `to_route('dashboard')`, and the sidebar logo links to
  it. The controller does no team scoping — it only reads `$request->user()`.
- `/{team}/overview` — `Storage\OverviewController` renders
  `resources/js/pages/storage/overview.tsx`: the real storage dashboard, with
  usage stats, a 14-day usage chart, largest buckets, recent objects and recent
  activity. It is the only one of the two the sidebar navigation links, as
  "Overview".

So users land on a placeholder while the screen with the content sits one click
away behind a second URL.

## Decision

One screen, served at `/{team}/dashboard`. The overview content becomes the
landing page and the invitations modal rides along on top of it. `/{team}/overview`
is removed outright rather than redirected — the URL has never been the app's
landing page and nothing outside the repository is expected to link to it.

Keeping `/dashboard` as the surviving URL means the fortify `home` setting, all
four redirect responses, `TeamInvitationController`, `app-header.tsx`,
`welcome.tsx` and the sidebar logo link are untouched by this change.

## Changes

### Route

`routes/web.php`: keep the `dashboard` route pointed at `DashboardController`.
Delete the `overview` route line and the `Storage\OverviewController` import.
`/{team}/overview` then 404s.

### Controller

`app/Http/Controllers/DashboardController.php` absorbs `OverviewController`'s
body. A single `__invoke` returns `Inertia::render('dashboard', ...)` with the
existing `pendingInvitations` prop plus `stats`, `series`, `largestBuckets`,
`recentObjects` and `activity`, built by exactly the queries
`OverviewController` runs today. The class gains `declare(strict_types=1)` and
`final` to match the storage controllers, and resolves its team through
`App\Support\StorageDashboard::team($request)` — which also closes the gap where
the current dashboard controller has no tenant scoping at all.

`app/Http/Controllers/Storage/OverviewController.php` is deleted.
`App\Support\StorageDashboard` stays where it is; the other storage controllers
still use it.

The merged controller lands at roughly 110 lines. That is within the size the
other storage controllers already run to, so the stats payload is not extracted
into a separate builder class.

### Page

`resources/js/pages/dashboard.tsx` becomes the current overview page's render
output, with `PendingInvitationsModal` and its `showInvitations` state added
inside the existing fragment, above the content. `Head title` and the `<h1>`
both stay "Overview". The layout export becomes `Dashboard.layout`, with the
breadcrumb titled "Overview" and pointing at `dashboard(slug)`; the `overview`
import from `@/routes/storage` is replaced by `dashboard` from `@/routes`.

`resources/js/pages/storage/overview.tsx` is deleted.

`resources/js/components/app-sidebar.tsx`: the "Overview" navigation item's
`href` becomes `dashboardUrl` and the `storageOverview` import is removed. The
label stays "Overview" so it continues to read as a sibling of Buckets, Access
keys, Connect and Activity. The no-team fallback branch of `mainNavItems` — the
single "Dashboard" item shown when there is no current team — is left alone.

Then run `php artisan wayfinder:generate`, which drops `overview` from
`resources/js/routes/storage/index.ts` and removes the generated
`resources/js/actions/App/Http/Controllers/Storage/OverviewController.ts`.

### The invitations modal

The modal keeps its current behaviour: it opens automatically whenever the user
has pending invitations, and closing it dismisses it for that page load. It now
opens over a dense stats screen rather than a placeholder. Changing it to a
dismissible banner is out of scope for this merge.

## Testing

- `tests/Feature/Storage/DashboardPagesTest.php`: the render dataset entry
  `'overview'` becomes `'dashboard'`, and the authentication test's
  `/harvest/overview` becomes `/harvest/dashboard`.
- `tests/Feature/DashboardTest.php`: the four existing invitation tests are
  unchanged — `route('dashboard')` still resolves without an explicit team
  because the `SetTeamUrlDefaults` middleware supplies the `current_team`
  default. Two tests are added: the dashboard response carries `stats`,
  `series`, `largestBuckets`, `recentObjects` and `activity` for the current
  team; and a second team's buckets and objects do not appear in those props.
- A new test asserts `/harvest/overview` returns 404 for a signed-in member.

Run `php artisan test --compact tests/Feature/DashboardTest.php tests/Feature/Storage/DashboardPagesTest.php`
and `vendor/bin/pint --dirty --format agent` before finishing.
