<?php

namespace Kizami;

/**
 * Builds the finished data package for views/dashboard.php from Store
 * queries. Kirby-free; names and settings come in. The template computes
 * nothing, it only prints.
 */
final class Analysis
{
    private const HOURS_FROM = 6;
    private const HOURS_TO = 22;

    public static function data(Store $store, Names $names, int $days, array $settings): array
    {
        $w = Store::window($days);
        $berlin = new \DateTimeZone('Europe/Berlin');
        $from = (new \DateTimeImmutable($w['since'], new \DateTimeZone('UTC')))->setTimezone($berlin)->format('Y-m-d');
        // The subtitle shows calendar days, regardless of window() putting
        // 'until' at the reference time (not midnight of the next day) — see
        // Store::window(). "To" is always today.
        $to = (new \DateTimeImmutable('today', $berlin))->format('Y-m-d');

        $visits = $store->visits($days);
        $visitsPrevious = $store->visits($days, 1);
        $visitsPerDay = $store->visitsPerDay($days);
        $actionsPerDay = $store->actionsPerDay($days);
        $contactTargets = (array) ($settings['contactTargets'] ?? []);
        $rateTargets = $contactTargets ?: (array) ($settings['targets'] ?? $settings['tiles'] ?? []);
        $rateCurrent = $store->contactSources($days, $rateTargets);
        $ratePrevious = $store->contactSources($days, $rateTargets, 1);
        $withAction = array_sum(array_column($rateCurrent['sources'], 'withContact'));
        $withActionPrevious = array_sum(array_column($ratePrevious['sources'], 'withContact'));
        $directLinks = array_map(fn ($a) => ['name' => $names->target($a['target']), 'value' => $a['count']],
            $store->directLinkHits($days));

        $tiles = [
            self::tile('visits', I18n::t('tile.visits'), $visits, $visitsPrevious, array_column($visitsPerDay, 'count'), I18n::t('tile.visitsHint')),
            self::tile('pageViews', I18n::t('tile.pageViews'), $store->pageViews($days), $store->pageViews($days, 1),
                array_column($store->viewsPerDayFilled($days), 'count'), I18n::t('tile.pageViewsHint')),
        ];
        $tileNames = (array) ($settings['tileNames'] ?? []);
        foreach ((array) ($settings['tiles'] ?? []) as $target) {
            $target = (string) $target;
            $name = (string) ($tileNames[$target] ?? $names->target($target));
            $tiles[] = self::tile($target, $name, $store->clicks($days, 0, $target), $store->clicks($days, 1, $target),
                array_column($store->actionsPerDay($days, 0, $target), 'count'), I18n::t('tile.clicksHint'));
        }

        $perPage = [];
        foreach ($store->redirects($days) as $r) {
            $perPage[$r['target']][] = ['name' => $names->page($r['path']), 'value' => $r['count']];
        }
        $actions = [];
        $actionCounts = array_column($store->actions($days), 'count', 'target');
        foreach (($settings['targets'] ?? []) as $target) { $actionCounts[$target] ??= 0; }
        arsort($actionCounts);
        foreach ($actionCounts as $target => $count) {
            $actions[] = ['name' => $names->target((string) $target), 'value' => $count,
                'pages' => array_slice($perPage[$target] ?? [], 0, 3)];
        }

        $rate = $visits > 0 ? round($withAction / $visits * 100, 1) : 0.0;
        $ratePrevious = $visitsPrevious > 0 ? round($withActionPrevious / $visitsPrevious * 100, 1) : 0.0;
        $contact = $contactTargets !== [];
        // Only actions after a page view on the same day. Several clicks of
        // one day visit don't raise its rate.
        $rateText = $withAction === 0 ? I18n::t('rate.none')
            : I18n::t($contact ? 'rate.everyNthContact' : 'rate.everyNth', ['n' => max(1, (int) round($visits / $withAction))])
              . ($withActionPrevious > 0 ? ' ' . I18n::t('rate.previous', ['n' => max(1, (int) round($visitsPrevious / $withActionPrevious))]) : '');
        // The template appends exactly one full stop (see dashboard.php) —
        // the text itself must not bring one, or "…).." appears.
        $rateText = rtrim($rateText, '.');

        $grid = [];
        foreach ($store->weekGrid($days) as $wd => $hours) {
            $grid[$wd] = array_values(array_slice($hours, self::HOURS_FROM, self::HOURS_TO - self::HOURS_FROM + 1));
        }

        $d = $store->devices($days);
        $unknown = $d['unknown'];
        unset($d['unknown']);
        $deviceTotal = max(1, array_sum($d));
        $devices = [];
        foreach (['mobile', 'desktop', 'tablet'] as $class) {
            $devices[] = ['name' => I18n::t('device.' . $class), 'value' => $d[$class], 'share' => round($d[$class] / $deviceTotal * 100) . ' %'];
        }
        usort($devices, fn ($a, $b) => $b['value'] <=> $a['value']);

        $sources = [];
        $internal = 0;
        foreach ($store->sources($days) as $s) {
            if ($names->isInternal($s['source'])) { $internal += $s['count']; continue; }
            $name = $names->source($s['source']);
            $sources[$name] = ($sources[$name] ?? 0) + $s['count'];
        }
        arsort($sources);
        $sources = array_map(fn ($n, $v) => ['name' => (string) $n, 'value' => $v], array_keys($sources), $sources);

        $pages = array_map(fn ($p) => ['name' => $names->page($p['path']), 'value' => $p['count']], $store->viewsPerPage($days));

        $contactData = $contact ? $rateCurrent : ['sources' => [], 'withoutEntry' => 0];
        $contactSources = [];
        foreach ($contactData['sources'] as $s) {
            $name = $s['source'] === Names::DIRECT ? I18n::t('source.directUnknown') : $names->source($s['source']);
            $contactSources[$name] ??= ['name' => $name, 'visits' => 0, 'clicks' => 0, 'withContact' => 0];
            foreach (['visits', 'clicks', 'withContact'] as $k) { $contactSources[$name][$k] += $s[$k]; }
        }
        $contactSources = array_values($contactSources);
        usort($contactSources, fn ($a, $b) => $b['visits'] <=> $a['visits']);
        foreach ($contactSources as &$s) {
            $s['rate'] = $s['visits'] >= 20 ? round(100 * $s['withContact'] / $s['visits'], 1) : null;
        }
        unset($s);
        $positions = array_map(fn ($p) => ['name' => $names->target($p['target']),
            'position' => (string) ($settings['positionNames'][$p['position']] ?? ($p['position'] ?: I18n::t('position.none'))),
            'value' => $p['count']], $store->linkPositions($days));
        $images = array_map(fn ($i) => ['name' => (string) ($settings['images'][$i['image']] ?? $i['image']),
            'page' => $names->page($i['path']), 'value' => $i['count']], $store->images($days));
        $entries = array_map(fn ($p) => ['name' => $names->page($p['path']), 'value' => $p['count']], $store->entryPages($days));

        // Dwell time: its own section, only with the switch on. null keeps
        // the template lean — it does not show the section at all then.
        $dwellTime = !empty($settings['dwellTime']) ? self::dwellTime($store, $names, $days, $berlin) : null;

        $lt = $store->longTerm();
        $months = array_reverse(array_slice($lt['months'], 0, 24));
        $months = array_map(fn ($m) => ['name' => I18n::monthYear($m['month']), 'value' => $m['devices']], $months);

        return [
            'days' => $days, 'from' => $from, 'to' => $to, 'brand' => (string) ($settings['brand'] ?? ''),
            'tiles' => $tiles,
            'actions' => $actions,
            'directLinkHits' => ['total' => array_sum(array_column($directLinks, 'value')), 'targets' => $directLinks],
            'discardedLinkHits' => $store->discardedLinkHits($days),
            'contact' => ['sources' => $contactSources, 'withoutEntry' => $contactData['withoutEntry'],
                'targets' => array_map(fn ($t) => $names->target((string) $t), $contactTargets)],
            'positions' => $positions, 'images' => $images, 'imagesActive' => !empty($settings['images']), 'entries' => $entries,
            'actionRate' => ['value' => $rate, 'previous' => $ratePrevious, 'text' => $rateText, 'contact' => $contact],
            'trend' => [
                'visits' => array_map(fn ($t) => ['name' => $t['day'], 'value' => $t['count']], $visitsPerDay),
                'actions' => array_map(fn ($t) => ['name' => $t['day'], 'value' => $t['count']], $actionsPerDay),
            ],
            'weekGrid' => ['rows' => array_map(fn ($i) => I18n::weekday($i), range(1, 7)),
                'columns' => array_map('strval', range(self::HOURS_FROM, self::HOURS_TO)), 'values' => $grid],
            'devices' => $devices,
            'devicesUnknown' => $unknown,
            'sources' => $sources,
            'sourcesTable' => [...$sources, ...($internal > 0 ? [['name' => I18n::t('source.internal'), 'value' => $internal]] : [])],
            'pages' => $pages,
            'longTerm' => ['total' => $lt['total'], 'months' => $months],
            'technical' => ['notFound' => $store->notFound($days), 'schema' => $store->missingTables(), 'writeError' => $store->writeProbe()],
            'shades' => Chart::shades((string) ($settings['color'] ?? '#2f4f2a')),
            'dwellTime' => $dwellTime,
        ];
    }

    /**
     * Dwell-time section: median per visit (with comparison to the previous
     * period), distribution in buckets, median per page. The median is
     * computed in PHP on purpose, not in SQL — the hoster has SQLite 3.7.17
     * without window functions, and a median can't be expressed in a simple
     * GROUP BY query.
     */
    private static function dwellTime(Store $store, Names $names, int $days, \DateTimeZone $berlin): array
    {
        $current = $store->dwellTimePerVisit($days);
        $previous = $store->dwellTimePerVisit($days, 1);
        $medianCurrent = (int) round(self::median($current));
        $medianPrevious = (int) round(self::median($previous));

        $perPage = [];
        foreach ($store->dwellTimePerPage($days) as $r) {
            $perPage[$r['path']][] = $r['seconds'];
        }
        $pages = [];
        foreach ($perPage as $path => $values) {
            $pages[] = ['name' => $names->page((string) $path), 'median' => (int) round(self::median($values)), 'measurements' => count($values)];
        }
        usort($pages, fn ($a, $b) => $b['measurements'] <=> $a['measurements']);

        $first = $store->firstDwellTimeMeasurement();
        $firstBerlin = $first !== null
            ? (new \DateTimeImmutable($first, new \DateTimeZone('UTC')))->setTimezone($berlin)->format('Y-m-d')
            : null;

        return [
            'median' => $medianCurrent,
            // The minimum-base rule must check the number of previous-period
            // visits WITH a measurement, not the previous median in seconds
            // (that is no quantity) — hence the third parameter.
            'comparison' => Comparison::text($medianCurrent, $medianPrevious, count($previous)),
            'buckets' => self::dwellTimeBuckets($current),
            'pages' => array_slice($pages, 0, 10),
            'firstMeasurement' => $firstBerlin,
        ];
    }

    /** Distributes second totals per visit over five fixed buckets. */
    private static function dwellTimeBuckets(array $secondsPerVisit): array
    {
        $buckets = ['under10s' => 0, '10to30s' => 0, '30sTo1min' => 0, '1to3min' => 0, 'over3min' => 0];
        foreach ($secondsPerVisit as $s) {
            $buckets[match (true) {
                $s < 10 => 'under10s',
                $s < 30 => '10to30s',
                $s < 60 => '30sTo1min',
                $s < 180 => '1to3min',
                default => 'over3min',
            }]++;
        }
        $result = [];
        foreach ($buckets as $key => $count) { $result[] = ['name' => I18n::t('bucket.' . $key), 'value' => $count]; }
        return $result;
    }

    /**
     * Median of a list of numbers, computed in PHP (see dwellTime()).
     * Even count: mean of the two middle values. Empty list: 0.0.
     */
    private static function median(array $values): float
    {
        $values = array_values($values);
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $middle = intdiv($n, 2);
        if ($n % 2 === 1) {
            return (float) $values[$middle];
        }
        return ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private static function tile(string $key, string $name, int $value, int $previous, array $trend, string $hint): array
    {
        return ['key' => $key, 'name' => $name, 'value' => $value,
            'comparison' => Comparison::text($value, $previous), 'trend' => array_map('intval', $trend), 'hint' => $hint];
    }
}
