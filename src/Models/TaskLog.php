<?php

namespace DreamFactory\Core\Scheduler\Models;

use DreamFactory\Core\Models\BaseSystemModel;

/**
 * Scheduled jobs
 */
class TaskLog extends BaseSystemModel
{
    protected $table = 'task_log';

    protected $primaryKey = 'task_id';

    protected $fillable = ['task_id', 'status_code', 'content'];

    protected $hidden = ['id'];

    protected $casts = ['task_id' => 'integer', 'status_code' => 'integer'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
    }
}