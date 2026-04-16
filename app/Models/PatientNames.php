<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PatientNames extends Model
{
    protected $connection = 'pharmasys';
    protected $table = 'patient_names';
    protected $primaryKey = 'patient_id';
    public $incrementing = true;
    
    public $timestamps = false;

    protected $keyType = 'int';
    protected $fillable = [
        'lastname',
        'firstname',
        'middlename',
        'suffix',
    ];
    public function setLastnameAttribute($value)
    {
        $this->attributes['lastname'] = strtoupper($value);
    }

    public function setFirstnameAttribute($value)
    {
        $this->attributes['firstname'] = strtoupper($value);
    }

    public function setMiddlenameAttribute($value)
    {
        $this->attributes['middlename'] = $value ? strtoupper($value) : null;
    }

    public function setSuffixAttribute($value)
    {
        $this->attributes['suffix'] = $value ? strtoupper($value) : null;
    }
        public function setHouseAddressAttribute($value)
    {
        $this->attributes['house_address'] = strtoupper($value);
    }
    public function setProvinceAttribute($value)
    {
        $this->attributes['province'] = strtoupper($value);
    }
    public function setCityAttribute($value)
    {
        $this->attributes['city'] = strtoupper($value);
    }
    public function setSexAttribute($value)
    {
        $this->attributes['sex'] = strtoupper($value);
    }
}
