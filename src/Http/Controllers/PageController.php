<?php

namespace Pvtl\VoyagerPageBlocks\Http\Controllers;

use App;
use Cache;
use Request;
use Pvtl\VoyagerPageBlocks\Page;
use TCG\Voyager\Models\Translation;
use Illuminate\Support\Facades\View;
use Pvtl\VoyagerPageBlocks\Traits\Blocks;

class PageController extends \Pvtl\VoyagerFrontend\Http\Controllers\PageController
{
    use Blocks;

    /**
     * Fetch all pages and their associated blocks
     *
     * @param string $slug
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function getPage($slug = 'home')
    {
        $route_name = \Route::current()->getName();

        $locale = App::getLocale();
        $default_locale = config('app.locale');

        $slug = \in_array(Request::path(), ['/', '', $locale, "${locale}/"]) ? 'home' : Request::path();
        $slug_unlocalized = \preg_replace('/^' . $locale . '\/?/', '', $slug);

        $routes = Cache::remember('page/slugs_detailed', 5, function() use ($default_locale) {
            $pages = Page::whereNull('site')->select(['id', 'slug', 'route_name'])->get();
            $translations = Translation::where('table_name', 'pages')
                ->where('column_name', 'slug')
                ->select('foreign_key', 'locale', 'value')
                ->get();

            $routes = [];
            foreach ($pages as &$page) {
                $page_id = $page->getKey();
                $page_translations = $translations->where('foreign_key', $page_id);

                $tmp_route = collect([
                    $default_locale => $page->slug,
                ]);
                foreach ($page_translations as &$translation) {
                    $tmp_route[$translation->locale] = $translation->value;
                }

                $routes[$page->route_name] = $tmp_route;
            }

            return collect($routes);
        });

        $page = Page::withTranslation($locale)->where(['route_name' => $route_name, 'status' => 'ACTIVE'])->firstOrFail();
        $blocks = $page->blocks()->where('is_hidden', '=', '0')->orderBy('order', 'asc')->get()->map(function($block) use ($locale) {
            $data = $block->translatedData($locale);
            $template = empty($block->path) ? null : $block->template()->template;

            return (object)[
                'id'         => $block->id,
                'page_id'    => $block->page_id,
                'updated_at' => $block->updated_at,
                'cache_ttl'  => $block->cache_ttl,
                'template'   => $template,
                'data'       => $data,
                'controller' => $block->controller,
                'path'       => $block->path,
                'type'       => $block->type,
            ];
        });

        // Override standard body content, with page block content
        $page['body'] = view('voyager-page-blocks::default', [
            'page' => $page,
            'blocks' => $this->prepareEachBlock($blocks),
        ]);

        // Check that the page Layout and its View exists
        if (empty($page->layout)) {
            $page->layout = 'layouts.default';
        }
        if (!View::exists($page->layout)) {
            $page->layout = 'layouts.default';
        }

        $page_translated = $page->translate($locale);
        $page_translated->body = $page->body; // preserve body

        // Return the full page
        return view("{$this->viewPath}::modules.pages.default", [
            'page' => $page_translated,
            'layout' => $page_translated->layout,
        ]);
    }
}
