<?php
/**
 * Dashboard output. Receives a finished $data array from Analysis::data()
 * (no Kirby access), so the tests check the escaping without Kirby.
 *
 * RULE: every interpolated value goes through $e(). Chart::* already returns
 * escaped markup and is printed raw. Texts come from I18n::t().
 *
 * No inline JavaScript and no inline styles (strict CSP "style-src 'self';
 * script-src 'self'"). Colours are classes; bar widths are viewBox units.
 */
use Kizami\Chart;
use Kizami\I18n;

$e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$t = fn (string $key, array $values = []) => $e(I18n::t($key, $values));
$days = (int) ($data['days'] ?? 30);
$failed = !empty($data['error']) || !empty($data['technical']['schema'] ?? []) || (($data['technical']['writeError'] ?? null) !== null);
$shortDate = fn (string $iso) => $e(I18n::shortDate(new \DateTimeImmutable($iso)));
$table = function (array $rows, ?string $columnA = null, ?string $columnB = null, ?string $summary = null) use ($e) {
    if ($rows === []) { return ''; }
    $h = '<details class="as-table"><summary>' . $e($summary ?? I18n::t('table.summary')) . '</summary><table><thead><tr><th scope="col">'
        . $e($columnA ?? I18n::t('table.name')) . '</th><th scope="col" class="num">' . $e($columnB ?? I18n::t('table.count')) . '</th></tr></thead><tbody>';
    foreach ($rows as $r) { $h .= '<tr><td>' . $e($r['name']) . '</td><td class="num">' . Chart::number($r['value']) . '</td></tr>'; }
    return $h . '</tbody></table></details>';
};
?>
<!doctype html>
<html lang="<?= $e(I18n::language()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($data['brand'] ?? '') ?> · <?= $t('dashboard.title') ?></title>
<link rel="stylesheet" href="/k/dashboard.css">
</head>
<body>
<main>
<header class="masthead">
  <div>
    <p class="brand"><?= $e($data['brand'] ?? '') ?></p>
    <h1><?= $t('dashboard.title') ?></h1>
  </div>
  <nav class="period" aria-label="<?= $t('dashboard.period') ?>">
    <?php foreach ([7 => 'period.7', 30 => 'period.30', 90 => 'period.90', 365 => 'period.365'] as $d => $key): ?>
      <a href="?days=<?= $d ?>"<?= $d === $days ? ' aria-current="page"' : '' ?>><?= $t($key) ?></a>
    <?php endforeach; ?>
  </nav>
</header>

<?php if ($failed): ?>
<p class="warning"><?= $t('dashboard.unavailable') ?></p>
<?php endif; ?>

<?php if (empty($data['error'])): ?>
<p class="subtitle"><?= $t('dashboard.subtitle', ['from' => I18n::date(new \DateTimeImmutable($data['from'])), 'to' => I18n::date(new \DateTimeImmutable($data['to'])), 'days' => $days]) ?></p>

<section class="tiles" aria-label="<?= $t('dashboard.keyFigures') ?>">
  <?php foreach ($data['tiles'] as $tile): ?>
    <article class="tile">
      <h2><?= $e($tile['name']) ?></h2>
      <p class="tile-value"><?= Chart::number($tile['value']) ?></p>
      <p class="tile-comparison comparison-<?= $e($tile['comparison']['class']) ?>"><?= $e($tile['comparison']['text']) ?></p>
      <?= Chart::sparkline($tile['trend']) ?>
      <?php if ($tile['hint'] !== ''): ?><p class="tile-hint"><?= $e($tile['hint']) ?></p><?php endif; ?>
    </article>
  <?php endforeach; ?>
</section>

<details class="explanation">
  <summary><?= $t('explain.summary') ?></summary>
  <dl>
    <dt><?= $t('tile.visits') ?></dt>
    <dd><?= $t('explain.visits') ?></dd>
    <dt><?= $t('tile.pageViews') ?></dt>
    <dd><?= $t('explain.pageViews') ?></dd>
    <?php $clickNames = array_column(array_filter($data['tiles'], fn ($tile) => !in_array($tile['key'], ['visits', 'pageViews'], true)), 'name'); ?>
    <dt><?= $clickNames === [] ? $t('explain.clicksTerm') : $e(implode(', ', $clickNames)) ?></dt>
    <dd><?= $t('explain.clicks') ?></dd>
    <dt><?= $t('explain.percentTerm') ?></dt>
    <dd><?= $t('explain.percent') ?></dd>
    <dt><?= $t('explain.notCountedTerm') ?></dt>
    <dd><?= $t('explain.notCounted') ?></dd>
  </dl>
</details>

<section class="block" aria-labelledby="actions-title">
  <h2 id="actions-title"><?= $t('actions.title') ?></h2>
  <p class="hint"><?= $t('actions.hint') ?></p>
  <?php if ($data['actions'] === []): ?>
    <?= Chart::empty(I18n::t('actions.none')) ?>
  <?php else: ?>
  <div class="two">
    <div>
      <?= Chart::bars(array_map(fn ($a) => ['name' => $a['name'], 'value' => $a['value']], $data['actions'])) ?>
      <?= $table(array_map(fn ($a) => ['name' => $a['name'], 'value' => $a['value']], $data['actions']), I18n::t('actions.column')) ?>
    </div>
    <div class="action-pages">
      <h3><?= $t('actions.fromPage') ?></h3>
      <?php foreach ($data['actions'] as $a): ?>
        <p><strong><?= $e($a['name']) ?>:</strong>
          <?= $a['pages'] === [] ? '–' : implode(', ', array_map(fn ($p) => $e($p['name']) . ' ' . Chart::number($p['value']), $a['pages'])) ?></p>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php $rate = $data['actionRate']; ?>
  <p class="rate"><strong><?= $e($rate['text']) ?>.</strong>
    <?= $t($rate['contact'] ? 'rate.contactLabel' : 'rate.label') ?>: <?= Chart::number($rate['value'], 1) ?> %<?php if ($rate['previous'] > 0): ?> (<?= $t('rate.before') ?> <?= Chart::number($rate['previous'], 1) ?> %)<?php endif; ?>.</p>
  <p class="hint"><?= $t($rate['contact'] ? 'rate.contactHint' : 'rate.hint') ?></p>
</section>

<section class="block" aria-labelledby="direct-links-title">
  <h2 id="direct-links-title"><?= $t('direct.title') ?></h2>
  <p class="hint"><?= $t('direct.hint', ['n' => Chart::number($data['directLinkHits']['total'])]) ?></p>
  <?= $table($data['directLinkHits']['targets'], I18n::t('direct.column')) ?>
  <?php if (isset($data['discardedLinkHits'])): ?>
  <p class="hint"><?= $t('direct.discarded', ['n' => Chart::number($data['discardedLinkHits'])]) ?></p>
  <?php endif; ?>
</section>

<?php if ($data['contact']['targets'] !== []): ?>
<section class="block" aria-labelledby="contact-title">
  <h2 id="contact-title"><?= $t('contact.title') ?></h2>
  <p class="hint"><?= $t('contact.hint', ['targets' => implode(', ', $data['contact']['targets'])]) ?></p>
  <?php if ($data['contact']['sources'] === []): ?><?= Chart::empty() ?><?php else: ?>
  <div class="scroll" tabindex="0" role="region" aria-label="<?= $t('contact.region') ?>"><table class="contact-table">
    <thead><tr><th scope="col"><?= $t('contact.source') ?></th><th scope="col" class="num"><?= $t('tile.visits') ?></th><th scope="col" class="num"><?= $t('contact.clicks') ?></th><th scope="col" class="num"><?= $t('contact.withContact') ?></th><th scope="col" class="num"><?= $t('contact.rate') ?></th></tr></thead>
    <tbody><?php foreach ($data['contact']['sources'] as $s): ?><tr>
      <th scope="row"><?= $e($s['name']) ?></th><td class="num"><?= Chart::number($s['visits']) ?></td><td class="num"><?= Chart::number($s['clicks']) ?></td><td class="num"><?= Chart::number($s['withContact']) ?></td><td class="num"><?= $s['rate'] === null ? '–' : Chart::number($s['rate']) . ' %' ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <p class="hint scroll-hint"><?= $t('contact.swipe') ?></p>
  <p class="hint"><?= $t('contact.rateHint') ?></p>
  <?php endif; ?>
  <?php if ($data['contact']['withoutEntry'] > 0): ?><p class="hint"><?= $t('contact.withoutEntry', ['n' => Chart::number($data['contact']['withoutEntry'])]) ?></p><?php endif; ?>
</section>
<?php endif; ?>

<section class="block" aria-labelledby="trend-title">
  <h2 id="trend-title"><?= $t('trend.title') ?></h2>
  <?php $sum = fn (array $series) => array_sum(array_column($series, 'value')); ?>
  <h3><?= $t('trend.visits') ?></h3>
  <?= $sum($data['trend']['visits']) === 0 ? Chart::empty() : Chart::area($data['trend']['visits'], I18n::t('trend.visits')) ?>
  <h3 class="small"><?= $t('trend.actions') ?></h3>
  <div class="area-small"><?= $sum($data['trend']['actions']) === 0 ? Chart::empty(I18n::t('trend.noActions')) : Chart::area($data['trend']['actions'], I18n::t('trend.actions')) ?></div>
  <div class="axis"><?php foreach (array_unique([0, (int) round(($days - 1) / 3), (int) round(2 * ($days - 1) / 3), $days - 1]) as $i): ?><span><?= $shortDate($data['trend']['visits'][$i]['name']) ?></span><?php endforeach; ?></div>
  <?= $table(array_reverse($data['trend']['visits']), I18n::t('trend.day'), I18n::t('tile.visits'), I18n::t('trend.visitsTable')) ?>
  <?= $table(array_reverse($data['trend']['actions']), I18n::t('trend.day'), I18n::t('trend.actionsColumn'), I18n::t('trend.actionsTable')) ?>
</section>

<div class="two">
  <section class="block" aria-labelledby="when-title">
    <h2 id="when-title"><?= $t('when.title') ?></h2>
    <p class="hint"><?= $t('when.hint') ?></p>
    <?php $gridSum = array_sum(array_map('array_sum', $data['weekGrid']['values'])); ?>
    <?php if ($gridSum === 0): ?>
      <?= Chart::empty() ?>
    <?php else: ?>
    <div class="scroll" tabindex="0" role="region" aria-label="<?= $t('when.region') ?>"><?= Chart::grid($data['weekGrid']['values'], $data['weekGrid']['rows'], $data['weekGrid']['columns']) ?></div>
    <p class="grid-legend" aria-label="<?= $t('when.legend') ?>"><?= $t('when.few') ?> <span class="shade-1"></span><span class="shade-2"></span><span class="shade-3"></span><span class="shade-4"></span><span class="shade-5"></span> <?= $t('when.many') ?></p>
    <p class="hint scroll-hint"><?= $t('when.swipe') ?></p>
    <details class="as-table"><summary><?= $t('table.summary') ?></summary>
      <div class="scroll" tabindex="0" role="region" aria-label="<?= $t('when.region') ?>"><table class="grid-table"><thead><tr><th scope="col"><?= $t('trend.day') ?></th>
        <?php foreach ($data['weekGrid']['columns'] as $hour): ?><th scope="col" class="num"><?= $e($hour) ?></th><?php endforeach; ?>
      </tr></thead><tbody>
        <?php foreach ($data['weekGrid']['rows'] as $ri => $rowName): ?>
          <tr><th scope="row"><?= $e($rowName) ?></th><?php foreach ($data['weekGrid']['values'][$ri] as $v): ?><td class="num"><?= (int) $v ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
      </tbody></table></div>
    </details>
    <?php endif; ?>
  </section>
  <section class="block" aria-labelledby="devices-title">
    <h2 id="devices-title"><?= $t('devices.title') ?></h2>
    <?= array_sum(array_column($data['devices'], 'value')) === 0 ? Chart::empty() : Chart::bars($data['devices']) ?>
    <?= $table($data['devices'], I18n::t('devices.column'), I18n::t('tile.visits')) ?>
    <?php if (($data['devicesUnknown'] ?? 0) > 0): ?><p class="hint"><?= $t('devices.unknown', ['n' => Chart::number($data['devicesUnknown'])]) ?></p><?php endif; ?>
  </section>
</div>

<div class="two">
  <section class="block" aria-labelledby="sources-title">
    <h2 id="sources-title"><?= $t('sources.title') ?></h2>
    <?= Chart::bars($data['sources']) ?>
    <?= $table($data['sourcesTable'], I18n::t('sources.column'), I18n::t('tile.pageViews')) ?>
  </section>
  <section class="block" aria-labelledby="pages-title">
    <h2 id="pages-title"><?= $t('pages.title') ?></h2>
    <?= Chart::bars($data['pages']) ?>
    <?= $table($data['pages'], I18n::t('pages.column'), I18n::t('tile.pageViews')) ?>
  </section>
</div>

<section class="block" aria-labelledby="entries-title">
  <h2 id="entries-title"><?= $t('entries.title') ?></h2>
  <p class="hint"><?= $t('entries.hint') ?></p>
  <?= Chart::bars($data['entries']) ?>
  <?= $table($data['entries'], I18n::t('entries.column'), I18n::t('tile.visits')) ?>
</section>

<?php if ($data['dwellTime'] !== null): ?>
<section class="block" aria-labelledby="dwell-title">
  <h2 id="dwell-title"><?= $t('dwell.title') ?></h2>
  <?php if ($data['dwellTime']['firstMeasurement'] === null): ?>
  <p class="empty"><?= $t('dwell.none') ?></p>
  <?php else: ?>
  <h3><?= $t('dwell.median') ?></h3>
  <p class="tile-value"><?= $e(Chart::duration($data['dwellTime']['median'])) ?></p>
  <p class="tile-comparison comparison-<?= $e($data['dwellTime']['comparison']['class']) ?>"><?= $e($data['dwellTime']['comparison']['text']) ?></p>
  <h3><?= $t('dwell.distribution') ?></h3>
  <?= Chart::bars($data['dwellTime']['buckets']) ?>
  <?= $table($data['dwellTime']['buckets'], I18n::t('dwell.column'), I18n::t('tile.visits')) ?>
  <h3><?= $t('dwell.perPage') ?></h3>
  <?php if ($data['dwellTime']['pages'] === []): ?><?= Chart::empty() ?><?php else: ?>
  <table><thead><tr><th scope="col"><?= $t('pages.column') ?></th><th scope="col" class="num"><?= $t('dwell.medianColumn') ?></th><th scope="col" class="num"><?= $t('dwell.measurements') ?></th></tr></thead><tbody>
    <?php foreach ($data['dwellTime']['pages'] as $p): ?><tr><td><?= $e($p['name']) ?></td><td class="num"><?= $e(Chart::duration($p['median'])) ?></td><td class="num"><?= Chart::number($p['measurements']) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
  <p class="hint"><?= $t('dwell.hint', ['date' => I18n::date(new \DateTimeImmutable($data['dwellTime']['firstMeasurement']))]) ?></p>
  <?php endif; ?>
</section>
<?php endif; ?>

<details class="block">
  <summary><?= $t('positions.title') ?></summary>
  <p class="hint"><?= $t('positions.hint') ?></p>
  <?php if ($data['positions'] === []): ?><?= Chart::empty() ?><?php else: ?>
  <table><thead><tr><th scope="col"><?= $t('actions.column') ?></th><th scope="col"><?= $t('positions.column') ?></th><th scope="col" class="num"><?= $t('positions.clicks') ?></th></tr></thead><tbody>
    <?php foreach ($data['positions'] as $p): ?><tr><td><?= $e($p['name']) ?></td><td><?= $e($p['position']) ?></td><td class="num"><?= Chart::number($p['value']) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</details>

<?php if ($data['imagesActive'] || $data['images'] !== []): ?>
<section class="block" aria-labelledby="images-title">
  <h2 id="images-title"><?= $t('images.title') ?></h2>
  <p class="hint"><?= $t('images.hint') ?></p>
  <?php if ($data['images'] === []): ?><?= Chart::empty(I18n::t('images.none')) ?><?php else: ?>
  <table><thead><tr><th scope="col"><?= $t('images.column') ?></th><th scope="col"><?= $t('pages.column') ?></th><th scope="col" class="num"><?= $t('images.opened') ?></th></tr></thead><tbody>
    <?php foreach ($data['images'] as $i): ?><tr><td><?= $e($i['name']) ?></td><td><?= $e($i['page']) ?></td><td class="num"><?= Chart::number($i['value']) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="long-term block" aria-labelledby="long-term-title">
  <h2 id="long-term-title"><?= $t('longTerm.title') ?></h2>
  <p class="hint"><?= $t('longTerm.hint') ?></p>
  <dl class="long-term-totals">
    <div><dt><?= $t('tile.pageViews') ?></dt><dd><?= Chart::number((int) ($data['longTerm']['total']['views'] ?? 0)) ?></dd></div>
    <div><dt><?= $t('trend.actionsColumn') ?></dt><dd><?= Chart::number((int) ($data['longTerm']['total']['clicks'] ?? 0)) ?></dd></div>
    <div><dt><?= $t('tile.visits') ?></dt><dd><?= Chart::number((int) ($data['longTerm']['total']['devices'] ?? 0)) ?></dd></div>
  </dl>
  <h3><?= $t('longTerm.perMonth') ?></h3>
  <?= $sum($data['longTerm']['months']) === 0 ? Chart::empty() : Chart::columns($data['longTerm']['months'], I18n::t('longTerm.perMonth')) ?>
  <?= $table($data['longTerm']['months'], I18n::t('longTerm.month'), I18n::t('tile.visits')) ?>
</section>

<details class="technical">
  <summary><?= $t('technical.title') ?></summary>
  <h3><?= $t('technical.notFound') ?></h3>
  <?php if ($data['technical']['notFound'] === []): ?><p class="empty"><?= $t('technical.noneInPeriod') ?></p><?php else: ?>
  <table><thead><tr><th scope="col"><?= $t('technical.address') ?></th><th scope="col" class="num"><?= $t('tile.pageViews') ?></th></tr></thead><tbody>
    <?php foreach ($data['technical']['notFound'] as $n): ?><tr><td><?= $e($n['path']) ?></td><td class="num"><?= (int) $n['count'] ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
  <h3><?= $t('technical.howTitle') ?></h3>
  <p><?= $t('technical.how') ?></p>
  <p><?= $t($failed ? 'technical.storageProblem' : 'technical.storageOk') ?></p>
</details>
<?php endif; ?>
</main>
</body>
</html>
