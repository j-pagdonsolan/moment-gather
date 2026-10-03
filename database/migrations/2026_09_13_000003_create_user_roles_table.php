<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['user_id', 'role_id']);
            $table->index('user_id');
            $table->foreign('user_id', 'user_roles_user_id_foreign')
                  ->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('role_id', 'user_roles_role_id_foreign')
                  ->references('id')->on('roles')->cascadeOnDelete();
        });
    }
    public function down(): void {
        Schema::dropIfExists('user_roles');
    }
};
