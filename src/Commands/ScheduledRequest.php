<?php

namespace DreamFactory\Core\Scheduler\Commands;

use DreamFactory\Core\Enums\DataFormats;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Scheduler\Components\RunAsSession;
use DreamFactory\Core\Utility\FileUtilities;
use Illuminate\Console\Command;
use ServiceManager;

/**
 * Console runner for scheduled tasks.
 *
 * Mirrors df-core's `df:request`, but optionally establishes a real DreamFactory
 * session (app + optional user) before dispatching. That single step makes the
 * scheduled task behave like a normal authenticated API request:
 *   - lookups ({name}, {app.api_key}, private lookups) resolve, because
 *     Session::setSessionData() runs setSessionLookups(); and
 *   - the script's internal platform.api calls pass RBAC, because the run-as
 *     role's role.services are loaded into the session.
 *
 * With no --app-id/--user-id this is behaviourally identical to df:request, so
 * legacy (session-less) tasks are unaffected.
 */
class ScheduledRequest extends Command
{
    /** @var string */
    protected $signature = 'df:scheduled-request {data?} {--verb=get} {--service=system} {--resource=} {--format=json} {--app-id=} {--user-id=}';

    /** @var string */
    protected $description = 'Perform a scheduled request on a service, optionally under a run-as identity.';

    public function handle()
    {
        try {
            $data = $this->argument('data');
            if (filter_var($data, FILTER_VALIDATE_URL)) {
                $data = FileUtilities::importUrlFileToTemp($data);
            }
            if (is_file($data)) {
                $data = file_get_contents($data);
            }

            $format = DataFormats::toNumeric($this->option('format'));
            $verb = strtoupper($this->option('verb'));
            $service = $this->option('service');
            $resource = $this->option('resource');

            $this->establishRunAsSession();

            // Top-level dispatch stays privileged: an admin deliberately scheduled
            // this task, so it runs regardless of whether the run-as role could
            // call the target service directly. The run-as role scopes the
            // script's *internal* platform.api calls (handleServiceRequest checks
            // permissions against the session we just set up).
            $result = ServiceManager::handleRequest($service, $verb, $resource, [], [], $data, $format, false);

            if ($result->getStatusCode() >= 300) {
                $this->error(print_r($result, true));
                throw new InternalServerErrorException($result->getContent()['error']['message'], $result->getStatusCode());
            }

            $this->info(print_r($result, true));
            $this->info('Request complete!');
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            throw new InternalServerErrorException($e->getMessage());
        }
    }

    /**
     * Build a session from the optional run-as identity. No-op when neither id is
     * supplied, preserving the legacy session-less behavior.
     */
    protected function establishRunAsSession()
    {
        RunAsSession::apply($this->option('app-id') ?: null, $this->option('user-id') ?: null);
    }
}
