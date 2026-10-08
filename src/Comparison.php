<?php

namespace Kizami;

/**
 * Change against the previous period as text. Below 20 events in the
 * previous period there is no percentage: "+300 %" for 3 → 12 actions sounds
 * like success and is noise.
 */
final class Comparison
{
    public const MIN_BASE = 20;

    /**
     * $base overrides which number the minimum-base rule checks — needed when
     * $previous itself is not a quantity (for example a median in seconds for
     * the dwell time). Without $base the rule checks $previous itself
     * (clicks, visits: value and sample size are the same there).
     *
     * @return array{class:string,text:string}
     */
    public static function text(int $current, int $previous, ?int $base = null): array
    {
        if ($current === 0 && $previous === 0) {
            return ['class' => 'neutral', 'text' => '–'];
        }
        if ($previous === 0) {
            return ['class' => 'new', 'text' => I18n::t('comparison.new')];
        }
        if (($base ?? $previous) < self::MIN_BASE) {
            return ['class' => 'neutral', 'text' => I18n::t('comparison.tooLittle')];
        }
        $percent = (int) round(($current - $previous) / $previous * 100);
        if ($percent >= 1000) {
            return ['class' => 'up', 'text' => '▲ ' . I18n::t('comparison.sharplyUp')];
        }
        if ($percent <= -1000) {
            return ['class' => 'down', 'text' => '▼ ' . I18n::t('comparison.sharplyDown')];
        }
        if ($percent > 0) {
            return ['class' => 'up', 'text' => '▲ +' . $percent . ' %'];
        }
        if ($percent < 0) {
            return ['class' => 'down', 'text' => '▼ −' . abs($percent) . ' %'];
        }
        return ['class' => 'neutral', 'text' => '±0 %'];
    }
}
