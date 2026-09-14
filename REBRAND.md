# REBRAND — Coolify → Xlaunch

**Product:** Xlaunch (Northlatch Labs LLC)
**Fork baseline:** upstream `coollabsio/coolify` @ `59eacc9ff8362e007104cd3549b3f4518ba70ff4`
**License:** Apache-2.0 (`LICENSE` retained byte-identical; attribution in `NOTICE.md`)

This document is the authoritative record of the rebrand sweep. It maps every
brand identifier change, records baseline vs. final occurrence counts, lists
every remaining brand string with a classification, and records the placeholder
registry and the bootstrap checklist required before Xlaunch can be deployed.

---

## 1. Brand mapping

Boundary-aware, case-sensitive, applied to text files only. Order of application
matters: `coollabsio` is replaced before `coollabs` (to avoid `northlatchio`),
and domains/emails are replaced before the bare brand tokens.

| From | To | Notes |
|---|---|---|
| `Coolify` | `Xlaunch` | title case |
| `coolify` | `xlaunch` | lowercase |
| `COOLIFY_` | `XLAUNCH_` | env-var prefix (applied via `COOLIFY` → `XLAUNCH`) |
| `coollabsio` | `northlatch` | org name (no-dot form) |
| `coollabs` | `northlatch` | lowercase |
| `Coollabs` | `Northlatch` | title case (8 occurrences) |
| `ghcr.io/coollabsio/coolify` | `ghcr.io/northlatch/xlaunch` | main image |
| `ghcr.io/coollabsio/coolify-helper` | `ghcr.io/northlatch/xlaunch-helper` | helper image |
| `ghcr.io/coollabsio/coolify-realtime` | `ghcr.io/northlatch/xlaunch-realtime` | realtime image |
| `coolify.io` | `northlatch.com` | domain (placeholder) |
| `coollabs.io` | `northlatch.com` | domain (placeholder) |
| `coolify.dev` | `northlatch.com` | domain (placeholder; 0 literal occurrences) |
| `hi@…` / `hello@…` / `support@…` (coolify/coollabs domains) | `hello@northlatch.com` | contact emails |
| `coollabsio/coolify` | `northlatch/xlaunch` | repo slug |

All other `coollabs`-published image paths (`ghcr.io/coollabsio/minio`,
`-example`, `-jean-server`, `-shoutrrr`, `-pocketbase`,
`-next-image-transformation`, `-maxio`) and GitHub slugs
(`github.com/coollabsio/coolify-examples`, `-docs`, `-coold`, `-jean`,
`-next-image-transformation`, `-minio`, `-private-app`) are renamed by the
org-name rule (`coollabsio` → `northlatch`) and the brand-token rules
(`coolify` → `xlaunch`). See §7 for the full placeholder registry.

### Protected / untouched

- `LICENSE` — retained byte-identical.
- Copyright/attribution statements — retained; enumerated in `NOTICE.md`.
- Third-party service references in `templates/` and `other/` (non-Coolify
  images such as `ghcr.io/windmill-labs/windmill`, `ghcr.io/umami-software/umami`,
  and third-party CDNs) — untouched; none contain a Coolify/Coollabs brand string.
- The four pre-drafted business files keep Coolify/Coollabs mentions only in
  attribution/legal/provenance contexts (`NOTICE.md`, `README.md`,
  `docs/business/REVENUE_MODEL.md`, `docs/business/ENTERPRISE_ROADMAP.md`).

---

## 2. Baseline vs. final occurrence counts

| Pattern | Baseline | Final (outside exempt) | Final (exempt attribution) |
|---|---|---|---|
| `coolify` (case-insensitive) | 6082 occurrences / 899 files | **0** | 10 occurrences (4 files) |
| `coollabs` (case-insensitive) | 932 | **0** | 3 occurrences (3 files) |
| `COOLIFY_` | 533 | **0** | 0 |
| `coollabsio` | 343 | **0** | 3 occurrences (3 files) |

> Baseline `coolify`/`COOLIFY_` figures are the ones supplied in the task brief;
> `coollabs`/`coollabsio` were measured directly with
> `grep -rio <pattern> --exclude-dir=.git .`. Final figures were verified with
> `grep -rniI` (case-insensitive) and `grep -rnI` over the whole tree excluding
> `.git`.

**Reconciliation note.** 6,010 case-insensitive `coolify` occurrences were
rewritten in-place by the sweep; 72 further occurrences lived in the upstream
`README.md`, which was overwritten by the pre-drafted README; and 10 occurrences
were newly introduced by the pre-drafted business files as attribution. Net:
`6082 = 6010 rewritten + 72 removed (README) + 0 leftover` and the working tree
carries `6010 rewritten + 10 attribution`.

---

## 3. Per-pattern replacement counts (the sweep)

Counts are exact string substitutions performed by the sweep script
(`/tmp/rebrand_sweep.py`); each count is the number of literal occurrences
rewritten.

| Pattern | Occurrences rewritten |
|---|---|
| `coolify` (lowercase) | 3343 |
| `Coolify` | 1276 |
| `COOLIFY` (→ `XLAUNCH`, incl. 533 `COOLIFY_`) | 545 |
| `coolify.io` | 846 |
| `coollabs` | 537 |
| `Coollabs` | 8 |
| `coollabsio` | 339 |
| `coollabs.io` | 24 |
| `hi@coollabs.io` (email) | 2 |
| `coolLabs` (camelCase, manual fix) | 1 |

**Files changed by top-level category** (975 files total):

| Category | Files | Occurrences |
|---|---|---|
| tests/ | 303 | 1555 |
| resources/ | 228 | 972 |
| app/ | 201 | 1038 |
| docs/ | 69 | 916 |
| templates/ | 32 | 733 |
| scripts/ | 9 | 261 |
| other/ | 8 | 242 |
| bootstrap/ | 16 | 223 |
| lang/ | 20 | 78 |
| database/ | 13 | 70 |
| config/ | 8 | 56 |
| docker/ (dir) | 11 | 34 |
| .github/ | 16 | 110 |
| public/ | 4 | 4 |
| routes/ | 3 | 5 |
| root dotfiles & markdown | ~35 | ~500 |
| docker-compose\*.yml (root) | 6 | 182 |

(Full per-file detail is available in `/tmp/rebrand_summary.json`.)

---

## 4. Leftover brand occurrences (complete list)

Every remaining occurrence of `coolify`/`coollabs`/`coollabsio` in the working
tree is **intentional attribution** and lives in one of the four exempt files.
There are no remaining `COOLIFY_` occurrences.

| File:line | Text | Classification |
|---|---|---|
| `NOTICE.md:3` | `[Coolify](https://github.com/coollabsio/coolify)` | intentional — attribution |
| `NOTICE.md:5` | `Coolify — Copyright the Coolify authors` | intentional — attribution |
| `NOTICE.md:13` | `"Coolify", its logos, and its trademarks` | intentional — legal |
| `README.md:39` | `[Coolify](https://github.com/coollabsio/coolify)` | intentional — attribution |
| `docs/business/REVENUE_MODEL.md:10` | `(Coolify, Apache-2.0)` | intentional — attribution |
| `docs/business/REVENUE_MODEL.md:64` | `"Coolify" name, logo, or trademarks` | intentional — legal |
| `docs/business/ENTERPRISE_ROADMAP.md:8` | `coollabsio/coolify@59eacc9…` | intentional — upstream provenance |

**Missed-then-fixed (recorded for the audit trail):**

| File:line | Text | Classification |
|---|---|---|
| `tests/Feature/ResourceIndexTypeLabelTest.php:37` | `'name' => 'coolLabs'` | **MISS** (camelCase `coollabs` variant not covered by the case-sensitive tokens) — fixed manually to `northlatch` |

The camelCase miss was caught by the final case-insensitive `coollabs` grep; no
other mixed-case variants remain (the case-insensitive `coolify` and `coollabs`
greps both return only the attribution lines above).

---

## 5. Boundary decisions (with evidence)

**No brand identifier was "intentionally kept".** Every Coolify/Coollabs brand
string (case-insensitive) was renamed, including all `ghcr.io/coollabsio/*`
image paths, because those are Coolify-published images (brand artifacts) rather
than neutral third-party dependencies. The result is a set of `northlatch`
placeholders (see §7), not references to the old brand.

- **Helper & realtime Dockerfiles are in-repo** (`docker/coolify-helper`,
  `docker/coolify-realtime`). Their image identifiers rename consistently:
  `IMAGE_NAME` in `.github/workflows/*` is now `northlatch/xlaunch-helper` and
  `northlatch/xlaunch-realtime`; exhaustive grep confirms **zero** `coollabsio`
  / `coolify` / `coollabs` / `COOLIFY_` strings remain in any workflow,
  Dockerfile, or compose file. (The build-context *directory* names are a
  separate, pending rename item — see §8.)
- **No genuine cross-repo runtime dependency required keeping.** Third-party
  images and CDNs that are not Coolify-branded (e.g. `ghcr.io/windmill-labs/windmill`,
  `ghcr.io/umami-software/umami`, `quay.io/soketi/soketi`, `minio/mc`) contain no
  brand string and were left untouched. Coolify-published images
  (`coollabsio/minio`, `coollabsio/example`, `coollabsio/jean-server`,
  `coollabsio/shoutrrr`, `coollabsio/pocketbase`,
  `coollabsio/next-image-transformation`, `coollabsio/maxio`) are **not**
  rebuildable from this repository and now resolve to `ghcr.io/northlatch/*`
  placeholders; they are recorded in §7 as images that must be republished or
  substituted before those service templates will deploy.

---

## 6. Verification results

| Check | Scope | Result |
|---|---|---|
| `php -l` | 815 modified `.php` / `.blade.php` / `.php.txt` files | **0 failures** |
| JSON parse (`json.load`) | 31 modified `.json` files | **0 failures** |
| YAML validate (Ruby Psych `load_stream`) | 55 modified `.yml` / `.yaml` files | **0 failures** |
| `grep -rniI "coolify"` | whole tree except `.git` | only 4 exempt attribution files |
| `grep -rniI "coollabs"` | whole tree except `.git` | only 3 exempt attribution files + 1 fixed `coolLabs` |
| `grep -rnI "COOLIFY_"` | whole tree except `.git` | 0 |
| `grep -rnI "coollabsio"` | whole tree except `.git` | only 3 exempt attribution files |

`composer update --lock` completed successfully (exit 0), changing only the
`content-hash` line of `composer.lock` (`971daeb1…` → `e09ef630…`); no
dependency versions changed.

---

## 7. Placeholder registry

Everything below is a placeholder that points at a Northlatch identity that does
not yet exist in production and must be provisioned before go-live.

### Domains

- `northlatch.com` (793) — apex placeholder
- `app.northlatch.com` (40), `app2.northlatch.com` (18), `cloud.northlatch.com`
- `cdn.northlatch.com` (17) — install-script / asset CDN
- `domainconnect.northlatch.com` (2), `analytics.northlatch.com`,
  `www.app.northlatch.com`, `undead.northlatch.com`, `staging-but-dev.northlatch.com`

### Email

- `hello@northlatch.com` — primary contact (README, CODE_OF_CONDUCT)
- `security@northlatch.com` — `SECURITY.md` (domain-replaced local-part)
- `feedback@northlatch.com` — in-app feedback (domain-replaced local-part)

### Repositories (GitHub)

- `github.com/northlatch/xlaunch` (76) — this repo
- `github.com/northlatch/xlaunch.git` (4)
- `github.com/northlatch/xlaunch-examples` (47), `xlaunch-docs` (2)
- `github.com/northlatch/coold`, `jean`, `next-image-transformation`, `minio`,
  `private-app`

### Container registry (`ghcr.io/northlatch/*`)

Rebuildable in this repo (publish first — see §8):

- `ghcr.io/northlatch/xlaunch`
- `ghcr.io/northlatch/xlaunch-helper`
- `ghcr.io/northlatch/xlaunch-realtime`
- `ghcr.io/northlatch/xlaunch-testing-host`

Coolify-published, **not** rebuildable from this repo (republish or substitute):

- `ghcr.io/northlatch/minio` (13) — Coolify minio fork
- `ghcr.io/northlatch/example` (5)
- `ghcr.io/northlatch/jean-server` (4)
- `ghcr.io/northlatch/shoutrrr`, `pocketbase`, `next-image-transformation`, `maxio`

### Twitter/social

- `@xlaunchio` (derived from `@coolifyio` by the `coolify` → `xlaunch` rule) —
  single occurrence in `resources/views/layouts/base.blade.php`

---

## 8. Bootstrap checklist

1. **Publish the 3 core images before deploy:** `xlaunch`, `xlaunch-helper`,
   `xlaunch-realtime` (plus `xlaunch-testing-host`) to `ghcr.io/northlatch/*`,
   and verify all three run. The in-repo Dockerfiles are
   `docker/coolify-helper/Dockerfile` and `docker/coolify-realtime/Dockerfile`
   (directories pending rename, below).
2. **Resolve the Coolify-published images** listed in §7 that are not
   rebuildable here (`minio`, `example`, `jean-server`, `shoutrrr`, `pocketbase`,
   `next-image-transformation`, `maxio`): republish under `ghcr.io/northlatch/*`
   or substitute with upstream equivalents.
3. **`composer update --lock` status:** ✅ complete — `composer.lock`
   `content-hash` synced, no dependency changes.
4. **Real domains:** replace `northlatch.com` (and subdomains) with the real
   distribution host; update the install-script CDN path
   (`cdn.northlatch.com`).
5. **Real logos:** replace the placeholder wordmark SVGs and the remaining
   raster logos (see §9).
6. **GHCR write permissions** in the target `northlatch` org for the CI
   `GITHUB_TOKEN`.
7. **Rename the pending filesystem paths** listed in §9 so that PHP PSR-4
   autoloading and the image CI pipeline resolve correctly.

---

## 9. Known consequences of the content-only sweep (pending renames)

The sweep was **content-only** (text files; logo SVGs keep their original
filenames per the brief). Class names, namespace segments, and build-context
paths were rewritten in content, but the corresponding **files/directories were
not renamed**. These are documented, not silently fixed, and require a follow-up
rename pass:

### 9a. PHP class/namespace ↔ filename mismatches (PSR-4 autoload break)

`composer dump-autoload` reports these classes no longer map to their files
(they will fail to autoload until the files/directories are renamed):

- `app/Mcp/Tools/CoolifyHelp.php` → class now `XlaunchHelp`
- `app/Mcp/Servers/CoolifyServer.php` → class now `XlaunchServer`
- `app/Actions/Server/UpdateCoolify.php` → class now `UpdateXlaunch`
- `app/Jobs/CoolifyTask.php` → class now `XlaunchTask`
- `app/Jobs/UpdateCoolifyJob.php` → class now `UpdateXlaunchJob`
- `app/Services/CoolifyUpgradeStatus.php` → class now `XlaunchUpgradeStatus`
- `app/Actions/CoolifyTask/` (directory) → namespace now `XlaunchTask`
- `tests/Unit/UpdateCoolifyTest.php`, `tests/Unit/CoolifyTaskCleanupTest.php`,
  `tests/Unit/CoolifyUpgradeStatusTest.php`,
  `tests/Unit/StripCoolifyCustomFieldsTest.php`,
  `tests/Unit/ApplicationDeploymentCoolifyUrlDomainsTest.php`,
  `tests/Feature/Jobs/CoolifyTaskRetryTest.php`

### 9b. Docker build-context paths (CI image builds will fail until renamed)

The realtime/helper Docker directories are still named `docker/coolify-realtime`
and `docker/coolify-helper`, but the content now references the renamed paths:

- `.github/workflows/coolify-realtime.yml` → `file: docker/xlaunch-realtime/Dockerfile`
  and `paths: - docker/xlaunch-realtime/**`
- `.github/workflows/coolify-helper.yml` → `file: docker/xlaunch-helper/Dockerfile`
- `docker/coolify-realtime/Dockerfile` → `COPY docker/xlaunch-realtime/…`

Rename the two directories to `docker/xlaunch-realtime` and
`docker/xlaunch-helper` (the workflow `dockerfile:`/`paths:` and the Dockerfile
`COPY` lines then resolve correctly).

### 9c. Logo filename mismatch

Code now references `xlaunch-logo.svg` / `xlaunch-logo-dev-transparent.png`
(`resources/views/layouts/*.blade.php`, `tests/Feature/SidebarNavigationMarkupTest.php`),
but the physical files are still `coolify-logo*.svg` (content replaced with an
Xlaunch wordmark per the brief) and `coolify-logo*.png` (raster, left as-is).
Reconcile by renaming the files or reverting the references.

### 9d. Files/dirs whose names still contain the old brand (cosmetic)

- Workflow filenames: `.github/workflows/coolify-*.yml` (9 files)
- `.coolify-logo` (ASCII logo — content replaced with an Xlaunch banner)
- `.cursor/rules/coolify-ai-docs.mdc`
- `.dev/lima/coolify-naked-test.yaml`
- `docs/v5/architecture/adr/0004-coolify-control-plane-boundary.md`
- `resources/domain-connect/coolify.io.hosting.json`
- Raster logos (left as-is, pending replacement):
  `public/coolify-logo-dev-transparent.png`, `public/coolify-logo-monochrome.png`,
  `public/coolify-logo-red.png`, `public/coolify-transparent.png`,
  `public/svgs/coolify.png`

---

## 10. What was NOT done / not verified

- **No Docker build** was run.
- **No test suite / no container** was run.
- The PHP PSR-4 autoload break (§9a) and Docker build-path break (§9b) are
  documented consequences of the content-only sweep; they were not fixed by
  file renames.
- Raster logos were left in place (not redrawn).
