<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';
    protected $primaryKey = 'ID';
    public $incrementing = true;
    public $timestamps = false;
    protected $fillable = [
        'USERNAME',
        'PASSWORD',
        'ROLE',
        'CATEGORY',
        'PARTNER',
    ];

    protected $hidden = [
        'PASSWORD',
        'REMEMBER_TOKEN',
    ];

    // Override the default password column
    public function getAuthPassword()
    {
        return $this->PASSWORD;
    }

    // Override the default username column
    public function getAuthIdentifierName()
    {
        return 'USERNAME';
    }

    // Override remember token column
    public function getRememberTokenName()
    {
        return 'REMEMBER_TOKEN';
    }
    public function setUsernameAttribute($value)
    {
        $this->attributes['USERNAME'] = strtoupper($value);
    }
}
