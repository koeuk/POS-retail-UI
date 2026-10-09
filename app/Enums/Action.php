<?php

namespace App\Enums;

/**
 * What someone may do inside a feature area they can already reach.
 *
 * `Permission` answers "may they open Products at all?" — the wall the route
 * middleware enforces. This answers the next question down: having opened it,
 * may they add, change, or remove a row? Fine-grained rules live here rather
 * than as extra Permission cases, so the enum the Staff dialog renders stays
 * short enough to read at a glance.
 */
enum Action: string
{
    case View = 'view';
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';

    public function label(): string
    {
        return match ($this) {
            self::View => 'View',
            self::Create => 'Add',
            self::Update => 'Edit',
            self::Delete => 'Delete',
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
