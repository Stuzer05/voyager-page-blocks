<?php

namespace Pvtl\VoyagerPageBlocks;

use TCG\Voyager\Models\DataRow;
use TCG\Voyager\Traits\Translatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Database\Eloquent\Model;

class PageBlock extends Model
{
    use Translatable;

    protected $translatable = ['data'];

    protected $touches = [
        'page',
    ];

    public static function boot() {
        parent::boot();

        static::deleted(function($model) {
            $model->translations()->delete();
        });
    }

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = [
        'created_at',
        'updated_at',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'data' => 'array',
        'is_hidden' => 'boolean',
        'is_minimized' => 'boolean',
        'is_delete_denied' => 'boolean',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'type',
        'path',
        'data',
        'is_hidden',
        'is_minimized',
        'is_delete_denied',
        'cache_ttl',
    ];

    public function cacheKey()
    {
        return sprintf(
            "%s/%s-%s",
            $this->getTable(),
            $this->getKey(),
            $this->updated_at->timestamp
        );
    }

    public function page()
    {
        return $this->belongsTo('TCG\Voyager\Models\Page');
    }

    // Fetch config for block template
    public function template()
    {
        $templateConfig = Config::get('page-blocks.' . $this->path);

        $templateConfig['fields'] = collect($templateConfig['fields'])
            ->map(function ($row) {
                if ($row['type'] === 'break') {
                    return (object)$row;
                }

                $dataRow = new DataRow();
                $dataRow->field = $row['field'];
                $dataRow->display_name = $row['display_name'];
                $dataRow->type = $row['type'];
                $dataRow->required = $row['required'] ?? 0;
                $dataRow->details = $row['details'] ?? null;
                $dataRow->placeholder = $row['placeholder'] ?? 0;
                $dataRow->translatable = $row['translatable'] ?? 0;

                return $dataRow;
            });

        return (object)$templateConfig;
    }

    public function getDataAttribute($value)
    {
        return json_decode($value);
    }

    public function translatedData($locale) {
        $data = $this->translate($locale)->data;

        if (is_string($data)) {
            return $this->getDataAttribute($data);
        } else {
            return $data;
        }
    }

    public function getCachedDataAttribute()
    {
        return Cache::remember($this->cacheKey() . ':datum', $this->cache_ttl, function () {
            return $this->data;
        });
    }
}
