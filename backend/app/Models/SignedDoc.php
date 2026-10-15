<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SignedDoc extends Model
{
    protected $guarded = ["id"];

    public function signer() { return $this->belongsTo(User::class, "signed_by"); }
}
