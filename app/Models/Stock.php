<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stock extends Model
{
    use HasFactory;

    public function ingredient() : BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'ingredient_id');
    }
}
