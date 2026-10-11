# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repo is

A **self-hosted fork** of [Leantime](https://github.com/Leantime/leantime) (open source project management system), maintained by one team for their own use.

- **Branch strategy**: `dev-self-hosted` is the working branch (fork-only commits on top of upstream). Since the 2026-10-11 upgrade, the fork **does track upstream via merges**: `master` mirrors `upstream/master` (synced to v3.11.0+10, `b0268c95b`), and `upgrade/3.11.0` is the merge-and-validate branch that upgrades `dev-self-hosted` from the old 3.10.0-era base to 3.11.0 (see `.docs/upgrade-1011/README.md`; merge into `dev-self-hosted` pending owner sign-off). Treat `dev-self-hosted` as the source of truth once the upgrade lands.
- **Version**: Leantime **3.11.0** on `upgrade/3.11.0` (`app/Core/Configuration/AppSettings.php` → `$appVersion`; also `package.json`); `dev-self-hosted` is still 3.9.8 until the upgrade merges. The app version is read at *makefile parse time*, so bumping it changes build artifact names.
- **Upstream**: `upstream` remote = `Leantime/leantime`; `origin` = `GavinCnod/leantime-forked`.
- **Language**: the team works in Chinese. `docs/` and `.docs/` hold the Chinese operating guides, glossary, and design records — see [Team docs](#team-docs-and-chinese-documentation).

## Fork-specific surface

Everything below is what this fork adds on top of stock Leantime. Prefer these over upstream patterns.

### Production deploy stack (`deploy/`)

Self-hosted container deployment, **PostgreSQL 16 + Redis 7 + Caddy** (not the upstream MySQL default).

| File | Purpose |
|---|---|
| `docker-compose.prod.yml` | db (Postgres) + redis + app + caddy. MySQL swap documented in a trailing comment block. |
| `Dockerfile.prod` | 2-stage: `node:20-alpine` builds assets (`npx mix --production`), `php:8.3-fpm-alpine` runs nginx+php-fpm+scheduler under supervisord. |
| `entrypoint.sh` | Boot: symlink persistence dirs, sync built-in plugins, auto-enable plugins, chown, `exec /start.sh`. |
| `plugin_ensure.php` | Idempotent plugin install/enable by composer package name. Called by the entrypoint with retry (30×2s). |
| `Caddyfile` | TLS terminating reverse proxy → `app:8080`. |
| `backup.sh` | DB dump + userfiles tarball, gzip, retention prune (default 14 days). |
| `.env.prod.example` | Template for `deploy/.env`. |
| `tencent-lighthouse.md`, `railway.md` | Step-by-step deploy guides for the two target hosts. |

Key operational facts:

- **The prod entrypoint does NOT run migrations.** Install and upgrades are user-driven: `/install` on first boot, and the `Updated` middleware prompts on version change.
- **Secrets**: generate with `openssl rand -hex 32` (session) / `-hex 24` (DB, Redis). `/start.sh` also supports `*_FILE` env vars for Docker secrets.
- **Persistence**: named volumes `userfiles`, `public_userfiles`, `plugins`, `logs`. On Railway, single-volume mode via `LEAN_PERSIST_ROOT=/data` (symlinks the four dirs into `/data`).
- Deploy flow: `cd deploy && cp .env.prod.example .env` → edit → `docker compose -f docker-compose.prod.yml up -d --build`. Update: `git pull` then `up -d --build app`.
- Prod images do not install `ext-intl` — use the intl-free `Leantime\Core\Support\Format::number()` for number display (upstream 3.11; replaces the retired fork helper `format_number()`).

### Plugins this fork ships (`app/Plugins/`)

`app/Plugins/` is a git submodule upstream, but this fork replaced it with **ordinary tracked directories** (`.gitmodules` now only declares `CostTracking`).

- **`CostTracking`** — git submodule → `https://github.com/GavinCnod/mr-leantime-costtracking.git` (currently v1.0.1). Adds per-ticket cost tracking. Registers **FQCN class-event listeners** (`TicketCreated`, `TicketDeleted`, `TicketListFilter`) — the reference example for consuming the class-based event API. Depends on core dispatching only *after* its permission checks, so it inherits authorization rather than declaring its own.
- **`TeamDocs`** — in-repo folder plugin (`leantime/teamdocs`, v1.1.0). Adds team documents to the Help dropdown as modal dialogs. Listens on `leantime.*.insideHelpMenu` and renders `teamdocs::helpMenu`. Documents live in `Resources/docs/*.md` and are rendered through the core `MarkdownRenderer`.
- The prod Dockerfile has a **submodule fallback**: if `app/Plugins/CostTracking/composer.json` is missing (e.g. CI clone without submodules) it clones `COSTTRACKING_REF` (default `main`) and hard-fails if still absent.

Plugin mechanics worth knowing:

- Discovery scans `app/Plugins/*/` for directories; a plugin is identified by `composer.json`. Marketplace plugins may be phars. The main class name is **derived, not configurable**: `Leantime\Plugins\{Studly(folder)}\Services\{Studly(folder)}`.
- **Permission classes are globbed from `app/Plugins/*/Permissions/*Permissions.php`** — path convention, no registration. Neither plugin in this repo declares one yet, but plugin routes *are* wrapped by the permission middleware, so the mechanism is live.
- Plugin `register.php` files are discovered at boot for system plugins, and later (after session/install/update checks) for user-enabled plugins.

Installing a plugin (`php bin/leantime plugin:install|enable|disable|remove`):

- The argument is the **composer package name**, never the folder name — `mindrose/costtracking`, not `CostTracking`. Passing the folder name fails with `Invalid plugin name`. Add `--no-interaction` in scripts. On shell-less platforms set `LEAN_PLUGINS_ENABLE=name1,name2` instead (this is what the prod entrypoint uses).
- `createPluginFromComposer()` **requires all five** of `name`, `description`, `version`, `homepage`, `authors` — they are raw array reads, so a missing key fails the install. (`type: leantime-plugin` is conventional but **not** validated in PHP.)
- Metadata is read from `composer.json` **only at install/discovery time**; afterwards the UI reads `zp_plugins`. Editing `composer.json` on a deployed instance changes nothing — use the plugin's own sync command (CostTracking ships `plugin:costtracking:sync-metadata`).

Three plugin-authoring traps — all fail **silently**:

1. **Template events need the `leantime.*.` wildcard.** `@dispatchEvent('beforeEndRightColumn')` arrives with its full path prefix (e.g. `leantime.domain.tickets.templates.submodules.additionalfields.beforeEndRightColumn`). Class-based events/filters must **not** use the prefix — those are matched on the FQCN.
2. **Template-event callbacks receive exactly one argument** (the payload array). Writing `function ($event, $wrap)` with `$payload = $wrap[0] ?? []` renders nothing forever, with no warning. Regression coverage: `EventDispatcherCharacterizationTest`.
3. **Language `.ini` files must be flat with no `[section]` header.** Core uses `parse_ini_file(..., false)` but the plugin loader uses `parse_ini_file(..., true)`; a section produces a nested array, so `__('myplugin.key')` fails and the literal key string is shown.

Also: language arrays are cached for 7 days, and plugin Blade namespaces are cached in `storage/framework/viewPaths.php` — clear both when new plugin UI doesn't appear.

Note that `app/Plugins` was a git submodule upstream; this fork removed that gitlink so the directory is ordinary tracked content (only `CostTracking` is still a submodule). Don't "restore" it.

### Team docs and Chinese documentation

- `docs/` (tracked, user-facing guides): `leantime-zh-guide.md` (operator guide), `leantime-zh-glossary.md` (EN↔ZH glossary), `cost-tracking-rules.md` (team expense-recording rules), `designs/`.
- `.docs/` (design/development records, dated folders): `initial-0910/` holds a **full Chinese translation of an older CLAUDE.md** (`CLAUDE.zh-CN.md`) plus 12 architecture chapters — genuinely useful, but **written against an older revision and partially stale**; cross-check against the code. Also `plugins-0914/` (CostTracking), `teamdocs-0919/` (TeamDocs), `bug-fix-0918/`, `ideas-0917/`.
- **Three files are duplicated between `docs/` and `app/Plugins/TeamDocs/Resources/docs/` and must stay byte-identical**: `cost-tracking-rules.md`, `leantime-zh-guide.md`, `leantime-zh-glossary.md`. The runtime image can't read root `docs/` (`.dockerignore` excludes it), so TeamDocs reads its own packaged copy; `Resources/docs/README.md` is plugin-only and has no counterpart. Edit the source in `docs/`, then run `php scripts/check-teamdocs-docs.php --sync` (without `--sync` it just reports drift). `TeamDocsResourceSyncTest` asserts the SHA-256 match, so drift fails CI.
- `MarkdownRenderer` (`app/Core/Support/MarkdownRenderer.php`, league/commonmark) is the **shared** Markdown→HTML renderer used by TeamDocs; styling lives in core's `markdown.css` in the main bundle. Reuse it rather than adding another Markdown dependency.

### Number formatting: `Format::number()`

Since the 3.11 upgrade, use `Leantime\Core\Support\Format::number()` (`app/Core/Support/Format.php`) for number display. It wraps `Illuminate\Support\Number::format(...)` when `ext-intl` is loaded and falls back to an intl-free `number_format()` implementation otherwise — the shipped Docker images have no intl, so **never call `Number::format()` directly**. (The fork's own `format_number()` helper was retired in the 3.11 merge; history in `.docs/upgrade-1011/README.md`.)

## Core systems

Four systems carry most of the architectural weight. Read these before touching controllers, services, or events.

### Permission engine (`app/Core/Auth/Permissions/`)

**This is the current authorization model.** Upstream Leantime authorizes with role-name comparisons (`$login::userIsAtLeast('manager')`), which still appears ~198 times in legacy code; new code must use the permission engine instead.

A domain declares a **vocabulary of verbs** (no role information) in `app/Domain/{Domain}/Permissions/{X}Permissions.php`:

```php
final class TicketsPermissions implements ProvidesPermissions
{
    public const VIEW = 'tickets.view';
    public const EDIT = 'tickets.edit';
    // ...
    public function domain(): string { return 'tickets'; }
    public function permissions(): array {
        return [new Permission(self::VIEW, 'View to-dos'), /* ... */];
    }
}
```

Discovery is by **path glob**, not registration — `app/Domain/*/Permissions/*Permissions.php` and `app/Plugins/*/Permissions/*Permissions.php`. 18 providers exist. Class name and key prefix need not match (e.g. `GoalcanvasPermissions` declares `goals.*`).

Enforcement is one method — `PermissionEnforcer::enforce()` — reached from three entry points: `Frontcontroller::executeAction()` (legacy convention controllers), the `CheckPermissions` middleware (`app/Core/Auth/Permissions/CheckPermissions.php`, applied globally by `RouteLoader` to all domain *and* plugin routes), and `Jsonrpc::executeApiRequest()`.

`#[RequiresPermission(permission, projectIdParam: ?, global: ?, entityScoped: ?)]` is a **method attribute** (`Attribute::TARGET_METHOD`). Semantics:

| Usage | Enforcement |
|---|---|
| No attribute | **Complete no-op** — no session/DB/permission-engine access at all. |
| `#[RequiresPermission(P)]` | Project = `session('currentProject')` (may be null → capability-only check). |
| `projectIdParam: 'x'` | Resolve project from `$params['x']`; strict positive int. If unresolvable **and the parameter is mandatory**, **fail closed (deny)**. |
| `global: true` | Check against the global role; no project. Use for company-wide capability screens. |
| `entityScoped: true` | **The enforcer returns immediately and performs no check.** It is a *declared-coverage marker*, not a check. |

`entityScoped: true` is the one that bites:

- Because it short-circuits **before** `global`, combining `global: true, entityScoped: true` makes the attribute inert — the *global* behavior then comes solely from the permission's own `projectScoped = false` metadata.
- The method body **must** call `$this->authorize()` against the project of the **loaded entity** (the enforcer cannot see it). Load the entity first and authorize against *its* project; fail closed (return `false`/`null`) when the id doesn't resolve. This is the same fail-closed discipline as the [shared canvas tables](#shared-canvas-tables-idor-caution).

Resolving the current user:

- In services: `$this->authorize($perm, ?int $projectId)`, `$this->can(...)` (from `Core/Domains/BaseService`). These always enforce regardless of audit mode.
- In Blade: `@can('tickets.edit')` — routed through the Gate bridge (`PermissionServiceProvider::registerPermissionGate()`), which only resolves dotted abilities present in the synced catalog.
- In legacy templates / anywhere a boolean is handy: `can('tickets.edit', $projectId)` (from `app/helpers.php`).

Roles are **incremental grants unioned up a hierarchy** — `readonly(5) < commenter(10) < editor(20) < manager(30) < admin(40) < owner(50)` — defined in `DefaultRolePermissions::ROLES` / `GRANTS`. Roles are **project-scoped**: `RoleResolver::effectiveRoleForProject()` resolves the user's role *in that project*. Manager+ keep their global role everywhere. Store: `zp_roles` / `zp_permissions` / `zp_role_permissions`.

Critical gotchas:

- **Run `php bin/leantime permissions:sync` after adding or changing permission classes.** Discovery and the grant map are cached `rememberForever` on the `installation` store, so a newly shipped domain's permissions are **invisible until synced**. An unsynced key is treated as *global-scoped* rather than deny-by-default, and the Gate bridge defers (returns `null`) instead of denying — silent under-enforcement, not an error.
- `--seed` re-grants built-in role defaults **additively** (never revokes, so admin edits survive); `--prune` is the only destructive path.
- Role/permission writes do **not** auto-invalidate: callers must `PermissionService::flushCache()`. Plugin enable/disable should call `PermissionRegistry::flush()`.
- Denials throw `AuthorizationException` → Web 403 / RPC `-32001`. Set `LEAN_PERMISSIONS_ENFORCE=false` for audit-only mode (logs `[permissions:audit]` instead of blocking; default `true`).
- **There is no admin UI for role→permission editing yet** — custom roles are reachable only through `PermissionRepository`.

### Event system (`app/Core/Events/`)

Two parallel mechanisms, WordPress-hook style. Both coexist; **new work should prefer the class-based form.**

**Class-based events** implement `LeantimeEvent` and use `InteractsWithEvents`:

```php
final class TicketUpdated implements LeantimeEvent
{
    use InteractsWithEvents;
    public function __construct(
        public readonly ?int $ticketId = null,
        private readonly ?string $legacyHook = null,   // TEMPORARY migration-window discriminator
    ) {}
    public function legacyHooks(): array {
        if ($this->legacyHook === null) return [];
        return ['leantime.domain.tickets.services.tickets.'.$this->legacyHook.'.ticket_updated'];
    }
}
```

Dispatch with named args — `TicketCreated::dispatch(ticketId: $id, legacyHook: __FUNCTION__)`. Class **filters** implement `LeantimeFilter` and return the payload: `$tickets = TicketListFilter::dispatch(tickets: $tickets, legacyHook: __FUNCTION__)`.

Listen with the FQCN; the closure receives the **typed object**:

```php
EventDispatcher::add_event_listener(TicketCreated::class, function (TicketCreated $e) { /* $e->ticketId */ });
```

**Compatibility mechanism** (why both styles keep working): `EventDispatcher::dispatch_event()` detects `LeantimeEvent` and bypasses all string machinery. `executeClassEventHandlers()` then runs two phases — (1) FQCN listeners get the bare typed object; (2) for each name in `legacyHooks()`, it rebuilds the legacy payload via `get_object_vars()` (so only *public* properties cross over — the `private $legacyHook` discriminator is excluded by construction) and runs the **exact same code path** as string events. Wildcard and string listeners therefore still fire, with today's array payload.

Naming rules:

- **Class names are `{Entity}{Verb}` / `{Thing}Filter`, and the verb must come from the `EventVerb` enum** (18 past-tense cases: `Created`, `Updated`, `Deleted`, … — not `Changed`/`Edited`/`Saved`). `tests/Unit/app/Core/Events/EventVocabularyTest.php` globs `app/Domain/*/Events/*.php` and `app/Core/*/Events/*.php` and enforces this on classes implementing `LeantimeEvent`/`LeantimeFilter` only.
- The legacy string name is **not derived** — it is hardcoded in `legacyHooks()` with the emit-site method spliced in. This is deliberate: every historical emit site passes its own `__FUNCTION__` so exact subscribers fire under the right conditions and wildcard subscribers fire **once**, not N times. Never statically list all historical emit sites.
- **Do not combine `InteractsWithEvents` with Laravel's `Dispatchable`** — both define `dispatch()`, and Laravel's bypasses the `LeantimeEvent` fast path.

String events (legacy, still dominant) are auto-named from class namespace + calling method + raw hook string, e.g. `leantime.domain.tickets.services.tickets.updateTicket.ticket_updated`. `DispatchesEvents` provides `self::dispatch_event()` / `self::dispatch_filter()`. Pattern matching supports `*`, `?`, and `{RGX:pattern:RGX}`.

**Class-based events currently in production** (11 total): Tickets (`TicketCreated`, `TicketUpdated`, `TicketDeleted`, `TicketStatusUpdated`, `StatusLabelsUpdated`, `MilestoneCreated/Updated/Deleted`, plus filters `TicketListFilter`, `TodoWidgetTasksFilter`) and Blueprints (`CanvasItemUpdated`). Note `Files/Events/FileUploaded.php` is a **plain Laravel stub, not** a `LeantimeEvent` — don't copy it as a template.

Listener registration lives in `register.php`; only 8 domains have one (Auth, CsvImport, Help, Install, Notifications, Plugins, Queue, Reports). Not every domain needs one.

### HTMX architecture

The async/DOM-update layer. **Use HTMX for data loading and refreshes; use JS only for interactivity** (editors, drag-and-drop). Do not add jQuery AJAX for new async work.

- HxControllers live in `app/Domain/{Domain}/Hxcontrollers/` (13 domains, 26 controllers — plus `app/Plugins/TeamDocs/Hxcontrollers/`) and extend `Core\Controller\HtmxController`. URL convention: `/hx/{module}/{controller}/{action}`. Templates go in `Templates/partials/` — `partials/` is reserved for non-reusable, page-specific fragments and HxController `$view` targets.
- DI goes through `init()`, **not** `__construct()`. Action methods are named semantically, not by HTTP verb.
- Event enums: per-domain `app/Domain/{D}/Htmx/Htmx{D}Events.php` for **data events** (`lt:{domain}:{entity}.{verb}`, past tense), and the single core `Core\Events\Htmx\HtmxUiEvents` for **UI commands** (`lt:ui:*` — notify, modal-close). Plugins reuse the core UI enum; never mint their own `lt:ui:*`.
- Emit with `$this->tpl->emit(...)`; consume declaratively with `hx-trigger="lt:tickets:ticket.updated from:body"`.
- **Migration window**: legacy DOMAIN event strings (`ticket_update`) are bidirectionally dual-emitted via `HtmxEvents::LEGACY_ALIASES`. UI command events are deliberately **not** aliased — JS listens on each legacy name directly, so aliasing would double-fire (double growl / multiple modal close).
- Prefer `<x-global::hx :for="...HxController::class">` over hand-written `hx-get`/`hx-trigger`. Declaring the contract on an `HxComponent` (`listensTo()`/`emits()`) means the emit side and listen side reference the same enum and cannot drift. Note how early this pattern is: there is exactly **one** `HxComponent` subclass today (`Tickets/Hxcontrollers/Subtasks`) and one mount — treat it as a new convention, and expect most components to still be anonymous Blade.

PHP enums can't implement `__toString` — use `->event()` / `->value` in PHP; Blade `{{ Events::Case }}` renders the value via Laravel's `e()`.

### WorkStructure (`app/Core/WorkStructure/`)

A meta-model for how Leantime entities compose and map **across structures** — e.g. a Logic Model output "generates" a Project milestone. It lives in Core (like Plugins and Auth) because it governs domains rather than being one.

Registration entry point is `StructureRegistry`: `register(title, type, elements, relationships): int` and `registerMappings(sourceId, targetId, mappings)`. It is idempotent, caches forever, and degrades to a no-op (`return 0`) when `zp_work_structures` doesn't exist yet — so it is safe to call from a provider `boot()` during install. Tables: `zp_work_structures`, `zp_work_structure_elements`, `zp_work_structure_relationships`, `zp_work_structure_mappings`.

**Status — infrastructure plus one seed.** `WorkStructureServiceProvider::boot()` seeds a built-in "Project" structure (milestone/task/goal). Beyond that, **no domain and no plugin in this repo calls `register()` or `registerMappings()`**, and `MappingService` / `WorkStructureService` have zero consumers outside `app/Core/WorkStructure/`. `ElementDefinition::$domainReference` is stored but never resolved or validated. Its three events are plain Laravel-style (`Dispatchable`), not `LeantimeEvent`, and nothing listens to them. Treat this as an unexercised extension point.

## Architecture reference

### Boot sequence

1. `public/index.php` → helpers, autoloader, `bootstrap/app.php`
2. `bootstrap/app.php` creates `Leantime\Core\Application`, binds HttpKernel / ConsoleKernel / ExceptionHandler / IncomingRequest
3. `Bootloader::getInstance()->boot($app)` routes to HttpKernel or ConsoleKernel
4. HttpKernel bootstrappers: LoadEnvironmentVariables → **LoadConfig** (custom) → HandleExceptions → RegisterFacades → RegisterProviders → BootProviders
5. Middleware pipeline → routing (Laravel routes first, `Frontcontroller` fallback)

### Config

Config is **not** standard Laravel. A custom loader (`app/Core/Bootstrap/LoadConfig.php`) makes `Environment` (not Laravel's `Repository`) the config repository and reads `app/Core/Configuration/laravelConfig.php` — **not** `config/*.php`.

- **All** Laravel config (database, cache, session, auth, …) lives in the single `laravelConfig.php`. `artisan vendor:publish` will **not** work correctly.
- Priority: env vars > `.env` > PHP config file > `DefaultConfig` defaults.
- `#[LaravelConfig('dotted.key')]` attributes on `DefaultConfig` properties map to Laravel config.
- User-editable settings belong in `config/sample.env` under a `LEAN_*` name (note: `sample.env`, **not** `.env.sample`).

### Routing

Two systems:

1. **Laravel routes** — `routes.php` per domain, loaded by `RouteLoader` (only 5 exist: Blueprints, Files, Projects, Setting, Users). All domain and plugin routes are wrapped in the `CheckPermissions` middleware group, so `#[RequiresPermission]` is honored without per-route `can:` declarations. **Prefer this for new code.**
2. **Frontcontroller** (legacy, convention-based, still handles most requests):
   - `/module/action` → `Domain\{Module}\Controllers\{Action}::get()|post()`
   - `/module/action/id`, `/module/action/id/method`
   - `/hx/module/action` → `Domain\{Module}\Hxcontrollers\{Action}`
   - Resolution order: Domain Controllers → Domain Hxcontrollers → Plugin Controllers → Plugin Hxcontrollers

**Controller style is now uniform**: `get($params)` / `post($params)` returning a `Response` (145 of 159 domain controllers). The old single-method `run()` pattern has been fully removed — do not reintroduce it. A note in `Frontcontroller::getValidControllerCall()` warns that the route cache can outlive a deploy, so it self-invalidates on mismatch.

Request types are auto-detected: `IncomingRequest`, `ApiRequest` (API key / bearer helpers), `HtmxRequest` (boosted, target, trigger name).

Middleware order (see `HttpKernel.php`): TrustProxies → StartSession → Installed → Updated → LoadPlugins → InitialHeaders → AuthCheck → AuthenticateSession → RequestRateLimiter → HandleCors → ValidatePostSize → TrimStrings → ConvertEmptyStringsToNull → SetCacheHeaders → Localization → CurrentProject. A **second pipeline** then runs plugin-registered middleware before dispatch.

Rate limiting applies **only to login/signup/invite POSTs and to `/cron`, `/api/jsonrpc`, and `/mcp` requests** — ordinary page views take an early return and are not limited. The key is `ratelimit-{ip}-{userId}`, with API/MCP/general sharing one counter: login 20/min (`…:loginAttempts`), signup + invite POSTs 5/min per IP (`…:signup`), API 120/min, MCP 300/min, general 2000/min — overridable via `LEAN_RATELIMIT_*`. Note **MCP gets its own 300/min budget (2.5× the API limit)** because agentic clients legitimately burst parallel tool calls; don't assume the API ceiling covers it.

Beware a trap when reading any setting here: `DefaultConfig`'s property values are only **fallbacks**. `Environment` derives each property's env var name mechanically (`LEAN_` + snake_case of the property), and a value present in the environment or `config/.env` wins. `config/sample.env` is a **template that gets copied to `config/.env`**, not a runtime file — but where its documented value differs from `DefaultConfig` (e.g. rate limits, where the class says 10000/100/20/300 but `sample.env` ships 2000/120/20/300), an installed instance behaves per `.env`. When the two disagree, trust `.env` for runtime behavior.

### Data layer

- Repositories extend `Leantime\Core\Db\Repository`; they mix raw SQL with Laravel Query Builder depending on vintage. `dbcall()` wraps execution with events.
- Models are plain public-property data structures (no ORM annotations, no validation); some use `#[DbColumn('name')]` for column mapping.
- `Core/Db/Db.php` wraps Laravel's `DatabaseManager`. `Core/Db/DatabaseHelper.php` is the **cross-database compatibility layer** — it has explicit `mysql` / `pgsql` / `sqlsrv` branches for string aggregation, date formatting, upserts, and column types. **Any query that must run on PostgreSQL has to go through these helpers**, which matters here because production is Postgres (this fork has already fixed several Postgres-only report bugs).
- **PostgreSQL folds unquoted identifiers and aliases to lowercase.** Column names Leantime uses are camelCase (`ticketId`, `actualCost`), so raw SQL written for MySQL silently misbehaves on Postgres — double-quote the identifiers and use lowercase aliases. This is the single most common source of "works in dev, breaks in prod" here.
- Tables use a `zp_` prefix.
- **Doctrine migration is a stated future direction** (repositories/entities/SQL → EntityManager/DQL/annotations). Nothing in this fork has started; don't refactor toward it unasked.

### Shared canvas tables (IDOR caution)

`zp_canvas` and `zp_canvas_items` are shared across **every** canvas type with one id sequence, so a bare-id repository read/write can land on a *foreign* canvas type or project. Canvas-family service/repo methods must **fail closed** — resolve the entity's real project (return `false`/`null`/`0` when the id doesn't match the expected `type`/`box`) and authorize against that, never falling back to `session('currentProject')`. Wiki and Ideas are the established reference implementations.

### Template system

**The `.tpl.php` → Blade migration is complete** in the sense that no `.tpl.php` / `.sub.php` / `.inc.php` files remain — 342 Blade files, 0 of the legacy extensions. Two caveats:

- One template keeps the bare `.php` extension and is still rendered through the legacy path: `app/Domain/Ideas/Templates/boardDialog.php`, loaded by `Ideas/Controllers/BoardDialog.php` via `displayPartial('ideas.boardDialog')`.
- Legacy helpers survive with a little residual usage: `$tpl->get(` still has 19 call sites (16 in Blade — mostly Logicmodelcanvas and Ideas). `displaySubmodule()` has zero call sites and is effectively dead.

- `app/Views/Templates/layouts/` — app, entry, blank, error, registration
- `app/Views/Templates/components/` — shared components, with `COMPONENTS.md` as the **maintained single source of truth** for the componentization effort (taxonomy, prop vocabulary, migration rules)
- `app/Views/Templates/sections/`, `app/Views/Composers/`

Component syntax: `<x-global::{name}>` → `app/Views/Templates/components/{name}.blade.php`; `<x-{domain}::{name}>` → `app/Domain/{Domain}/Templates/components/{name}.blade.php`. Anonymous components resolve automatically; class-backed ones (`app/Domain/{Domain}/View/Components/`) take precedence.

The componentization playbook (`COMPONENTS.md`) is worth reading before adding UI: components are introduced **no-op first** (byte-for-byte identical render, zero visual change), and the **prop API is the durable contract** while rendered CSS classes are the swappable implementation. Category-namespaced naming (`<x-global::{category}.{name}>`) and a shared camelCase prop vocabulary (`contentRole`, `state`, `variant`, `scale`, `position`, …) are defined there. Follow it rather than inventing props.

Rendering methods on `Template`: `display()`, `displayPartial()`, `displayFragment()` (HTMX), `displaySubmodule()`, `emptyResponse()`. HxControllers set `protected static string $view`; batch assignment uses `array_map([$this->tpl, 'assign'], array_keys($vars), array_values($vars))`.

### JSON-RPC API

`app/Domain/Api/Controllers/Jsonrpc.php`. Method routing: `leantime.rpc.{domain}.{method}` (4 segments) or `leantime.rpc.{domain}.{service}.{method}` (5 segments). It uses Reflection to match request params to service method parameters by name and to type-cast.

**Any public service method is callable** — the `@api` annotation (487 uses) is documentation only, not enforced. The RPC entry point resolves the *service* method, so `#[RequiresPermission]` there is enforced by the RPC path, not the controller's.

Auth: Leantime API keys (`x-api-key`, format `lt_{user}_{key}`, acts as a service account) or Laravel Sanctum bearer tokens (requires the AdvancedAuth plugin). The legacy REST-like controllers in `app/Domain/Api/Controllers/` are deprecated — new work goes through JSON-RPC.

### Auth and roles

Three guards: `leantime` (web session), `sanctum` (token), `jsonRpc` (API). Native LDAP and OIDC support, plus Laravel Socialite providers (Authentik, Auth0, Gitea, GitHub, GitLab, Google, Keycloak, Microsoft, Okta, PropelAuth, EduID, SAML2).

Each user has one client and a role; projects are visible to everyone, to the user's client, or to explicitly assigned users. Admins/owners see all projects. **For new authorization logic use the [permission engine](#permission-engine-appcoreauthpermissions), not role-name comparisons.**

### CLI commands

`php bin/leantime [command]` — commands live in `app/Command/` and are discovered by globbing that directory. Names come from the `#[AsCommand(name: ...)]` attribute and **often do not match the class name**:

| Command | Class |
|---|---|
| `system:update` | `UpdateLeantime` |
| `db:migrate` | `MigrateCommand` |
| `db:backup` | `BackupDbCommand` |
| `user:add` | `AddUserCommand` |
| `setting:save [key] [value]` | `SaveSettingCommand` |
| `permissions:sync [--seed] [--prune]` | `SyncPermissionsCommand` |
| `plugin:list\|enable\|disable\|install\|remove` | `*PluginCommand` |
| `cache:clearAll` | `ClearAll` |
| `language:clear` | `ClearLanguage` |
| `email:testemail` | `TestEmailCommand` |
| `event:check-listeners` | `CheckEventListeners` |
| `translations:check-unused` | `CheckTranslations` |
| `goals:backfillHistory` | `BackfillGoalHistoryCommand` |
| `auth:create-bearer-token` | `CreateBearerTokenCommand` |

Run `php bin/leantime` with no arguments or `list` to see the live set; guessing a name from the class name (e.g. `php bin/leantime migrate`) fails.

### Global helpers (`app/helpers.php`)

Beyond the helpers above (`Format::number()` is now a core class, not a helper):

- `format($value)` — formatting object for dates/values (`FromFormat::DbDate` default)
- `dtHelper()`, `CarbonImmutable` + `CarbonMacros` for all datetime work. DB dates are **UTC** in `YYYY-MM-DD HH:MM:SS`; frontend/user input is in the user's timezone and date format — `DateTimeHelper` parses common formats
- `can()`, `cast()`, `build()`, `do_once()`, `mix()`, `safe_unserialize()` (JSON-first, then `unserialize` with `allowed_classes: false`)
- `get_release_version()` / `get_domain_key()`

## Frontend

### Build

Laravel Mix 6 (Webpack 5), output `public/dist/` with version-stamped filenames. There are **no npm or composer scripts** — `package.json` has an empty `"scripts": {}` and `composer.json` has no `scripts` key — so invoke tools directly:

```bash
npx mix                 # dev build (with source maps)
npx mix --production    # production build
node generateBlocklist.mjs   # post-step both ways; extracts tw-* classes from app.css
```

`make build` / `make build-dev` wrap these and also clear caches. The `.eslint({fix: true})` config means **builds auto-fix lint**.

Bundles (all loaded on every page — no code splitting): `compiled-htmx`, `compiled-htmx-extensions`, `compiled-frameworks` (jQuery 3.7.1 + Bootstrap 2.x), `compiled-framework-plugins`, `compiled-global-component`, `compiled-editor-component` (TinyMCE 5.10.9, ~3.6MB alone), `compiled-calendar-component`, `compiled-table-component`, `compiled-gantt-component`, `compiled-chart-component`, `compiled-footer`, `compiled-app` (all of `app/Domain/**/*.js` by glob), plus `compiled-lottieplayer` and three `compiled-tiptap-*` entries (editor, toolbar, tests).

Per-domain JS chunking remains **commented out** in `webpack.mix.js` — a known, deferred improvement, not something to "fix" incidentally.

### JavaScript

Global `leantime` namespace with IIFE modules — `leantime.ticketsController = (function () { ... })();`. 33 JS files under `app/Domain/*/Js/`, mirroring backend layering (`{domain}Repository.js` / `{domain}Service.js` / `{domain}Controller.js`). Use `leantime.rpc(...)` for calls; when using `fetch`, include `credentials: 'include'` and `X-Requested-With: XMLHttpRequest`.

Bootstrap 2.x and jQuery are ancient but load-bearing — don't assume modern Bootstrap semantics.

### CSS

Three layers: third-party (Bootstrap 2, jQuery UI, Font Awesome), custom component CSS in `public/assets/css/components/` (plus LESS sources in `public/assets/less/`), and Tailwind 3.4 **with the `tw-` prefix** (base layer disabled to avoid Bootstrap conflicts). Tailwind is in active use (~67 templates) and its output ships as `app.{version}.min.css`; `node generateBlocklist.mjs` extracts the generated `tw-*` names into `blocklist.json` as a post-build step.

Use the design-token CSS variables rather than hardcoded values: `--accent1/2`, `--primary-color`, `--primary-font-color`, `--primary-background`, `--secondary-background`, `--layered-background`; `--base-font-size`, `--font-size-xs`…`xxxl`; `--box-radius*`, `--element-radius`, `--input-radius`; `--min-shadow`, `--regular-shadow`, `--large-shadow`, `--input-shadow`; `--zlayer-1`…`9`; `--glass-*`.

Themes in `public/theme/{default,minimal}/` with `theme.ini` + `css/light.css` + `css/dark.css`.

## Commands

### Setup

```bash
make install-deps-dev    # npm install + composer install
make install-deps        # production variant (--no-dev --optimize-autoloader)
make build-dev           # dev assets + blocklist
make build               # production assets + blocklist
make run-dev             # Docker dev server (port 8090)
make clear-cache         # clears bootstrap/cache, viewPaths, storage/framework caches
make get-version         # prints the version parsed from AppSettings.php
```

`make run-dev` starts: app `:8090`, MailDev `:8081`, phpMyAdmin `:8082` (leantime/leantime), S3Ninja `:8083`.

Manual setup: install deps → point the web server at `public/` → create the database → copy `config/sample.env` to `config/.env` and configure → visit `<localdomain>/install`.

### Quality

```bash
make phpstan             # static analysis — level 5 (.phpstan/phpstan.neon; excludes app/Plugins/*)
make test-code-style     # Pint, check only
make fix-code-style      # Pint, apply fixes
make codesniffer         # PHPCS (PSR12-based); codesniffer-fix to apply
```

Note: the **root `phpstan.neon` (level 0) is orphaned** — `make phpstan` uses `.phpstan/phpstan.neon` at **level 5**. `app/Plugins/*` is excluded from analysis.

### Tests

Codeception **5.3.2** (wraps PHPUnit). Two suites only: `Unit` and `Acceptance` (API tests are Cests under `tests/Acceptance/API/`, part of the Acceptance suite — there is no `Api` suite, so `make api-test`, which runs `codecept run Api`, matches nothing). Groups are declared with PHP 8 `#[Group('...')]` attributes.

`tests/Unit/TestCase.php` forces `cache.default=array`, `session.driver=array`, and an empty `database.default`, so **unit tests are configured not to need a DB**. Acceptance tests run against a containerized MySQL (`leantime-db`, database `leantime_test`) driven by `.dev/test.env`; they need the dev+test compose stack up (which the `make` targets do for you).

```bash
make unit-test           # docker: full Unit suite
make acceptance-test     # docker: full Acceptance suite (clean + build + run)
make api-test            # (matches no suite — see above)

# Single suite / file / method / group, inside the dev container:
docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml exec leantime-dev \
  php vendor/bin/codecept run Unit --steps
docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml exec leantime-dev \
  php vendor/bin/codecept run Unit tests/Unit/app/Core/Events/ClassEventDispatchTest.php
docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml exec leantime-dev \
  php vendor/bin/codecept run Unit tests/Unit/app/Core/Events/ClassEventDispatchTest.php:test_fqcn_closure_listener_receives_typed_event_object
docker compose --file .dev/docker-compose.yaml --file .dev/docker-compose.tests.yaml exec leantime-dev \
  php vendor/bin/codecept run Acceptance -g api --steps
```

Groups in use: `api`, `bearer-api`, `mcp`, `user`, `goals`, `wiki`, `ticket`, `timesheet`, `login`, `blueprints`. Watch for a bug in the suite: `tests/Acceptance/InstallCest.php` declares `#[Group('install, api')]` as **one comma-joined string**, and `Group::__construct(string ...$groups)` does not split on commas — so the intended `install` group does not exist and those Cests are reachable only via no group filter. Run `codecept build` after changing suite config. There is no `phpunit.xml`; `vendor/bin/phpunit` exists only transitively.

### CI (`.github/workflows/`)

Runs on push/PR to `master` and `*.*-dev`: `make build`, `make unit-test`, `make acceptance-test-ci`, `make bearer-api-test-ci`, `make phpstan`, and Pint — plus `composer audit` / `npm audit` / CodeQL on a weekly cron. `release-prepare.yml` and `release.yml` handle versioning and release artifacts (they call the Anthropic API to draft changelog entries). PHPCS is **not** in CI.

## Conventions

- **Layer enforcement**: controllers call services only, never repositories. Services call repositories. Services validate input and throw on failure. Watch for circular references when calling one domain service from another.
- **No backwards compatibility** unless explicitly requested — do not keep shims or legacy branches.
- **Error logging**: always `Log::error($exception)` via the Log facade; never `error_log()`.
- **Strict types** wherever possible, on parameters and returns. When you reach for an array, consider whether a model/DTO is warranted (see `Core/Domains/DTO.php`).
- **PhpDoc** on all classes and methods; keep it aligned when you change a signature. Public service methods intended for the JSON-RPC API should carry `@api`.
- **Caching**: Leantime uses the Laravel cache (file or Redis). Cache expensive operations. When Redis is available, check whether the admin selected it and load the Redis config from the relevant ServiceProvider rather than assuming.
- **Deploy-sensitive caches**: several caches (`permissionProviders`, `leantime.permissionMap`, `domainRoutes`, `workstructure.*`, Blade view cache) are `rememberForever` outside debug mode. A stale `storage/framework/views/*` is a known cause of "my template change didn't show up" in production — clear it before debugging further.

## Skill routing

When the user's request matches an available skill, invoke it via the Skill tool. When in doubt, invoke the skill.

Key routing rules:

- Product ideas/brainstorming → invoke /office-hours
- Strategy/scope → invoke /plan-ceo-review
- Architecture → invoke /plan-eng-review
- Design system/plan review → invoke /design-consultation or /plan-design-review
- Full review pipeline → invoke /autoplan
- Bugs/errors → invoke /investigate
- QA/testing site behavior → invoke /qa or /qa-only
- Code review/diff check → invoke /review
- Visual polish → invoke /design-review
- Ship/deploy/PR → invoke /ship or /land-and-deploy
- Save progress → invoke /context-save
- Resume context → invoke /context-restore
- Author a backlog-ready spec/issue → invoke /spec
