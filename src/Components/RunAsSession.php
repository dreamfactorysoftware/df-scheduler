<?php

namespace DreamFactory\Core\Scheduler\Components;

use DreamFactory\Core\Models\App;
use DreamFactory\Core\Utility\Session;
use Log;

/**
 * Establishes a DreamFactory session for a scheduled task's optional "run as"
 * identity, so the task behaves like a normal authenticated API request:
 *   - lookups ({name}, {app.api_key}, private lookups) resolve, because
 *     Session::setSessionData() runs setSessionLookups(); and
 *   - the script's internal platform.api calls are permission-checked against a
 *     real role, because the run-as role's role.services are loaded.
 *
 * With no identity this is a no-op, preserving the legacy session-less behavior.
 */
class RunAsSession
{
    /**
     * @param int|string|null $appId
     * @param int|string|null $userId
     */
    public static function apply($appId = null, $userId = null)
    {
        $appId = !empty($appId) ? (int)$appId : null;
        $userId = !empty($userId) ? (int)$userId : null;

        if (empty($appId) && empty($userId)) {
            return;
        }

        // Populates app.id, role (+ role.services for RBAC), user, and lookups.
        Session::setSessionData($appId, $userId);

        // Make the app's key available so {app.api_key} and api-key-dependent
        // logic resolve just like an authenticated request.
        if (!empty($appId)) {
            $apiKey = App::getCachedInfo($appId, 'api_key');
            if (!empty($apiKey)) {
                Session::setApiKey($apiKey);
            }
        }

        if (empty(Session::get('role.id'))) {
            Log::warning(
                'Scheduled run-as identity (app_id=' . var_export($appId, true) .
                ', user_id=' . var_export($userId, true) . ') resolved no role; ' .
                'internal platform.api calls will be denied. Check the app has a default role.'
            );
        }
    }
}
