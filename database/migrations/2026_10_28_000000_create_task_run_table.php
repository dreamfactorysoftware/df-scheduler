<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-run history for scheduled tasks. Additive: task_log keeps its
 * "last failure only" meaning.
 */
class CreateTaskRunTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('task_run')) {
            return;
        }

        Schema::create(
            'task_run',
            function (Blueprint $t) {
                $t->increments('id');
                $t->integer('task_id')->unsigned();
                $t->foreign('task_id')->references('id')->on('scheduler_tasks')->onDelete('cascade');
                $t->timestamp('started_at')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->integer('status_code')->unsigned()->nullable();
                $t->mediumText('output')->nullable();
                $t->index(['task_id', 'id']);
            }
        );
    }

    public function down()
    {
        Schema::dropIfExists('task_run');
    }
}
