<?php

namespace Kizami;

/**
 * Dashboard access. Deliberately split in two:
 *
 * - checkFile() is Kirby-free (bcrypt against site/config/kizami.php) and
 *   tested here.
 * - The Panel branch (kirby()->user()) and the preview detection live in
 *   index.php, because they need Kirby.
 *
 * IMPORTANT: if the configuration is missing or empty, access is DENIED
 * (false), not granted. Open statistics on the web would be worse than an
 * unreachable dashboard.
 */
final class Access
{
    /**
     * @param array{user?:string,hash?:string}|null $credentials contents of kizami.php
     */
    public static function checkFile(?array $credentials, ?string $user, ?string $password): bool
    {
        $expectedUser = $credentials['user'] ?? '';
        $expectedHash = $credentials['hash'] ?? '';

        if (!is_string($expectedUser) || !is_string($expectedHash) || $expectedUser === '' || $expectedHash === '') {
            return false; // not set up → fail closed
        }
        if ($user === null || $password === null) {
            return false;
        }

        // hash_equals against timing attacks on the user name; password_verify
        // is constant-time anyway.
        return hash_equals($expectedUser, $user)
            && password_verify($password, $expectedHash);
    }
}
