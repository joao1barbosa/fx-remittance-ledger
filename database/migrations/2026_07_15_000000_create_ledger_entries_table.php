<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The double-entry ledger materialized as an auditable read-model. Fed by a Spatie
// projector, rebuilt by replay — never a manual balance UPDATE. One row per posting.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('aggregate_uuid')->index();
            $table->string('account');
            $table->bigInteger('debit');
            $table->bigInteger('credit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
