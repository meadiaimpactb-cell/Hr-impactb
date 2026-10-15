<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailThread extends Model
{
    protected $guarded = ["id"];

    protected function casts(): array
    {
        return ["participants" => "array", "read_by" => "array", "last_at" => "datetime"];
    }

    public function messages() { return $this->hasMany(MailMessage::class, "thread_id")->orderBy("id"); }
}
