<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->string('participation_collection_method')->default('bluetick_pay')->change());
    }

    public function down(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->string('participation_collection_method')->default('manual')->change());
    }
};
