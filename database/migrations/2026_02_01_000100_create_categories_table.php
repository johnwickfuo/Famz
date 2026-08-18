<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();

            // Self-referencing tree. Deleting a parent takes its children with
            // it, which is what an administrator pruning a branch expects.
            $table->foreignId('parent_id')->nullable()->constrained('categories')->cascadeOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon', 64)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['parent_id', 'sort_order']);
            $table->index(['is_active', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
