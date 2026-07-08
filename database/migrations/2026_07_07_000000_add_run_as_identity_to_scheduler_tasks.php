<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an optional "run as" identity (app + optional user) to scheduler tasks.
 * When set, the scheduled task runs with a real session (role + lookups) instead
 * of the legacy session-less context. Both columns nullable => fully backward
 * compatible: null identity reproduces the current behavior exactly.
 */
class AddRunAsIdentityToSchedulerTasks extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('scheduler_tasks')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        // sqlsrv chokes on multiple cascade/set-null paths to the same table
        $onDelete = (('sqlsrv' === $driver) ? 'no action' : 'set null');

        Schema::table('scheduler_tasks', function (Blueprint $t) use ($onDelete) {
            if (!Schema::hasColumn('scheduler_tasks', 'app_id')) {
                $t->integer('app_id')->unsigned()->nullable()->after('service_id');
                $t->foreign('app_id')->references('id')->on('app')->onDelete($onDelete);
            }
            if (!Schema::hasColumn('scheduler_tasks', 'user_id')) {
                $t->integer('user_id')->unsigned()->nullable()->after('app_id');
                $t->foreign('user_id')->references('id')->on('user')->onDelete($onDelete);
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('scheduler_tasks')) {
            return;
        }

        Schema::table('scheduler_tasks', function (Blueprint $t) {
            if (Schema::hasColumn('scheduler_tasks', 'app_id')) {
                $t->dropForeign(['app_id']);
                $t->dropColumn('app_id');
            }
            if (Schema::hasColumn('scheduler_tasks', 'user_id')) {
                $t->dropForeign(['user_id']);
                $t->dropColumn('user_id');
            }
        });
    }
}
