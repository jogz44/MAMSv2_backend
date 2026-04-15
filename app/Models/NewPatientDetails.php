<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewPatientDetails extends Model
{
    protected $connection = 'pharmasys';
    protected $table = 'new_patient_details';
    protected $primaryKey = 'gl_no';
    public $incrementing = true;
    
    public $timestamps = false;

    protected $keyType = 'int';

    protected $fillable = [
        'patient_id',
        'category',
        'age',
        'sex',
        'preference',
        'province',
        'city',
        'barangay',
        'house_address',
        'partner',
        'hospital_bill',
        'issued_amount',
        'issued_by',
        'issued_at',
    ];
    public function setCategoryAttribute($value)
    {
        $this->attributes['category'] = strtoupper($value);
    }
    public function setIssuedByAttribute($value)
    {
        $this->attributes['issued_by'] = strtoupper($value);
    }
    public function setPartnerAttribute($value)
    {
        $this->attributes['partner'] = strtoupper($value);
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
