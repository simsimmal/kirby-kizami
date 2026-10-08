<?php

namespace Kizami;

/**
 * Dashboard fonts from the configuration (`kizami.fonts`), so no site has to
 * change package files for them. Kirby-free.
 *
 *     'kizami.fonts' => [
 *         'serif' => ['name' => 'Source Serif 4', 'file' => '/assets/fonts/source-serif-4-latin.woff2'],
 *         'sans'  => ['name' => 'Source Sans 3',  'file' => '/assets/fonts/source-sans-3-latin.woff2'],
 *     ],
 *
 * `file` is optional: without it the entry uses a font that is already
 * loaded elsewhere (for example 'serif' => ['name' => 'Source Sans 3'] for a
 * site that is sans-serif throughout) or installed on the device.
 *
 * Checked strictly, because the result ends up in CSS: name only letters,
 * digits, spaces and hyphens; file only an own path to .woff2/.woff without
 * "..". Invalid entries are dropped entirely (falling back to the system
 * fonts from dashboard.css) instead of being half written.
 */
final class Fonts
{
    private const FALLBACK = ['serif' => 'Georgia, "Times New Roman", serif', 'sans' => '-apple-system, system-ui, "Segoe UI", sans-serif'];

    /** @return array{css:string,errors:list<string>} */
    public static function css(mixed $fonts): array
    {
        $css = '';
        $vars = [];
        $errors = [];
        $loaded = [];
        foreach (self::FALLBACK as $role => $fallback) {
            $entry = is_array($fonts) ? ($fonts[$role] ?? null) : null;
            if ($entry === null) {
                continue;
            }
            $name = is_array($entry) ? ($entry['name'] ?? null) : null;
            $file = is_array($entry) ? ($entry['file'] ?? null) : null;
            if (!is_string($name) || !preg_match('/^[\p{L}\p{N} \-]{1,60}$/u', $name)) {
                $errors[] = "fonts.$role: invalid name";
                continue;
            }
            if ($file !== null && (!is_string($file) || !preg_match('#^/[A-Za-z0-9._/\-]+\.(woff2|woff)$#', $file) || str_contains($file, '..'))) {
                $errors[] = "fonts.$role: invalid file";
                continue;
            }
            if (is_string($file) && !isset($loaded[$name . "\0" . $file])) {
                $format = str_ends_with($file, '.woff2') ? 'woff2' : 'woff';
                $css .= '@font-face { font-family: "' . $name . '"; src: url("' . $file . '") format("' . $format . '"); font-display: swap; }' . "\n";
                $loaded[$name . "\0" . $file] = true;
            }
            $vars[] = "--$role: \"$name\", $fallback;";
        }
        if ($vars !== []) {
            $css .= ':root { ' . implode(' ', $vars) . " }\n";
        }
        return ['css' => $css, 'errors' => $errors];
    }
}
