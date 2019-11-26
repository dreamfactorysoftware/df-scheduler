<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSchedulerTasksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $driver = Schema::getConnection()->getDriverName();
        $onDelete = (('sqlsrv' === $driver) ? 'no action' : 'set null');

        Schema::create('scheduler_tasks', function (Blueprint $t) use ($onDelete){
            $t->increments('id');
            $t->string('name', 64)->unique();
            $t->string('description')->nullable();
            $t->boolean('is_active')->default(0);
            $t->integer('service_id')->unsigned();
            $t->foreign('service_id')->references('id')->on('service')->onDelete('cascade');
            $t->string('component')->nullable();
            $t->integer('verb_mask')->unsigned()->default(1);
            $t->integer('frequency')->nullable()->default(5);
            $t->mediumText('payload')->nullable();
            $t->timestamp('created_date')->nullable();
            $t->timestamp('last_modified_date')->useCurrent();
            $t->integer('created_by_id')->unsigned()->nullable();
            $t->foreign('created_by_id')->references('id')->on('user')->onDelete($onDelete);
            $t->integer('last_modified_by_id')->unsigned()->nullable();
            $t->foreign('last_modified_by_id')->references('id')->on('user')->onDelete($onDelete);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('scheduler_tasks');
    }
}
