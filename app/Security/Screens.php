<?php

namespace App\Security;

use App\Models\User;
use InvalidArgumentException;

/**
 * Reads config/screens.php: which screens exist, the permission each level maps to, and
 * what a given user may see. Permissions are `{screen}.{level}`; levels are cumulative.
 */
final class Screens
{
    /** Lowest to highest. Holding a level means holding every level below it. */
    public const LEVELS = ['view', 'edit', 'approve'];

    /** @return array<string, array> */
    public static function all(): array
    {
        return config('screens', []);
    }

    public static function permission(string $screen, string $level): string
    {
        self::assertLevel($screen, $level);

        return "{$screen}.{$level}";
    }

    /**
     * Every permission a role needs to hold $level on $screen: that level and each
     * declared level below it.
     *
     * @return list<string>
     */
    public static function grantsFor(string $screen, string $level): array
    {
        self::assertLevel($screen, $level);

        $grants = [];
        foreach (self::LEVELS as $candidate) {
            if (in_array($candidate, self::levels($screen), true)) {
                $grants[] = "{$screen}.{$candidate}";
            }
            if ($candidate === $level) {
                break;
            }
        }

        return $grants;
    }

    /** The highest level $user holds on $screen, or null. A signed-in screen is 'view'. */
    public static function levelOf(User $user, string $screen): ?string
    {
        $config = self::screen($screen);

        if ($config['signed_in'] ?? false) {
            return 'view';
        }

        foreach (array_reverse(self::LEVELS) as $level) {
            if (in_array($level, $config['levels'], true) && $user->can("{$screen}.{$level}")) {
                return $level;
            }
        }

        return null;
    }

    public static function allows(User $user, string $screen, string $level): bool
    {
        $held = self::levelOf($user, $screen);

        return $held !== null
            && array_search($held, self::LEVELS, true) >= array_search($level, self::LEVELS, true);
    }

    /**
     * The sidebar: every screen $user may at least view, in registry order.
     *
     * @return list<array{key: string, label: string, route: string, group: string, icon: string}>
     */
    public static function visibleFor(User $user): array
    {
        $items = [];
        foreach (self::all() as $key => $config) {
            if (self::levelOf($user, $key) !== null) {
                $items[] = ['key' => $key] + array_intersect_key($config, array_flip(['label', 'route', 'group', 'icon', 'active']));
            }
        }

        return $items;
    }

    /** @return list<string> every permission the registry defines */
    public static function allPermissions(): array
    {
        $permissions = [];
        foreach (self::all() as $key => $config) {
            foreach ($config['levels'] as $level) {
                $permissions[] = "{$key}.{$level}";
            }
        }

        return $permissions;
    }

    /** @return list<string> */
    private static function levels(string $screen): array
    {
        return self::screen($screen)['levels'];
    }

    private static function screen(string $screen): array
    {
        return self::all()[$screen]
            ?? throw new InvalidArgumentException("Unknown screen [{$screen}]. Add it to config/screens.php.");
    }

    private static function assertLevel(string $screen, string $level): void
    {
        if (! in_array($level, self::levels($screen), true)) {
            throw new InvalidArgumentException("Screen [{$screen}] has no [{$level}] level.");
        }
    }
}
