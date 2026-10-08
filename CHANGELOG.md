# Changelog

All notable changes to this project are documented here. Versions follow
[Semantic Versioning](https://semver.org) with these project rules:

- **A change to the counting definition** (what counts as a visit, page view
  or click) is a **major** version and carries a date, so a break in a time
  series can be explained later.
- A schema change is a **minor** version and ships with an automatic migration.
- A change to the privacy text (`DATENSCHUTZ.md` / `PRIVACY.md`) is marked
  **[privacy text]**. Every site using the plugin must then review its privacy
  policy.
- The report JSON carries `format`; it increases only when a field is removed,
  renamed or changes meaning.

## [1.1.0] — 2026-10-08

### Added

- **Panel menu entry** "Statistics" / "Kennzahlen" (chart icon) that opens
  `/k/dashboard` in a new tab, for every Panel role, while `kizami.active` is
  true. A plain link area, nothing is added to the Panel bundle. Sites with
  their own `panel.menu` list add `'kizami'` to it and can drop a hand-made
  dashboard entry.

## [1.0.0] — 2026-10-08

First release as a Composer package. Before 1.0.0 the code lived as a copy
named `kennzahlen` in each site (`site/plugins/kennzahlen/`), with German
names throughout. The changes below are measured against that copy.

### Changed — counting definition (2026-10-08)

- **Redirect clicks are filtered.** A hit on a counted redirect is recorded
  only if the browser sends `Sec-Fetch-User: ?1`, or if the same day identifier
  already has a page view on the same (Europe/Berlin) day. Everything else is
  still redirected (302), but is not stored as an event. It only increases a
  daily counter (`kizami_discarded`: date + count). Reason: on one site,
  117 of 169 contact-redirect hits had no page view at all. They came from a
  link-following crawler with a browser-like user agent and rotating IPs.
  Expect lower click numbers and contact rates from the switch-over day on.
  Known undercount: browsers without `Sec-Fetch-User` (Safari/WebKit, older
  browsers, some in-app browsers) whose IP or user agent changes between page
  view and click. Earlier data is not filtered retroactively.

### Changed — everything renamed to English (breaking)

Code, options, routes, page methods, roles, report fields, files and tables
are English now. There are no fallbacks for the old names: a site switching
from the copy updates its config and templates once, using the tables below.

| Area | Before (`kennzahlen` copy) | Now (Kizami) |
|---|---|---|
| Options | `kennzahlen.*` | `kizami.*` |
| | `aktiv`, `weiterleitungen` | `active`, `redirects` |
| | `zielnamen`, `quellnamen`, `seitennamen` | `targetNames`, `sourceNames`, `pageNames` |
| | `kontaktziele`, `kacheln`, `kachelnamen` | `contactTargets`, `tiles`, `tileNames` |
| | `positionsnamen`, `bilder`, `verweildauer` | `positionNames`, `images`, `dwellTime` |
| | `eigeneHosts`, `farbe`, `schriften` (`datei`) | `ownHosts`, `color`, `fonts` (`file`) |
| | `zusatzCss` | `extraCss` |
| | `bericht`, `berichtRollen`, `kennung`, `berichtOhneHttps` | `report`, `reportRoles`, `siteId`, `reportWithoutHttps` |
| | `sicherung`, `sicherungRollen` | `snapshot`, `snapshotRoles` |
| | `farbe` fell back to the site option `themenfarbe` | no fallback; set `color` |
| | brand from the site field `marke`, else the title | `brand` option (string or closure), else the title |
| | preview detected via `site/config/credentials.php` | `previewAccess` closure (see README) |
| Access file | `site/config/kennzahlen.php` with `benutzer`, `hash` | `site/config/kizami.php` with `user`, `hash` |
| Routes | `/k/bericht?art=tag\|woche&datum=` | `/k/report?kind=day\|week&date=` |
| | `/k/sicherung` | `/k/snapshot` |
| | `/k/tafel.css`, `/k/dashboard?tage=` | `/k/dashboard.css`, `/k/dashboard?days=` |
| | `/k/bild` (`bild`, `von`) | `/k/image` (`image`, `path`) |
| | `/k/dauer` (`von`, `sekunden`) | `/k/duration` (`path`, `seconds`) |
| | redirect links `/<name>?von=…` | `/<name>?from=…` |
| Page methods | `weiterleitungsLink()`, `weiterleitungsAnker()` | `redirectLink()`, `redirectAnchor()` |
| Positions (examples) | `kopf`, `fuss`, `inhalt` | `header`, `footer`, `content` |
| Panel roles | `kennzahlen-bericht`, `kizami-sicherung` | `kizami-report`, `kizami-snapshot` |
| Helpers | `kennzahlen_*()`, `kizami_verzeichnis()`, … | `kizami_option()`, `kizami_directory()`, `kizami_log_error()`, … |
| Classes | `Kennzahlen\*` aliases, German class names | `Kizami\*` only: `Capture`, `Store`, `Access`, `Redirect`, `Report`, `ReportService`, `SnapshotService`, `Analysis`, `Chart`, `Secret`, `Names`, `Options`, `Fonts`, `Comparison`, `I18n`, `Migration` |
| CLI | `wartung.php bereinigen\|sichern` | `maintenance.php compact\|snapshot` |
| Dashboard CSS | German class names and custom properties | English (`--base`, `--shade-1`…, `.tile`, …); check any `extraCss` |

**Report JSON, `format` 2.** Query `kind=day|week` and `date=YYYY-MM-DD`.
Fields:

| `format` 1 | `format` 2 |
|---|---|
| `art`, `von`, `bis`, `marke` | `kind`, `from`, `to`, `brand` |
| `besuche`, `besucheVorher` | `visits`, `visitsPrevious` |
| `aufrufe`, `aufrufeVorher` | `pageViews`, `pageViewsPrevious` |
| `klicks`, `klicksVorher` | `clicks`, `clicksPrevious` |
| `direkteLinkaufrufe` | `directLinkHits` |
| `verworfeneLinkaufrufe(Vorher)` | `discardedLinkHits(Previous)` |
| `aktionen` `[{name, wert}]` | `actions` `[{name, value}]` |
| `herkunft`, `seiten` | `sources`, `pages` |
| `verweildauer` | `dwellTime` |
| `jeTag` `[{tag, anzahl}]` (week) | `perDay` `[{day, visits}]` (week) |
| `handyAnteil` (week) | `mobileShare` (week) |
| `fehler` | `error` |
| — | `generatedAt` (new) |

`ok`, `format`, `site` and `text` keep their names. `text` follows the site
language (see below); German sites get the same wording as before.

### Added

- Package `sayamaapps/kirby-kizami`, Kirby plugin ID `sayamaapps/kizami`,
  installed to `site/plugins/kizami/`, PHP namespace `Kizami\`.
- **English and German.** Dashboard and report text default to English and
  switch to German via `kizami.language`, the site's default language, or
  Kirby's `locale` option. The strings are also registered as Kirby
  translations (`kizami.*`).
- If an old copy is still present in `site/plugins/kennzahlen/`, the package
  stays inactive and logs that, instead of counting twice.
- Report fields `discardedLinkHits` and `discardedLinkHitsPrevious`. The
  dashboard also shows the discarded count.
- `GET /k/snapshot`: a consistent SQLite snapshot over HTTPS for backup jobs
  on hosts without a shell (`kizami.snapshot`, `kizami.snapshotRoles`). Its
  own Panel role `kizami-snapshot` (no Panel access), separate from the
  report role, because the snapshot contains raw events. The snapshot is one
  self-contained file (rollback journal, no `-wal`/`-shm`).
- `bin/kizami-snapshot`: the same snapshot from the command line, without
  merging old events first.
- `kizami.fonts`: dashboard fonts from configuration instead of a
  site-specific CSS file.
- `$page->redirectAnchor()`: a complete link with `rel="nofollow"`.
  Redirect responses send `X-Robots-Tag: noindex, nofollow`.
- CI on PHP 8.2–8.4, each against a real SQLite 3.7.17 build and a current
  SQLite.

### Fixed

- On SQLite 3.7.17, opening a read-only, schema-less database threw a
  `SQLite3Exception` instead of the documented `RuntimeException` (found by
  the new 3.7.17 CI job). Callers caught it anyway, so pages were unaffected.

### Unchanged (data continuity)

- **No data is lost.** On the first request after the switch, Kizami moves
  `storage/kennzahlen/` to `storage/kizami/` (`kennzahlen.sqlite` →
  `kizami.sqlite` with its `-wal`/`-shm`, `geheimnis.txt` → `secret.txt`,
  `fehler.log` → `errors.log`) and migrates the tables in one transaction:
  `ereignisse` → `kizami_events` (columns and values in English, ids kept),
  `kennzahlen_tage` → `kizami_days`, `kennzahlen_verworfen` →
  `kizami_discarded`, `kennzahlen_wartung` → `kizami_maintenance`. Stored
  link positions `kopf`/`fuss`/`inhalt` become `header`/`footer`/`content`;
  other positions and all redirect names stay as they are. The secret keeps
  its content, so day identifiers continue across the switch. Works on
  SQLite 3.7.17 (no `RENAME COLUMN` needed); covered by
  `tests/MigrationTest.php` in CI on both SQLite versions. Backup jobs that
  read `storage/kennzahlen/` directly must switch to `storage/kizami/`.
- The counting itself (visits, page views, clicks, retention) is unchanged
  apart from the redirect-click filter above.

### [privacy text]

- `DATENSCHUTZ.md` / `PRIVACY.md` now describe the redirect-click filter and
  the daily count of discarded hits, and mention the snapshot route. The
  operations notes use the new option, route and file names.
