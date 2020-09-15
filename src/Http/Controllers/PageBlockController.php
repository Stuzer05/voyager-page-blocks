<?php

namespace Pvtl\VoyagerPageBlocks\Http\Controllers;

use Exception;
use Illuminate\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Pvtl\VoyagerPageBlocks\Page;
use Pvtl\VoyagerPageBlocks\PageBlock;
use Pvtl\VoyagerPageBlocks\Traits\Blocks;
use Pvtl\VoyagerPageBlocks\MockedDataModel;
use Pvtl\VoyagerPageBlocks\Validators\BlockValidators;
use TCG\Voyager\Facades\Voyager;
use TCG\Voyager\Http\Controllers\VoyagerBaseController;

class PageBlockController extends VoyagerBaseController
{
    use Blocks;

    public function index(Request $request)
    {
        return redirect('/admin/pages');
    }

    /**
     * POST B(R)EAD - Read data.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     *
     * @return View
     */
    public function edit(Request $request, $id)
    {
        $page = Page::findOrFail($id);

        return view('voyager::page-blocks.edit-add', [
            'page' => $page,
            'pageBlocks' => $page->blocks->sortBy('order'),
        ]);
    }

    /**
     * POST BR(E)AD - Edit data.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, $id)
    {
        $block = PageBlock::findOrFail($id);
        $template = $block->template();
        $dataType = Voyager::model('DataType')->where('slug', '=', 'page-blocks')->first();

        // Get all block data & validate
        $data = [];

        foreach ($template->fields as $row) {
            $existingData = $block->data;

            if (
                $row->type === 'image'
                || $row->type === 'multiple_images'
            ) {
                if (is_null($request->file($row->field))) {
                    if (isset($existingData->{$row->field})) {
                        $data[$row->field] = $existingData->{$row->field};
                    }

                    continue;
                }

                $data[$row->field] = $request->file($row->field);
            } else {
                $data[$row->field] = $request->input($row->field);
            }
        }

        // Just.Do.It! (Nike, TM)
        $validator = BlockValidators::validateBlock($request, $block);
        if ($validator->fails()) {
            return redirect()
                ->back()
                ->withErrors($validator)
                ->withInput()
                ->with([
                    'message' => __('voyager::json.validation_errors'),
                    'alert-type' => 'error',
                ]);
        }

        $data = $this->uploadImages($request, $data);

        $translatable_fields = $template->fields->filter(function($field) {
            return isset($field->translatable) && $field->translatable;
        })->pluck('field');
        $translations = $this->prepareTranslations($translatable_fields, $request);

        $default_locale = config('voyager.multilingual.default', 'en');
        $locales = config('voyager.multilingual.locales', [$default_locale]);

        // Save translations and regular
        foreach ($locales as $locale) {
            if (!array_key_exists($locale, $translations)) continue;

            $localized_data = $data;
            foreach ($translations[$locale] as $field => $translation) {
                $localized_data[$field] = $translation;
            }

            if ($locale == $default_locale) {
                $block->data = $localized_data;

                if ($block->type === 'include') {
                    $block->controller = $request->input('controller');
                }

                $block->is_hidden = $request->has('is_hidden');
                $block->is_delete_denied = $request->has('is_delete_denied');
                $block->cache_ttl = $request->input('cache_ttl');
                $block->save();
            } else {
                $block = $block->translate($locale);
                $block->data = json_encode($localized_data);
                $block->save();
            }
        }

        return redirect()
            ->to(URL::previous() . "#block-id-" . $id)
            ->with([
                'message' => __('voyager::generic.successfully_updated') . " {$dataType->display_name_singular}",
                'alert-type' => 'success',
            ]);
    }

    /**
     * POST - Order data.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function sort(Request $request)
    {
        $blockOrder = json_decode($request->input('order'));

        foreach ($blockOrder as $index => $item) {
            $block = PageBlock::findOrFail($item->id);
            $block->order = $index + 1;
            $block->save();
        }
    }

    /**
     * POST - Minimize Block
     *
     * @param \Illuminate\Http\Request $request
     */
    public function minimize(Request $request)
    {
        $block = PageBlock::findOrFail((int)$request->id);
        $block->is_minimized = (int)$request->is_minimized;
        $block->save();
    }

    /**
     * POST - Change Page Layout
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id - the page id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function changeLayout(Request $request, $id)
    {
        $page = Page::findOrFail((int)$id);
        $page->layout = $request->layout;
        $page->save();

        return redirect()
            ->back()
            ->with([
                'message' => __('voyager::generic.successfully_updated') . " Page Layout",
                'alert-type' => 'success',
            ]);
    }

    /**
     * POST BRE(A)D - Store data.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $request->validate([
            'type' => ['required']
        ]);

        $page = Page::findOrFail($request->input('page_id'));
        $dataType = Voyager::model('DataType')->where('slug', '=', 'page-blocks')->first();

        if ($request->input('type') === 'include') {
            $type = $request->input('type');
            $path = '\Pvtl\VoyagerFrontend\Http\Controllers\PostController::recentBlogPosts()';
        } else {
            [$type, $path] = explode('|', $request->input('type'));

            // Check for enforced type
            $blocks = config('page-blocks');
            if (isset($blocks[$path]['type'])) {
                $type = $blocks[$path]['type'];
            }
        }

        $block = $page->blocks()->create([
            'type' => $type,
            'path' => $path,
            'data' => $type === 'include' ? '' : $this->generatePlaceholders($request),
            'order' => time(),
        ]);

        return redirect()
            ->route('voyager.page-blocks.edit', array($page->id, '#block-id-' . $block->id))
            ->with([
                'message' => __('voyager::generic.successfully_added_new') . " {$dataType->display_name_singular}",
                'alert-type' => 'success',
            ]);
    }

    /**
     * DELETE BREA(D) - Delete data.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Request $request, $id)
    {
        $block = PageBlock::findOrFail($id);
        $dataType = Voyager::model('DataType')->where('slug', '=', 'page-blocks')->first();

        try {
            $block->delete();
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->with([
                    'message' => "Unable to delete {$dataType->display_name_singular}",
                    'alert-type' => 'error',
                ]);
        }

        return redirect()
            ->back()
            ->with([
                'message' => __('voyager::generic.successfully_deleted') . " {$dataType->display_name_singular}",
                'alert-type' => 'success',
            ]);
    }

    public function prepareTranslations($fields, $request)
    {
        $translations = [];

        $default_locale = config('voyager.multilingual.default', 'en');
        $locales = config('voyager.multilingual.locales', [$default_locale]);

        // $fields = !empty($request->attributes->get('breadRows')) ? array_intersect($request->attributes->get('breadRows'), $transFields) : $transFields;

        foreach ($fields as $field) {
            if (!$request->input($field.'_i18n')) {
                throw new Exception('Invalid Translatable field'.$field);
            }

            $trans = json_decode($request->input($field.'_i18n'), true);

            foreach ($trans as $lang => $translation) {
                if (!array_key_exists($lang, $translations)) $translations[$lang] = [];
                $translations[$lang][$field] = $translation;
            }

            // Set the default local value
            $request->merge([$field => $trans[config('voyager.multilingual.default', 'en')]]);

            // Remove field hidden input
            unset($request[$field.'_i18n']);
        }

        // Remove language selector input
        unset($request['i18n_selector']);

        return $translations;
    }
}
