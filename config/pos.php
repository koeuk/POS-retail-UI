<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Business timezone
    |--------------------------------------------------------------------------
    |
    | Timestamps are stored in UTC, as they should be. But "today's sales" is a
    | question about the shop's day, not Greenwich's: a sale rung up at 06:00 in
    | Phnom Penh happened on 23:00 UTC the day before, and reporting it under
    | yesterday makes the morning's takings vanish from the dashboard.
    |
    | Every report and every order number resolves its day through this.
    |
    */

    'business_timezone' => env('POS_BUSINESS_TIMEZONE', config('app.timezone')),

];
