<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    protected $table = "imports";

    protected $guarded = ["id"];

    protected function casts(): array
    {
        return ["errors" => "array", "created" => "array"];
    }

    public function user() { return $this->belongsTo(User::class, "by"); }
}
