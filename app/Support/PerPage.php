<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * How many rows a paginated screen shows.
 *
 * The value is **whitelisted, not clamped**. `per_page` arrives from the query
 * string, so a hand-edited `?per_page=999999` would otherwise ask the database
 * for every row and hydrate a model for each one. Only the sizes actually
 * offered in the dropdown are accepted; anything else silently falls back.
 */
class PerPage
{
    /** @var list<int> */
    public const OPTIONS = [10, 20, 50, 100, 150, 200];

    public const DEFAULT = 20;

    public static function resolve(Request $request, int $default = self::DEFAULT): int
    {
        $requested = $request->integer('per_page');

        return in_array($requested, self::OPTIONS, true) ? $requested : $default;
    }
}
