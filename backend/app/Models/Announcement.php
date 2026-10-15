<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $guarded = ["id"];

    protected function casts(): array
    {
        return ["audience" => "array", "require_ack" => "boolean", "published_at" => "datetime", "expires_at" => "date:Y-m-d"];
    }

    public function sender() { return $this->belongsTo(User::class, "sender_id"); }
    public function receipts() { return $this->hasMany(AnnouncementReceipt::class); }
}
