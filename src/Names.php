<?php

namespace Kizami;

use Closure;

/**
 * Readable names for targets, sources and pages. The core knows no project
 * terms; everything comes from the configuration or from a page-title
 * callback passed in (Kirby page titles), so the class stays testable
 * without Kirby.
 *
 * Source keys are what Store returns: a referrer domain, `utm:<value>`, or
 * one of the tokens below.
 */
final class Names
{
    public const DIRECT = '(direct / unknown)';
    public const INTERNAL = '(internal navigation)';
    public const ENTRY_UNKNOWN = '(entry unknown)';

    public function __construct(
        private readonly array $targetNames,
        private readonly array $sourceNames,
        private readonly array $pageNames,
        private readonly ?Closure $pageTitle = null,
    ) {
    }

    public function target(string $target): string
    {
        return (string) ($this->targetNames[$target] ?? $target);
    }

    public function isInternal(string $source): bool
    {
        return $source === self::INTERNAL;
    }

    public function source(string $source): string
    {
        if ($source === self::DIRECT || $source === '') {
            return I18n::t('source.direct');
        }
        if ($source === self::ENTRY_UNKNOWN) {
            return I18n::t('source.unknown');
        }
        if ($this->isInternal($source)) {
            return I18n::t('source.internal');
        }
        if (str_starts_with($source, 'utm:')) {
            $id = substr($source, 4);
            return (string) ($this->sourceNames[$id] ?? I18n::t('source.campaign', ['name' => $id]));
        }
        if (isset($this->sourceNames[$source])) {
            return (string) $this->sourceNames[$source];
        }
        $withoutWww = preg_replace('/^www\./', '', $source);
        return (string) ($this->sourceNames[$withoutWww] ?? $withoutWww);
    }

    public function page(string $path): string
    {
        if (isset($this->pageNames[$path])) {
            return (string) $this->pageNames[$path];
        }
        if ($path === '/' || $path === '') {
            return I18n::t('page.home');
        }
        $title = $this->pageTitle ? ($this->pageTitle)($path) : null;
        return is_string($title) && $title !== '' ? $title : $path;
    }
}
