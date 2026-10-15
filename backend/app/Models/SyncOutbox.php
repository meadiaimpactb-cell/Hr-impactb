<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncOutbox extends Model
{
    protected $table = 'sync_outbox';

    protected $guarded = ['id'];

    public const STATUSES = ['queued' => 'بانتظار الإرسال', 'sent' => 'أُرسل', 'failed' => 'فشل', 'skipped' => 'تخطّي'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'response' => 'array', 'run_after' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
