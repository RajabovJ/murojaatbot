<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Appeal extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'role',
        'message',
        'is_reviewed',
        'telegram_message_id',
    ];

    protected $casts = [
        'is_reviewed' => 'boolean',
    ];


    public function user()
    {
        return $this->belongsTo(User::class);
    }
}