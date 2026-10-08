# Kizami

Cookieless, server-side visitor statistics for [Kirby CMS](https://getkirby.com) 5.

*Kizami* (刻み) is Japanese for a notch or tally mark; *kizamu* also means "to keep the beat". The plugin counts. It does not track anyone from one day to the next.

- **Server-side.** Page views are counted in Kirby's `route:after` hook. Ad blockers don't affect it, and no tracking script is needed.
- **No cookies, no browser storage, no third parties.** Nothing is stored on the visitor's device. Data stays in one SQLite file outside the web root.
- **Honours Do Not Track and Global Privacy Control.** With `DNT: 1` or `Sec-GPC: 1`, nothing is recorded.
- **Daily rotating salt.** A day identifier is computed from IP address, user agent, date and a secret that is overwritten every day (Europe/Berlin). Raw IPs and user agents are never stored. Identifiers from different days can't be linked through the database.
- **Counts what small businesses care about.** Phone calls, route requests and e-mail clicks go through counted redirects such as `/call` → `tel:…`. No JavaScript is involved, and the dashboard shows contact rates per source and landing page.
- **Bot and crawler filtering.** User-agent filtering plus a redirect-click filter based on Fetch Metadata and the visitor's same-day page views (see [Counting rules](#counting-rules)).
- **Dashboard** at `/k/dashboard` and a **JSON report** at `/k/report` for scripts (daily or weekly summaries).
- **English and German.** The dashboard and the report text follow the site's language (see `language` below).
- **Runs on old shared hosting.** Every SQL statement works on SQLite 3.7.17, and CI runs the test suite against a real 3.7.17 build.

## Requirements

- PHP 8.2–8.4 with the `sqlite3` extension
- Kirby 5
- A writable Kirby `storage` root, ideally outside the web root. Data lives in `<storage>/kizami/`.

## Installation

```sh
composer require sayamaapps/kirby-kizami
```

Composer installs the plugin into `site/plugins/kizami/` (via `getkirby/composer-installer`, which ships with `getkirby/cms`). Add `/site/plugins/kizami/` to `.gitignore` and make sure your deploy ships it, just as it ships `kirby/` and `vendor/`.

Until the package is on Packagist, add the repository first:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/simsimmal/kirby-kizami" }]
```

Manual installation (download into `site/plugins/kizami/`) also works. The plugin loads its own classes and has no runtime dependencies.

## Configuration

Everything is off by default. Minimal setup in `site/config/config.php`:

```php
return [
    'kizami.active' => true,
    'kizami.redirects' => [
        'call'  => 'tel:+491234567890',
        'route' => 'https://www.google.com/maps/dir/?api=1&destination=…',
    ],
];
```

In templates:

```php
<a href="<?= $page->redirectLink('call', 'tel:+491234567890', 'header') ?>" rel="nofollow">Call us</a>
<?= $page->redirectAnchor('call', 'Call us', 'tel:+491234567890', 'header', ['class' => 'button']) ?>
```

The second argument is a fallback: if the redirect isn't configured, the link still works. Redirects keep working when counting is switched off. The third argument is a link position; it is recorded only if it is a key of `positionNames`.

Dashboard access: create `site/config/kizami.php` (keep it out of git) with a bcrypt hash, or log in to the Panel:

```php
<?php return ['user' => 'client', 'hash' => '$2y$10$…']; // password_hash('…', PASSWORD_DEFAULT)
```

### Options

All keys are read as `kizami.<name>`, flat or nested. `null` counts as "not set".

| Option | Default | Meaning |
|---|---|---|
| `active` | `false` | Master switch for counting, dashboard, report and snapshot route. Redirects work regardless. |
| `redirects` | `[]` | Counted redirects: `name => target` (`tel:`, `mailto:`, `https:`, or a path), or a closure returning the target. Each name becomes a route `/<name>`. |
| `targetNames` | `[]` | Display names for redirect targets: `name => label`. |
| `sourceNames` | `[]` | Display names for referrer hosts / `utm_source` values. |
| `pageNames` | `[]` | Display names for paths (otherwise the Kirby page title). |
| `contactTargets` | `[]` | Redirects that count as "contact" for the contact rate. |
| `tiles` | first two redirects | Redirects shown as dashboard tiles and as `actions` in the report. |
| `tileNames` | `[]` | Tile headings: `name => label`. |
| `positionNames` | `[]` | Allowed link positions (`header`, `footer`, …) with labels. Unknown positions are dropped. |
| `images` | `[]` | Allowed image IDs for gallery-open counting (needs the site's gallery script, see [Beacons](#beacons)). |
| `dwellTime` | `false` | Accept time-on-page beacons at `/k/duration` (needs the site's beacon script). |
| `ownHosts` | `[]` | Additional own domains whose referrers count as internal. |
| `language` | site language | `en` or `de` for the dashboard and the report text. Otherwise the default language of a multi-language site, then the first two letters of Kirby's `locale` option, then English. |
| `brand` | site title | Name in the dashboard title and the report (string or closure). |
| `color` | `#2f4f2a` | Dashboard accent colour (`#rrggbb`). |
| `fonts` | `null` | Dashboard fonts: `['serif' => ['name' => '…', 'file' => '/assets/fonts/….woff2'], 'sans' => […]]`. `file` is optional. |
| `extraCss` | `null` | Extra dashboard CSS, as a `.css` path relative to the project root. Loaded last. |
| `previewAccess` | `null` | For sites behind their own password while being built: a closure returning `['user' => …, 'hash' => …]` while that protection is on, `null` once live. During preview the dashboard checks exactly these credentials. |
| `report` | `false` | Enable `/k/report` (also requires `active`). Registers the Panel role `kizami-report`. |
| `reportRoles` | `['kizami-report']` | Roles allowed to read the report. Admins are deliberately not allowed by default. |
| `siteId` | site hostname | Site identifier (`site`) in the report. Set it explicitly so it survives a domain change. |
| `reportWithoutHttps` | `false` | Allow the report and snapshot over plain HTTP. Local testing only. |
| `snapshot` | `false` | Enable `/k/snapshot` (also requires `active`). Registers the Panel role `kizami-snapshot` (no Panel access). |
| `snapshotRoles` | `['kizami-snapshot']` | Roles allowed to download the snapshot. Separate from the report role on purpose: the snapshot contains raw events. |

The dashboard and report strings are also registered as Kirby translations (`t('kizami.tile.visits')`).

### Beacons

Two optional routes accept `POST` requests from the site's own scripts. Both check `Origin` and `Sec-Fetch-Site`, accept only paths of existing pages, and always answer `204`:

- `/k/image` with `image` (a key of `images`) and `path` — a deliberate full-size view in a gallery.
- `/k/duration` with `path` and `seconds` — active seconds on a page, capped at 30 minutes.

## Report route

`GET /k/report?kind=day|week&date=YYYY-MM-DD`, with HTTP Basic Auth against a Kirby account that has an allowed role. HTTPS only. Kirby's login throttling applies.

- `kind=day`: the given day, or yesterday if `date` is omitted. Compared with the same weekday one week earlier.
- `kind=week`: the Monday–Sunday week containing `date`, or the last full week. Compared with the week before.

```json
{
  "ok": true, "format": 2, "site": "example", "generatedAt": "2026-10-07T08:00:00+02:00",
  "kind": "day", "from": "2026-10-06", "to": "2026-10-06", "brand": "…",
  "visits": 3, "visitsPrevious": 2, "pageViews": 9, "pageViewsPrevious": 7,
  "clicks": 1, "clicksPrevious": 0, "directLinkHits": 0,
  "discardedLinkHits": 0, "discardedLinkHitsPrevious": 0,
  "actions": [{"name": "Calls", "value": 1}], "sources": {}, "pages": {},
  "dwellTime": null,
  "text": "…"
}
```

`kind=week` adds `perDay` (`[{"day": "…", "visits": 3}, …]`) and `mobileShare` (percent or `null`). `from` is the first day that was actually delivered, so a reader can check it against the day it asked for. Errors are `{"ok": false, "error": "…"}` with a 4xx/5xx status. `format` changes only when a field is removed, renamed or changes meaning. New fields don't change it.

## Backups

`<storage>/kizami/kizami.sqlite` runs in WAL mode. A plain file copy can miss committed transactions, so use one of these:

- **CLI:** `php site/plugins/kizami/bin/kizami-snapshot /abs/path/storage/kizami /backups/new.sqlite` (also `vendor/bin/kizami-snapshot`).
- **HTTP**, for hosts without a shell or cron: `GET /k/snapshot` with Basic Auth (enable with `kizami.snapshot`). It returns the snapshot as `application/vnd.sqlite3` and puts the SHA-256 in `X-Kizami-Sha256`.

Both use SQLite's online backup API (available since 3.6.11) and run `PRAGMA integrity_check` on the copy. The copy is one self-contained file. The daily secret (`secret.txt`) is never included. Don't archive it in other backups either.

Maintenance: `php site/plugins/kizami/maintenance.php compact /abs/path/storage/kizami` merges events older than five years into daily totals. The same thing happens automatically on the first request of each day.

## Counting rules

- A **visit** is a day identifier with at least one page view.
- **Redirect clicks** count only when the browser confirms a user action (`Sec-Fetch-User: ?1`) or when the same day identifier has already viewed a page today. Discarded hits are still redirected and appear as a daily total (`discardedLinkHits`), with no other data.
  - Known undercount: Safari/WebKit never sends `Sec-Fetch-User`. A visitor whose IP or user agent changes between the page view and the click (for example, switching from Wi-Fi to mobile data) is not counted. There is no workaround.
  - Hits from before 1.0.0 are not filtered retroactively.
- Bots (user-agent list, empty user agent) and requests with DNT/GPC are not counted. Logged-in Panel users are currently counted like everyone else.

## Upgrading from the `kennzahlen` copy

Before 1.0.0 the plugin lived as a copy named `kennzahlen` in each site. On the first request after switching to the package, Kizami moves `<storage>/kennzahlen/` to `<storage>/kizami/` and migrates the tables to the English schema; no data is lost. Configuration keys, the access file, routes, page methods and Panel roles were renamed; the [CHANGELOG](CHANGELOG.md#1.0.0) has the full old → new table. While `site/plugins/kennzahlen/` still exists, the package stays inactive.

## Privacy

See [PRIVACY.md](PRIVACY.md) for what is stored and why, and [DATENSCHUTZ.md](DATENSCHUTZ.md) for the German technical text block for privacy policies. They describe the implementation and are not legal advice. Each operator must check the legal basis and retention period for their own site.

## Development

```sh
composer install --ignore-platform-req=ext-gd   # Kirby is a dev dependency used as a test fixture
composer test                                   # or: sh tests/run.sh
```

The tests are plain PHP scripts (exit code 0 = pass). The read-only database check must run as a non-root user.

## License

MIT, see [LICENSE](LICENSE).
