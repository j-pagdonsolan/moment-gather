<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photos', function (Blueprint $table): void {
            $table->string('optimized_path', 512)->nullable()->after('original_path');
            $table->string('thumbnail_path', 512)->nullable()->after('optimized_path');
        });
    }

    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table): void {
            $table->dropColumn(['optimized_path', 'thumbnail_path']);
        });
    }
};
