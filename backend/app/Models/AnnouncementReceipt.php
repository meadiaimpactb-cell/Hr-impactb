<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnnouncementReceipt extends Model
{
    protected $guarded = ["id"];

    protected function casts(): array
    {
        return ["read_at" => "datetime", "ack_at" => "datetime"];
    }

    public function user() { return $this->belongsTo(User::class); }
    public function announcement() { return $this->belongsTo(Announcement::class); }
}
