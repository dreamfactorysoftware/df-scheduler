<?php

return [
    // Add the schedule:run entry to the host crontab automatically from artisan.
    'install_cron' => env('DF_SCHEDULER_INSTALL_CRON', true),
    // OS user whose crontab gets the entry. Empty: the first user that runs artisan on the host.
    'cron_user'    => env('DF_SCHEDULER_CRON_USER', ''),
];
