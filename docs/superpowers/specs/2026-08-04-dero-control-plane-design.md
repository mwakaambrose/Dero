# Dero — Sub-project B: Control Plane, Dashboard & Landing Page

**Date:** 2026-08-04
**Status:** Approved for planning
**Depends on:** Sub-project A (data plane)
**Blocks:** nothing

---

## 1. Purpose

Everything a human touches: the public landing page, first-run setup, and the dashboard
for managing buckets, objects, access keys, usage, and activity.

The data plane is the product's substance; this is its face. The bar is a polished modern
SaaS product, not an admin panel. Concretely that means: real typographic hierarchy,
restrained colour, considered empty states, keyboard navigation, and dark mode that was
designed rather than inverted.

## 2. Brand identity

**Dero** is a traditional cylindrical granary of the Luo and Acholi peoples: a woven
basket body raised on legs above the ground, capped by a conical grass-thatched roof,
protecting millet, sorghum, simsim and maize from moisture and pests.

The metaphor is load-bearing, not decorative. *Raised off the ground* — isolated from the
host. *Woven from many parts into one vessel* — many objects, one store. *Sealed against
moisture and pests* — durability and access control. *What you keep after the harvest* —
the things worth storing.

### 2.1 The mark

A geometric reduction of the granary: a triangle (the conical roof) sitting above a
rounded-corner cylinder (the woven body), crossed by one horizontal weave band, standing
on three short legs.

Constraints it must satisfy:
- Legible at 16px as a favicon.
- Single-colour capable — no gradients, no more than one fill.
- Recognisable in silhouette.

Delivered as a React component (`app-logo-icon.tsx`, replacing the starter kit's) plus an
SVG favicon and an `apple-touch-icon` PNG.

### 2.2 Palette

The starter kit ships shadcn's neutral greys (`oklch(0.145 0 0)` and friends). Dero
re-tints these warm and introduces a single accent.

- **Accent — millet gold**, roughly `oklch(0.72 0.13 75)`. The colour of dried grain.
  Used for primary actions, active navigation, and the logo. Nothing else.
- **Surfaces** — warm neutrals: hues pulled a few degrees toward amber rather than pure
  achromatic grey, so the interface reads as earthen rather than clinical.
- **Dark mode** — deep warm charcoal near `oklch(0.16 0.008 60)`, not black. Grain stored
  in shade, not a void.
- **Semantic** — amber for warnings, red for destructive, green for success. These are
  never used decoratively.

Colour carries meaning. If a surface is coloured, that colour means something.

Exact values are fixed during implementation against contrast measurement (§10), not
guessed here. All of them replace the `:root` / `.dark` custom properties in
`resources/css/app.css`, so every existing shadcn component inherits the identity for
free.

### 2.3 Type

`Instrument Sans` is already wired through the Bunny fonts Vite plugin — keep it. Add a
monospace face for the things that are literally machine data: access key IDs, ETags,
checksums, object keys, and every code sample. Mixing those into the sans face is a
readability failure, not a style choice.

## 3. Landing page

A full marketing page at `/`, replacing the starter kit's `welcome.tsx`. Server-rendered
through Inertia, no auth, fast first paint, dark/light aware.

Sections, in order:

1. **Hero** — the mark at size, a one-line positioning statement, and the honest pitch:
   your own S3-compatible object storage, on your own server. Primary CTA to get started,
   secondary to the docs. Behind it, a restrained visual built from the granary's weave
   geometry — not a stock gradient blob.
2. **The problem** — object storage is either someone else's cloud with someone else's
   egress bill, or a cluster you have to operate. Dero is one application on one VPS.
3. **Feature grid** — S3 API, dashboard file browser, access keys, presigned URLs,
   multipart uploads, range requests, CORS, usage analytics, audit log. Each with the
   Lucide icon it actually deserves, not a generic checkmark.
4. **Works with your existing tools** — tabbed, copy-ready code samples for AWS CLI,
   Laravel/Flysystem, Python/boto3, JavaScript, and rclone. This is the section that
   converts a developer, so the samples must be correct and runnable.
5. **Compatibility, stated honestly** — a condensed version of the matrix from
   `docs/s3-compatibility.md`, including what is *not* supported. Claiming full S3
   compatibility would be a lie and developers detect it instantly.
6. **Self-hosting** — the story of §2 told properly, plus what it takes to run: one
   Ubuntu box, Nginx, PHP-FPM, MySQL, Redis.
7. **Footer** — docs, source, license.

## 4. First-run setup

On a fresh install with no users, every route redirects to `/setup`. A guarded wizard,
one step per screen, with a progress indicator:

1. **Welcome** — what Dero is, what the wizard will do.
2. **Administrator** — name, email, password (Fortify's existing rules).
3. **Organisation** — team name; creates the first `Team` and its owner membership.
4. **Storage** — `STORAGE_ROOT` path, with a live writability and free-space check. This
   step *verifies* rather than accepts: an unwritable path is caught here, not on the
   first upload.
5. **Security** — endpoint host, region, and whether to permit public buckets at all.
6. **Finish** — creates the first access key and shows the secret **once**, with a
   copy-ready AWS CLI snippet already filled in.

Completion is recorded in `system_settings`; the wizard then refuses to run again. This
is a security boundary, not a UX nicety — a re-runnable setup wizard is an admin-account
creation endpoint.

## 5. Navigation

Sidebar (the starter kit's `app-sidebar.tsx`, restructured): Overview, Buckets, Files,
Access Keys, Team, Usage, Activity, Settings, Help. Team switcher and user menu pinned to
the bottom — both already exist and are reused.

**Command palette** (`Cmd/Ctrl+K`): jump to any bucket, search files, upload, create
bucket, create access key, toggle theme, open settings. For a tool developers live in,
this is primary navigation, not a flourish.

## 6. Screens

### 6.1 Overview

Stat tiles — total storage, object count, bucket count, requests, bandwidth — each with
its period-over-period delta. Four Recharts time series: storage, objects, requests,
bandwidth, over 24h / 7d / 30d, fed from `usage_records`. A recent-activity feed from
`audit_logs`.

Charts follow the project's dataviz conventions: one palette, consistent axes, readable
in both themes, and no chart junk. A chart that needs a legend to be understood usually
needs a different chart.

### 6.2 Buckets

Table-first with density that suits scanning: name, objects, size, visibility, created,
row actions (open, settings, generate credentials, delete). Creation is a dialog —
name (validated live against S3 naming rules *before* submission), region, access policy,
versioning (disabled and marked "coming in a later release", since A reserved it),
CORS, quota. Advanced fields stay collapsed.

Deleting a non-empty bucket is refused with a message that says how many objects are in
the way and offers to open it — not a bare "BucketNotEmpty".

### 6.3 File browser

The centrepiece, and the screen most likely to be judged. Finder-like:

Breadcrumb navigation over `/`-delimited prefixes; folders synthesised from the delimiter
(there are no real directories, and the UI should never imply otherwise in a way that
misleads on cost or semantics). List and grid views. Multi-select with shift-range and
`Cmd/Ctrl` toggle. Context menu. Bulk delete and bulk download. Debounced search. Sort by
name, size, date, type. Filter by content type.

**Virtualised rendering** via `@tanstack/react-virtual` — a bucket with 50,000 objects in
one prefix must scroll at 60fps, and paginate against `ListObjectsV2` continuation tokens
rather than loading everything.

Keyboard: arrows to move, `Enter` to open, `Space` to preview, `Delete` to delete,
`Cmd+A` to select all, `/` to focus search.

### 6.4 Upload

Drag-and-drop anywhere in the browser pane, plus folder upload where the browser supports
`webkitdirectory`. Files above `S3_MULTIPART_THRESHOLD` are uploaded via **real multipart
uploads through the S3 API**, with parts issued in parallel and presigned per part — the
same code path an external client would take. Dogfooding the data plane here means the
dashboard cannot drift from the public API.

Per-file progress, aggregate progress ("Uploading 4 of 18 · 2.3 GB / 8.2 GB · ~4 min
remaining"), cancel, retry on failure, and resume of an interrupted multipart upload
where the parts survive.

Failure messages name the file and the cause: *"Connection interrupted while uploading
quarterly-report.pdf"* with Retry and Cancel. Never "Something went wrong."

### 6.5 Preview and object details

Preview images, PDF, text, JSON, CSV, Markdown, video, audio — video and audio via range
requests, which is the visible payoff of §10 in spec A.

Untrusted content is rendered defensively: HTML and SVG are **never** rendered inline as
markup. They are shown as source text or offered for download. Rendering user-uploaded
SVG in the dashboard origin is a stored-XSS vector, and "it's my own file" is not a
threat model.

Unsupported types show type, size, last modified, ETag, and metadata instead of a broken
preview.

Details panel: name, full key, size, content type, created, modified, ETag, SHA-256,
storage backend, custom metadata (editable where safe), and access. Actions: download,
copy URL, generate temporary link, delete.

### 6.6 Access keys

List with name, access key ID, permissions, last used, created, status. Creation shows
the secret **once**, in a dialog that makes the finality obvious, with copy buttons and a
pre-filled `aws configure` snippet. Rotate, revoke, and delete are distinct operations
with distinct consequences, and the UI explains the difference rather than assuming the
user knows it.

Secrets never appear in logs, Inertia props after creation, or error messages.

### 6.7 Connect your client

The page that makes the product usable. Endpoint, region, and a key selector, then
copy-ready configuration for AWS CLI, Laravel/Flysystem, boto3, JavaScript, rclone, and
Cyberduck — each pre-filled with this installation's real endpoint and the selected key
ID. A downloadable rclone config file.

Every snippet on this page is generated from the same source as the interop tests, so a
snippet that doesn't work is a failing test.

### 6.8 Usage and Activity

Usage: storage, objects, requests, and bandwidth over time, broken down per bucket, with
CSV export.

Activity: a filterable audit table — timestamp, actor, action, bucket, object, IP, user
agent, result — with filters on action, actor, bucket, result, and date range, backed by
the indexes from spec A §5.1. Denials and signature failures are visually distinct from
successes, because that is what someone scanning this table is looking for.

## 7. Design system

The starter kit already provides Button, Input, Select, Dialog, Sheet, Dropdown, Tooltip,
Tabs, Badge, Card, Avatar, Checkbox, Separator, Skeleton, Sonner toasts, Breadcrumb, and
Sidebar. Reuse them; do not reimplement.

New components: `DataTable` (`@tanstack/react-table`), `EmptyState`, `Progress`,
`FileIcon`, `StorageChart` (Recharts), `ActivityItem`, `CommandPalette`, `ConfirmDialog`,
`CopyField`, `CodeBlock`, `StatTile`, `UploadTray`.

Business logic stays out of components. Formatting, permission evaluation, and key
manipulation live in `resources/js/lib/`; components render.

## 8. Data flow

Inertia pages receive typed props. All backend calls go through **Wayfinder-generated**
functions from `@/actions` and `@/routes` — no hardcoded URL strings anywhere in the
frontend.

Expensive panels (charts, activity feeds, bucket statistics) use Inertia v3
`Inertia::defer()` with animated skeletons, so navigation is instant and the page fills
in. Deferred props without a designed loading state are a worse experience than blocking,
so every one gets a skeleton.

Uploads bypass Inertia entirely and talk to the S3 API directly (§6.4).

## 9. New dependencies

`recharts`, `@tanstack/react-table`, `@tanstack/react-virtual`. Approved by the user on
2026-08-04. No new PHP dependencies.

## 10. Accessibility

Target WCAG 2.2 AA. Semantic HTML first, ARIA only where semantics fall short. Every
interactive element reachable and operable by keyboard with a visible focus ring. Dialogs
trap focus and restore it on close. No information conveyed by colour alone — status
carries an icon or a label as well as a hue.

Contrast is **measured**, not eyeballed, in both themes, including chart series against
their backgrounds. This is the gate on the palette in §2.2.

## 11. Responsive behaviour

Desktop is primary. Tablet is fully usable. On mobile: navigation, bucket list, basic file
management, upload, download, and activity. The desktop file manager is not crammed into
a phone — the mobile file view is a purpose-built list, because a virtualised
multi-select grid with a context menu is not a phone interface.

## 12. Testing

Pest feature tests for every controller: setup wizard (including that it cannot re-run),
bucket CRUD, object listing, access key lifecycle (including that secrets are shown once
and never re-served), usage and activity queries, and authorisation — a member of team A
must not reach team B's buckets through any dashboard route.

Pest browser tests for the flows that only exist in the browser: upload, file browser
navigation and selection, and the command palette.

`npm run lint:check`, `npm run format:check`, and `npm run types:check` all clean.

## 13. Definition of done

1. Landing page complete, both themes, responsive, Lighthouse accessibility ≥ 95.
2. First-run wizard completes on a fresh database and refuses to re-run.
3. Every screen in §6 built, with designed empty, loading, and error states — no screen
   ships with an unstyled empty state.
4. A 1 GB file uploads through the dashboard via genuine multipart, with accurate
   progress, and survives a cancel and a retry.
5. Cross-tenant access is proven impossible by test, not by inspection.
6. Contrast measured and passing in both themes.
