<?php

namespace Pvtl\VoyagerPageBlocks;

use Illuminate\Database\Eloquent\Model;
use TCG\Voyager\Traits\Translatable;

class MockedDataModel extends Model
{
    use Translatable;

    protected $translatable = [];
    protected $translations = [];

    public function __construct($field, $data = null)
    {
        if(!is_object($field)) return [];

        if ($field->translatable) {
            $this->translatable = [ $field->field ];
        }

        $this->{$field->field} = $data->{$field->field} ?? null;

        return $this;
    }

    public function setTranslations($translations) {
        foreach ($translations as $trans) {
            $locale = $trans->locale;
            if (!array_key_exists($locale, $this->translations)) $this->translations[$locale] = collect();

            $trans_data = json_decode($trans->value, true);
            foreach ($trans_data as $field => $data) {
                $this->translations[$locale][$field] = $data;
            }
        }
    }

    public function getTranslationsOf($field_needed) {
        $default_locale = config('voyager.multilingual.default', 'en');

        $translations = [];
        foreach ($this->translations as $locale => $trans) {
            $translations[$locale] = isset($trans[$field_needed]) ? $trans[$field_needed] : $this->$field_needed;
        }

        $translations[$default_locale] = $this->$field_needed;

        return $translations;
    }
}
