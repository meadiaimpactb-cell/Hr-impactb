<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailMessage extends Model
{
    protected $guarded = ["id"];

    public function sender() { return $this->belongsTo(User::class, "from_user_id"); }
}
