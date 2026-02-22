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
        if (Schema::hasTable('property_images')) {
            Schema::table('property_images', function (Blueprint $table) {
                // Ensure property_id exists, though it should
                if (!Schema::hasColumn('property_images', 'property_id')) {
                    $table->foreignId('property_id')->constrained()->onDelete('cascade');
                }
                
                // Add missing path column
                if (!Schema::hasColumn('property_images', 'path')) {
                    $table->string('path')->nullable();
                }

                // Add missing is_main column
                if (!Schema::hasColumn('property_images', 'is_main')) {
                    $table->boolean('is_main')->default(false);
                }

                // Add missing order column
                if (!Schema::hasColumn('property_images', 'order')) {
                    $table->integer('order')->default(0);
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
