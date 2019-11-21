# df-scheduler
DreamFactory Scheduler Configuration Package

This is a system service library for the DreamFactory platform containing API for the [Scheduler](https://laravel.com/docs/master/scheduling).
This is an add on to the DreamFactory Core library and requires the [df-core repository] (http://github.com/dreamfactorysoftware/df-core).

## This feature requires a cron job to be configured on your system.

```
* * * * * cd /opt/dreamfactory/ && php artisan schedule:run >> /dev/null 2>&1 
```

This package will try to add it automatically, but it is your responsibility to make sure that system has the cron job. 
Otherwise, tasks would not be scheduled.