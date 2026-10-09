<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sql_relations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sql_schema_id');
            $table->unsignedBigInteger('from_column_id');
            $table->unsignedBigInteger('to_column_id');
            $table->string('type', 32)->default('one_to_many');
            $table->string('on_delete', 32)->nullable();
            $table->string('on_update', 32)->nullable();
            $table->timestamps();

            $table->foreign('sql_schema_id')->references('id')->on('sql_schemas')->cascadeOnDelete();
            $table->foreign('from_column_id')->references('id')->on('sql_columns')->cascadeOnDelete();
            $table->foreign('to_column_id')->references('id')->on('sql_columns')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sql_relations');
    }
};
