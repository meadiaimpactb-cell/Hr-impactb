<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailDraft extends Model
{
    protected $fillable = ['user_id', 'to', 'subject', 'body', 'group'];

    protected $casts = ['to' => 'array', 'group' => 'boolean'];
}
