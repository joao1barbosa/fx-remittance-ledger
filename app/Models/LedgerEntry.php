<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One persisted double-entry posting in the auditable ledger read-model. Written
 * only by the LedgerEntryProjector (delete-by-aggregate + insert); rebuilt by replay.
 */
final class LedgerEntry extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
