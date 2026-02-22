<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PropertyImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id',
        'path',
        'is_main',
        'order',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * Accesor para obtener la URL completa de la imagen.
     */
    public function getUrlAttribute()
    {
        if (!$this->path) return null;
        if (str_starts_with($this->path, 'http')) {
            return $this->path;
        }
        return asset('storage/' . $this->path);
    }

    /**
     * Compatibilidad con frontend que busca image_url
     */
    public function getImageUrlAttribute()
    {
        return $this->getUrlAttribute();
    }

    protected $appends = ['url', 'image_url'];
}
