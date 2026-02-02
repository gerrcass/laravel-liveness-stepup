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
        Schema::table('user_faces', function (Blueprint $table) {
            $table->string('registration_method')->default('image')->after('user_id'); // 'image' or 'liveness'
            $table->json('liveness_data')->nullable()->after('face_data'); // Store liveness session data
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_faces', function (Blueprint $table) {
            $table->dropColumn(['registration_method', 'liveness_data']);
        });
    }
};
