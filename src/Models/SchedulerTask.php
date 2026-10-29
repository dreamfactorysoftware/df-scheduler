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

        // ponytail: 3 small indexed queries per task per GET, batch if lists reach hundreds
        $runs = TaskRun::whereTaskId($this->id)->orderByDesc('id');
        $pick = function ($q) {
            $r = $q->first(['id', 'started_at', 'finished_at', 'status_code']);
            return $r ? $r->toArray() : null;
        };
        $finished = (clone $runs)->whereNotNull('finished_at');

        $status['last_run'] = $pick(clone $runs);
        $status['last_success'] = $pick((clone $finished)->where('status_code', '<', 300));
        $status['last_failure'] = $pick((clone $finished)->where('status_code', '>=', 300));
        // 1440 min = withoutOverlapping's default expiry, so a killed process stops reading as running
        $status['is_running'] = $status['last_run'] && empty($status['last_run']['finished_at'])
            && TaskRun::whereKey($status['last_run']['id'])->where('started_at', '>', now()->subMinutes(1440))->exists();

        return $status;
    }
}
