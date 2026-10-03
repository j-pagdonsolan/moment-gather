<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('plan_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->unsignedInteger('max_active_events')->nullable();
            $table->unsignedInteger('max_photos_per_event')->nullable();
            $table->unsignedBigInteger('max_storage_bytes')->nullable();
            $table->unsignedInteger('price')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('plan_overrides');
    }
};
