<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SolutionForest\Collaboration\Support\Database;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Database::connectionName();
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        // The shared draft of each record, and the log of changes made to it.
        $schema->create(Database::tableName('documents'), function (Blueprint $table): void {
            $table->id();
            $table->char('room', 64)->unique();
            $table->char('parent_room', 64)->nullable()->index();
            $table->unsignedInteger('epoch')->default(1);
            $table->unsignedBigInteger('sequence')->default(0);
            $table->unsignedBigInteger('floor')->default(0);
            $table->unsignedBigInteger('compacted_at_sequence')->default(0);
            $table->unsignedBigInteger('version')->default(0);
            $table->longText('state');
            $table->longText('saved_state');
            $table->longText('saved_hashes');
            $table->text('dirty');
            $table->text('saved_by')->nullable();
            $table->longText('contributors')->nullable();
            $table->timestamps();
        });

        $schema->create(Database::tableName('updates'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained(Database::tableName('documents'))->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->unsignedBigInteger('client');
            $table->longText('operations');
            $table->unique(['document_id', 'sequence']);
        });

        // One row per open tab: who is here, in which field, and where their caret is.
        $schema->create(Database::tableName('participants'), function (Blueprint $table): void {
            $table->id();
            $table->char('room', 64);
            $table->char('scope', 64)->nullable();
            $table->unsignedBigInteger('client');
            $table->string('user_key', 64);
            $table->string('name');
            $table->text('avatar')->nullable();
            $table->string('mode', 16);
            $table->text('field')->nullable();
            $table->text('cursor')->nullable();
            $table->boolean('idle')->default(false);
            $table->char('audience', 16)->nullable();
            $table->text('readable')->nullable();
            $table->unsignedBigInteger('seen_at');
            $table->unique(['room', 'client']);
            $table->index(['room', 'seen_at']);
            $table->index(['scope', 'seen_at']);
        });

        $schema->create(Database::tableName('versions'), function (Blueprint $table): void {
            $table->id();
            $table->char('room', 64);
            $table->string('reason', 16);
            $table->string('user_name')->nullable();
            $table->longText('values');
            $table->unsignedBigInteger('created_at');
            $table->index(['room', 'created_at']);
        });

        $schema->create(Database::tableName('uploads'), function (Blueprint $table): void {
            $table->id();
            $table->char('room', 64);
            $table->string('field');
            $table->string('disk', 64);
            $table->text('path');
            $table->unsignedBigInteger('created_at');
            $table->index('room');
            $table->index('created_at');
        });

        $schema->create(Database::tableName('edits'), function (Blueprint $table): void {
            $table->id();
            $table->char('room', 64);
            $table->string('record_type');
            $table->string('record_key', 64);
            $table->string('user_type');
            $table->string('user_id', 64);
            $table->string('user_name');
            $table->string('field');
            $table->unsignedBigInteger('minute');
            $table->unique(['room', 'user_type', 'user_id', 'field', 'minute']);
            $table->index(['record_type', 'record_key', 'minute']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach (['edits', 'uploads', 'versions', 'participants', 'updates', 'documents'] as $table) {
            $schema->dropIfExists(Database::tableName($table));
        }
    }
};
