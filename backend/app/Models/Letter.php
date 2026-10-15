<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Letter extends Model
{
    protected $guarded = ["id"];

    protected function casts(): array
    {
        return ["includes_salary" => "boolean", "issued_at" => "date:Y-m-d"];
    }

    public function employee() { return $this->belongsTo(Employee::class); }
    public function company() { return $this->belongsTo(Company::class); }
    public function issuer() { return $this->belongsTo(User::class, "issued_by"); }
    public function signedDoc() { return $this->belongsTo(SignedDoc::class, "signed_doc_id"); }
}
