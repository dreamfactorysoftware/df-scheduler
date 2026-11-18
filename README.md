# df-scheduler
DreamFactory Scheduler Configuration Package

This is a system service library for the DreamFactory platform containing API for the [Scheduler](https://laravel.com/docs/master/scheduling).
This is an add on to the DreamFactory Core library and requires the [df-core repository] (http://github.com/dreamfactorysoftware/df-core).


## Overview

DreamFactory is a secure, self-hosted enterprise data access platform that provides governed API access to any data source, connecting enterprise applications and on-prem LLMs with role-based access and identity passthrough.

## This feature requires a cron job to be configured on your system.

```
* * * * * cd /opt/dreamfactory/ && php artisan schedule:run >> /dev/null 2>&1 
```

This package will try to add it automatically, but it is your responsibility to make sure that system has the cron job. 
Otherwise, tasks would not be scheduled.

The entry should exist once per host. Each OS user has its own crontab, so the entry is added for one user only:
when an artisan command runs, the first user that has or adds the entry on the host is recorded in
`storage/crontab.owner.json` (one record per host name), and artisan runs as other users leave their crontabs
alone. On an install that has no record yet, the owner of `storage/crontab.txt`, the file the entry is written
from, is taken as the user that added it; if that cannot be told either, only `root` adds the entry. (If the
recorded user's entry has been removed, artisan run as `root` adds it again for `root`.) Two settings change this:

| Variable | Default | Effect |
|---|---|---|
| `DF_SCHEDULER_CRON_USER` | empty | Add the entry only when artisan runs as this OS user, for example `www-data`. |
| `DF_SCHEDULER_INSTALL_CRON` | `true` | Set to `false` to manage the cron entry yourself. |

When you change the user, remove the entry from the crontab of the previous user (`crontab -u <user> -e`),
otherwise `schedule:run` starts twice a minute and tasks can run twice.

**!Note**: This package will try to change your directory for temporary files 
([sys_get_temp_dir](https://www.php.net/manual/en/function.sys-get-temp-dir.php)) 
to `your/instance/path/storage/logs/tmp` using TMPDIR env variable.

## Overlapping runs

A task does not start while its previous run is still going. During a run the scheduler holds a lock for the
task in the cache. The lock is released when the run ends, and expires on its own after two run intervals of
the task, or 10 minutes if that is longer. For example, the lock of a task that runs every minute expires after
10 minutes, and that of a task that runs every 30 minutes after an hour. If a run is cut short, for example by
a container restart, the task runs again once its lock has expired. A run that lasts longer than this can
overlap with the next one.
