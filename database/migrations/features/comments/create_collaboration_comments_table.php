<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SolutionForest\Collaboration\Support\Database;

/** Comments on a record, or on one of its fields: only with `Features::comments()` on. */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Database::connectionName();
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        // Version 0.1.0 created it with the other tables.
        if ($schema->hasTable(Database::tableName('comments'))) {
            return;
        }

        $schema->create(Database::tableName('comments'), function (Blueprint $table): void {
            $table->id();
            $table->char('room', 64);
            $table->string('field')->nullable();
            $table->text('body');
            $table->string('user_type');
            $table->string('user_id', 64);
            $table->string('user_name');
            $table->text('mentions');
            $table->string('resolved_by')->nullable();
            $table->unsignedBigInteger('resolved_at')->nullable();
            $table->unsignedBigInteger('created_at');
            $table->index(['room', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists(Database::tableName('comments'));
    }
};
