<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('module_deployenv_deployments', function (Blueprint $table) {
            $table->id();
            $table->string('module', 255)->comment('name of module like "deploy-env" or null for app')->nullable();
            $table->string('version', 255)->comment('version to remember not deploy this again')->nullable();
            $table->integer('batch')->comment('like migrations to rollback same batches');

            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('module_deployenv_deployments');
    }

};
