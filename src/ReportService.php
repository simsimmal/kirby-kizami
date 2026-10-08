<?php

namespace Kizami;

use DateTimeImmutable;
use Kirby\Cms\App;
use Kirby\Http\Response;
use Throwable;

/**
 * GET /k/report?kind=day|week&date=YYYY-MM-DD — the short report from
 * Report.php as JSON, for scripts (digest across several sites, bot).
 *
 * Off by default, twice: without `kizami.active` AND `kizami.report` the
 * route answers with Kirby's real 404, like the dashboard.
 *
 * Read-only, only for Kirby accounts with a role from `kizami.reportRoles`
 * (default: the bundled role `kizami-report` without Panel access and
 * without permissions). An admin account deliberately does NOT get through:
 * its password belongs in no cron job. Login via Basic Auth against Kirby's
 * own password check, so with Kirby's lockout after failed attempts —
 * without the global option api.basicAuth, which would open the whole API
 * for all accounts.
 *
 * A site with its own bot account allows that role via `kizami.reportRoles`.
 */
final class ReportService
{
    /**
     * JSON format version. Increase it as soon as a field is removed,
     * renamed or changes meaning — readers (digests, other sources with the
     * same format) check it. New fields alone don't increase it.
     *
     * 2 (Kizami 1.0.0): English field names, `kind`/`date` query parameters.
     */
    public const FORMAT = 2;

    public const ROLE = 'kizami-report';

    public static function respond(App $kirby, DateTimeImmutable $now): Response
    {
        if (Options::get($kirby, 'active', false) !== true || Options::get($kirby, 'report', false) !== true) {
            return kizami_not_found();
        }
        // Here and not in the route: sites may call respond() from a route
        // of their own, and the text must still follow the site language.
        I18n::setLanguage(Options::language($kirby));
        try {
            self::authenticate($kirby);
            $kind = $kirby->request()->get('kind', 'day');
            if (!in_array($kind, Report::KINDS, true)) {
                throw new ReportError('"kind" must be "day" or "week".');
            }
            $date = $kirby->request()->get('date');
            if ($date !== null && !is_string($date)) {
                throw new ReportError('"date" must be a valid YYYY-MM-DD.');
            }
            try {
                $reference = Report::referenceDay($kind, $date, $now);
            } catch (\InvalidArgumentException $e) {
                throw new ReportError($e->getMessage());
            }

            $store = new Store(kizami_directory() . '/' . Store::FILE);
            $names = new Names(
                (array) Options::get($kirby, 'targetNames', []),
                (array) Options::get($kirby, 'sourceNames', []),
                (array) Options::get($kirby, 'pageNames', []),
                fn (string $path) => $kirby->site()->find(trim($path, '/'))?->title()->value()
            );
            // The same settings as the dashboard, so report and dashboard
            // show the same names and tiles.
            $redirects = array_keys((array) Options::get($kirby, 'redirects', []));
            $data = Report::data($store, $names, $kind, $reference, [
                'brand' => Options::brand($kirby),
                'tiles' => (array) Options::get($kirby, 'tiles', array_slice($redirects, 0, 2)),
                'tileNames' => (array) Options::get($kirby, 'tileNames', []),
                'contactTargets' => array_values(array_intersect((array) Options::get($kirby, 'contactTargets', []), $redirects)),
                'dwellTime' => Options::get($kirby, 'dwellTime', false) === true,
            ]);
            // Name lists always as a JSON object: PHP would turn an empty
            // list into "[]", and a reader would have to know two shapes.
            $data['sources'] = (object) $data['sources'];
            $data['pages'] = (object) $data['pages'];
            return self::json([
                'ok' => true,
                'format' => self::FORMAT,
                'site' => self::siteId($kirby),
                'generatedAt' => $now->format(DATE_ATOM),
                ...$data,
            ], 200);
        } catch (ReportError $e) {
            return self::json(['ok' => false, 'error' => $e->getMessage()], $e->status);
        } catch (Throwable $e) {
            error_log('Kizami: report not available: ' . $e->getMessage());
            kizami_log_error($e);
            return self::json(['ok' => false, 'error' => 'Internal error.'], 500);
        }
    }

    /**
     * Site identifier in the report: `kizami.siteId`, otherwise the site's
     * hostname. Set it explicitly when a reader tells sites apart — the
     * hostname changes with a domain move, the identifier doesn't.
     */
    public static function siteId(App $kirby): string
    {
        $id = Options::get($kirby, 'siteId');
        if (is_string($id) && trim($id) !== '') {
            return trim($id);
        }
        return strtolower((string) parse_url($kirby->url('index'), PHP_URL_HOST));
    }

    /**
     * Basic Auth against a Kirby account with an allowed role.
     *
     * HTTPS only: Basic Auth sends the password with every request.
     * `kizami.reportWithoutHttps` is meant for local tests only.
     *
     * @throws ReportError
     */
    public static function authenticate(App $kirby, ?array $roles = null): void
    {
        $request = $kirby->request();
        if ($request->ssl() === false && Options::get($kirby, 'reportWithoutHttps', false) !== true) {
            throw new ReportError('HTTPS only.', 403);
        }
        $auth = $request->auth();
        if (!$auth instanceof \Kirby\Http\Request\Auth\BasicAuth) {
            throw new ReportError('Authentication required.', 401);
        }
        try {
            $user = $kirby->auth()->validatePassword((string) $auth->username(), (string) $auth->password());
        } catch (Throwable) {
            throw new ReportError('Authentication failed.', 401);
        }
        $roles = array_map('strval', $roles ?? (array) Options::get($kirby, 'reportRoles', [self::ROLE]));
        if (!in_array($user->role()->name(), $roles, true)) {
            throw new ReportError('This account may not use this route.', 403);
        }
    }

    public static function json(array $data, int $status): Response
    {
        // Pass headers on creation: Kirby's Response::header() only reads,
        // a later header('Cache-Control', …) would set nothing.
        $headers = ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow, noarchive'];
        if ($status === 401) {
            $headers['WWW-Authenticate'] = 'Basic realm="Kizami", charset="UTF-8"';
        }
        return Response::json($data, $status, true, $headers);
    }
}
