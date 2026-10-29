<?php

namespace DreamFactory\Core\Scheduler\Models;

use DreamFactory\Core\Models\BaseSystemModel;

/**
 * One execution of a scheduled job
 */
class TaskRun extends BaseSystemModel
{
    // ponytail: count cap, make it configurable if a customer asks
    const KEEP_PER_TASK = 100;

    const OUTPUT_LIMIT = 16384;

    protected $table = 'task_run';

    public $timestamps = false;

    protected $fillable = ['task_id', 'started_at', 'finished_at', 'status_code', 'output'];

    protected $casts = [
        'id'          => 'integer',
        'task_id'     => 'integer',
        'status_code' => 'integer',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];
}
