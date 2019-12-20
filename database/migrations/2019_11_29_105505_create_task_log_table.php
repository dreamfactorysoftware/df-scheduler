<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTaskLogTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create(
            'task_log',
            function (Blueprint $t){
                $t->integer('task_id')->unsigned()->primary();
                $t->foreign('task_id')->references('id')->on('scheduler_tasks')->onDelete('cascade');
                $t->integer('status_code')->unsigned()->default(500);
                $t->mediumText('content')->nullable();
                $t->timestamp('created_date')->nullable();
                $t->timestamp('last_modified_date')->useCurrent();
            }
        );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('task_log');
    }
}
