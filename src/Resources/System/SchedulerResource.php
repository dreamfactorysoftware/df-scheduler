<?php

namespace DreamFactory\Core\Scheduler\Resources\System;

use DreamFactory\Core\Scheduler\Models\SchedulerTask;
use DreamFactory\Core\System\Resources\BaseSystemResource;

class SchedulerResource extends BaseSystemResource
{
    /**
     * @var \DreamFactory\Core\Scheduler\Models\SchedulerTask
     */
    protected static $model = SchedulerTask::class;


    //*************************************************************************
    //* Methods
    //*************************************************************************

    /**
     * Create a new SchedulerResource
     *
     * @param array $settings settings array
     *
     * @throws \InvalidArgumentException
     */
    public function __construct($settings)
    {
        parent::__construct($settings);
        $this->schedulerTask = new static::$model;
    }
}
