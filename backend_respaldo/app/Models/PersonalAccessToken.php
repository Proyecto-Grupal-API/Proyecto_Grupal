<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use MongoDB\Laravel\Eloquent\DocumentModel;

/**
 * Token de acceso personal de Sanctum almacenado nativamente en MongoDB.
 * Extiende SanctumPersonalAccessToken para satisfacer el typehint de Sanctum,
 * e incorpora DocumentModel para operar nativamente sobre MongoDB.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use DocumentModel;

    protected $connection = 'mongodb';
    protected $collection = 'personal_access_tokens';
    protected $primaryKey = '_id';
    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'tokenable_type',
        'tokenable_id',
    ];

    protected $casts = [
        'abilities'    => 'array',
        'last_used_at' => 'datetime',
        'expires_at'   => 'datetime',
    ];

    protected $hidden = [
        'token',
    ];

    public function tokenable()
    {
        return $this->morphTo('tokenable');
    }

    public static function findToken($token)
    {
        if (str_contains($token, '|')) {
            [$id, $plainToken] = explode('|', $token, 2);

            if ($instance = static::find($id)) {
                return hash_equals($instance->token, hash('sha256', $plainToken)) ? $instance : null;
            }
        }

        return static::where('token', hash('sha256', $token))->first();
    }

    public function can($ability)
    {
        return in_array('*', $this->abilities ?? [], true) ||
               in_array($ability, $this->abilities ?? [], true);
    }

    public function cant($ability)
    {
        return ! $this->can($ability);
    }
}
