<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthIdentity extends Model
{
    protected $table = 'auth_identities';

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'last_login_at',
        'raw_claims',
    ];

    protected $casts = [
        'raw_claims' => 'array',
        'last_login_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
