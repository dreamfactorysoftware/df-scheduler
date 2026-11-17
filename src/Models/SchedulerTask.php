<?php

namespace DreamFactory\Core\Scheduler\Models;

use DreamFactory\Core\Enums\VerbsMask;
use DreamFactory\Core\Models\BaseSystemModel;

/**
 * Scheduled jobs
 */
class SchedulerTask extends BaseSystemModel
{
    /** @var string */
    protected $table = 'scheduler_tasks';

    protected $fillable = [
        'name',
        'description',
        'is_active',
        'service_id',
        'app_id',
        'user_id',
        'component',
        'verb_mask',
        'frequency',
        'payload'
    ];

    protected $required = [
      'name',
      'service_id'
    ];

    protected $casts = [
        'id'             => 'integer',
        'is_active'      => 'boolean',
        'verb_mask'      => 'integer',
        'service_id'     => 'integer',
        'app_id'         => 'integer',
        'user_id'        => 'integer'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['verb', 'run_status'];

    /** Shortest time, in minutes, a task's overlap lock is held. */
    const OVERLAP_LOCK_MIN_MINUTES = 10;

    /** Run history is written only by the scheduler; ignore it in API payloads. */
    public static function create(array $attributes = [])
    {
        unset($attributes['task_run_by_task_id']);

        return parent::create($attributes);
    }

    public function update(array $attributes = [], array $options = [])
    {
        unset($attributes['task_run_by_task_id']);

        return parent::update($attributes, $options);
    }

    /**
     * Get verb.
     *
     * @return bool
     * @throws \DreamFactory\Core\Exceptions\NotImplementedException
     */
    public function getVerbAttribute()
    {
        return VerbsMask::toString($this->attributes['verb_mask']);
    }

    /**
     * Running flag plus last run / success / failure (no output, keeps lists small).
     *
     * @return array
     */
    public function getRunStatusAttribute()
    {
        $status = ['is_running' => false, 'last_run' => null, 'last_success' => null, 'last_failure' => null];
        if (empty($this->id)) {
            return $status;
        }

        // 3 small indexed queries per task per GET; batch them if lists reach hundreds.
        $runs = TaskRun::whereTaskId($this->id)->orderByDesc('id');
        $pick = function ($q) {
            $r = $q->first(['id', 'started_at', 'finished_at', 'status_code']);
            return $r ? $r->toArray() : null;
        };
        $finished = (clone $runs)->whereNotNull('finished_at');

        $status['last_run'] = $pick(clone $runs);
        $status['last_success'] = $pick((clone $finished)->where('status_code', '<', 300));
        $status['last_failure'] = $pick((clone $finished)->where('status_code', '>=', 300));
        // Same window as the overlap lock, so a run whose process died stops reading as running
        $status['is_running'] = $status['last_run'] && empty($status['last_run']['finished_at'])
            && TaskRun::whereKey($status['last_run']['id'])
                ->where('started_at', '>', now()->subMinutes($this->overlapLockMinutes()))->exists();

        return $status;
    }

    /**
     * Minutes the scheduler's overlap lock is held for this task: two run intervals,
     * and at least OVERLAP_LOCK_MIN_MINUTES. While it is held the next run waits.
     * A run that never finished (its process stopped) holds it no longer than this.
     *
     * @return int
     */
    public function overlapLockMinutes()
    {
        // frequency is the minute step in '*/N * * * *'; N of 60 or more fires once an hour.
        $interval = min(max((int)$this->frequency, 1), 60);

        return max(static::OVERLAP_LOCK_MIN_MINUTES, 2 * $interval);
    }
}
