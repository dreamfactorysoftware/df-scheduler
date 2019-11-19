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
        'service_id'     => 'integer'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['verb'];

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
}