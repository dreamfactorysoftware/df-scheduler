# Change Log
All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]
- Keep per-run history (`task_run`) and expose `run_status` for scheduled tasks (df-scheduler#41)
- A scheduled task's overlap lock now expires after two run intervals (at least 10 minutes) instead of 24 hours, so a task whose run was cut short (for example by a container restart) resumes on schedule
