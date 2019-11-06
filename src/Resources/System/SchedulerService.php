<?php namespace DreamFactory\Core\Scheduler\Resources;

use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\RestException;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Scheduler\Models\SchedulerConfig;
use DreamFactory\Core\Services\BaseRestService;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Core\Enums\Verbs;

class SchedulerService extends BaseRestService
{
    /**
     * @var \DreamFactory\Core\Scheduler\Models\SchedulerConfig
     */
    protected $schedulerModel = null;


    //*************************************************************************
    //* Methods
    //*************************************************************************

    /**
     * Create a new SchedulerService
     *
     * @param array $settings settings array
     *
     * @throws \InvalidArgumentException
     */
    public function __construct($settings)
    {
        $this->schedulerModel = new SchedulerConfig();
        parent::__construct($settings);
    }

    /**
     * Get scheduled tasks.
     *
     * @return array
     * @throws UnauthorizedException
     */
    protected function handleGET()
    {
        return ["GET"];
    }

    /**
     * Update scheduled task.
     *
     * @return array
     * @throws NotFoundException
     * @throws \Exception
     */
    protected function handlePOST()
    {
        return ["POST"];
    }
}
