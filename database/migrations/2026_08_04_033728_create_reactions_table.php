<?php

use App\Models\User;
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
        Schema::create('reactions', function (Blueprint $table): void {
            $table->id();
            // Posts and comments are both reactable, so the target is stored
            // polymorphically rather than as one foreign key per type.
            $table->morphs('reactable');
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->timestamps();

            // One reaction per person per thing — switching reaction replaces
            // the row, and the database is what guarantees it under a double
            // click or two tabs racing each other. The index also answers
            // "what did this reader pick", which every read does; `morphs()`
            // has already indexed the target on its own for the count query.
            $table->unique(['reactable_type', 'reactable_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reactions');
    }
};
