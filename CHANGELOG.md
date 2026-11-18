# Change Log
All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]
- Keep per-run history (`task_run`) and expose `run_status` for scheduled tasks (df-scheduler#41)
- A scheduled task's overlap lock now expires after two run intervals (at least 10 minutes) instead of 24 hours, so a task whose run was cut short (for example by a container restart) resumes on schedule
- The `schedule:run` crontab entry is added for one OS user per host (the first that runs artisan, or `DF_SCHEDULER_CRON_USER`); `DF_SCHEDULER_INSTALL_CRON=false` turns it off
- `GET system/scheduler` accepts `fields` lists without `verb_mask` or `id`; `verb` and `run_status` are left out when the columns they are built from are not selected
