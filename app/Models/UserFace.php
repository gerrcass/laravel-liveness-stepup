<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserFace extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'registration_method',
        'collection_name',
        'face_data',
        'liveness_data',
        'verification_status',
        'last_verified_at',
    ];

    protected $casts = [
        'face_data' => 'array',
        'liveness_data' => 'array',
        'last_verified_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
