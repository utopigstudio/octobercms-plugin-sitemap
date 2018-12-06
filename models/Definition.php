<?php namespace Utopigs\Sitemap\Models;

use Url;
use Cms;
use Model;
use Event;
use Request;
use DOMDocument;
use Config;
use Cms\Classes\Theme;
use Cms\Classes\Page;
use Utopigs\Sitemap\Classes\DefinitionItem;

/**
 * Definition Model
 */
class Definition extends Model
{
    /**
     * Maximum URLs allowed (Protocol limit is 50k)
     */
    const MAX_URLS = 50000;

    /**
     * Maximum generated URLs per type
     */
    const MAX_GENERATED = 10000;

    /**
     * @var string The database table used by the model.
     */
    public $table = 'utopigs_sitemap_definitions';

    /**
     * @var array Guarded fields
     */
    protected $guarded = [];

    /**
     * @var array Fillable fields
     */
    protected $fillable = [];

    /**
     * @var integer A tally of URLs added to the sitemap
     */
    protected $urlCount = 0;

    /**
     * @var array List of attribute names which are json encoded and decoded from the database.
     */
    protected $jsonable = ['data'];

    /**
     * @var array The sitemap items.
     * Items are objects of the \Utopigs\Sitemap\Classes\DefinitionItem class.
     */
    public $items;

    /**
     * @var DOMDocument element
     */
    protected $urlSet;

    /**
     * @var DOMDocument
     */
    protected $xmlObject;

    public function beforeSave()
    {
        $this->data = (array) $this->items;
    }

    public function afterFetch()
    {
        $this->items = DefinitionItem::initFromArray($this->data);
    }

    public function generateSitemap()
    {
        if (!$this->items) {
            return;
        }

        $currentUrl = Request::path();
        $theme = Theme::load($this->theme);

        $alternateLocales = [];
        if (class_exists('\RainLab\Translate\Classes\Translator')){
            $translator = \RainLab\Translate\Classes\Translator::instance();
            $defaultLocale = \RainLab\Translate\Models\Locale::getDefault()->code;
            $alternateLocales = array_keys(\RainLab\Translate\Models\Locale::listEnabled());
            $translator->setLocale($defaultLocale, false);
        }

        /*
         * Cycle each page and add its URL
         */
        foreach ($this->items as $item) {

            /*
             * Explicit URL
             */
            if ($item->type == 'url') {
                $this->addItemToSet($item, Url::to($item->url));
            }
            /*
             * Registered sitemap type
             */
            else {

                if (class_exists("\RainLab\Blog\Models\Post") && $item->type == 'blog-category' || $item->type == 'all-blog-categories') {
                    $apiResult = self::extendCategoryResolveMenuItem($item, $currentUrl, $theme);
                }
                elseif (class_exists("\RainLab\Blog\Models\Post") && $item->type == 'blog-post' || $item->type == 'all-blog-posts') {
                    $apiResult = self::extendPostResolveMenuItem($item, $currentUrl, $theme);
                }
                else {
                    $apiResult = Event::fire('utopigs.sitemap.resolveSitemapItem', [$item->type, $item, $currentUrl, $theme]);
                    if (!$apiResult) {
                        $apiResult = Event::fire('pages.menuitem.resolveItem', [$item->type, $item, $currentUrl, $theme]);
                    }
                }

                if (!is_array($apiResult)) {
                    continue;
                }
                
                foreach ($apiResult as $itemInfo) {
                    if (!is_array($itemInfo)) {
                        continue;
                    }

                    /*
                     * Single item
                     */
                    if (isset($itemInfo['url'])) {
                        $url = $itemInfo['url'];
                        $alternateLocaleUrls = [];
                        if ($item->type == 'cms-page' && count($alternateLocales)) {
                            $page = Page::loadCached($theme, $item->reference);
                            if ($page->hasTranslatablePageUrl($defaultLocale)) {
                                $page->rewriteTranslatablePageUrl($defaultLocale);
                            }
                            $url = Cms::url($translator->getPathInLocale($page->url, $defaultLocale));
                            foreach ($alternateLocales as $locale) {
                                if ($page->hasTranslatablePageUrl($locale)) {
                                    $page->rewriteTranslatablePageUrl($locale);
                                }
                                $alternateLocaleUrls[$locale] = Cms::url($translator->getPathInLocale($page->url, $locale));
                            }
                        }
                        if (isset($itemInfo['alternate_locale_urls'])) {
                            $alternateLocaleUrls = $itemInfo['alternate_locale_urls'];
                        }
                        $this->addItemToSet($item, $url, array_get($itemInfo, 'mtime'), $alternateLocaleUrls);
                    }

                    /*
                     * Multiple items
                     */
                    if (isset($itemInfo['items'])) {

                        $parentItem = $item;

                        $itemIterator = function($items) use (&$itemIterator, $parentItem)
                        {
                            foreach ($items as $item) {
                                if (isset($item['url'])) {
                                    $alternateLocaleUrls = [];
                                    if (isset($item['alternate_locale_urls'])) {
                                        $alternateLocaleUrls = $item['alternate_locale_urls'];
                                    }
                                    $this->addItemToSet($parentItem, $item['url'], array_get($item, 'mtime'), $alternateLocaleUrls);
                                }

                                if (isset($item['items'])) {
                                    $itemIterator($item['items']);
                                }
                            }
                        };

                        $itemIterator($itemInfo['items']);
                    }
                }
            }

        }

        $urlSet = $this->makeUrlSet();
        $xml = $this->makeXmlObject();
        $xml->appendChild($urlSet);

        return $xml->saveXML();
    }

    protected function makeXmlObject()
    {
        if ($this->xmlObject !== null) {
            return $this->xmlObject;
        }

        $xml = new DOMDocument;
        $xml->encoding = 'UTF-8';

        return $this->xmlObject = $xml;
    }

    protected function makeUrlSet()
    {
        if ($this->urlSet !== null) {
            return $this->urlSet;
        }

        $xml = $this->makeXmlObject();
        $urlSet = $xml->createElement('urlset');
        $urlSet->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $urlSet->setAttribute('xmlns:xhtml', 'http://www.w3.org/TR/xhtml11/xhtml11_schema.html');
        $urlSet->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $urlSet->setAttribute('xsi:schemaLocation', 'http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd http://www.w3.org/TR/xhtml11/xhtml11_schema.html http://www.w3.org/2002/08/xhtml/xhtml1-strict.xsd');

        return $this->urlSet = $urlSet;
    }

    protected function addItemToSet($item, $url, $mtime = null, $alternateLocaleUrls = [])
    {
        if ($mtime instanceof \DateTime) {
            $mtime = $mtime->getTimestamp();
        }

        $xml = $this->makeXmlObject();
        $urlSet = $this->makeUrlSet();
        $mtime = $mtime ? date('c', $mtime) : date('c');

        if ($alternateLocaleUrls) {
            foreach ($alternateLocaleUrls as $alternateLocaleUrl) {
                $urlElement = $this->makeUrlElement(
                    $xml,
                    $alternateLocaleUrl,
                    $mtime,
                    $item->changefreq,
                    $item->priority,
                    $alternateLocaleUrls
                );
                if ($urlElement) {
                    $urlSet->appendChild($urlElement);
                }
            }
        } else {
            $urlElement = $this->makeUrlElement(
                $xml,
                $url,
                $mtime,
                $item->changefreq,
                $item->priority
            );
            if ($urlElement) {
                $urlSet->appendChild($urlElement);
            }
        }

        return $urlSet;
    }

    protected function makeUrlElement($xml, $pageUrl, $lastModified, $frequency, $priority, $alternateLocaleUrls = [])
    {
        if ($this->urlCount >= self::MAX_URLS) {
            return false;
        }

        $this->urlCount++;

        $url = $xml->createElement('url');
        $url->appendChild($xml->createElement('loc', $pageUrl));
        $url->appendChild($xml->createElement('lastmod', $lastModified));
        $url->appendChild($xml->createElement('changefreq', $frequency));
        $url->appendChild($xml->createElement('priority', $priority));
        foreach ($alternateLocaleUrls as $locale => $locale_url) {
            $alternateUrl = $xml->createElement('xhtml:link');
            $alternateUrl->setAttribute('rel', 'alternate');
            $alternateUrl->setAttribute('hreflang', $locale);
            $alternateUrl->setAttribute('href', $locale_url);
            $url->appendChild($alternateUrl);
        }

        return $url;
    }

    protected static function extendCategoryResolveMenuItem($item, $url, $theme)
    {

    }

    protected static function extendPostResolveMenuItem($item, $url, $theme)
    {
        if ($item->type == 'blog-post') {
            if (!$item->reference || !$item->cmsPage)
                return;

            $page = Page::loadCached($theme, $item->cmsPage);
            if (!$page) {
                return;
            }

            $post = \RainLab\Blog\Models\Post::find($item->reference);
            if (!$post)
                return;

            $result = self::getPostMenuItem($page, $post, $url);
        }
        elseif ($item->type == 'all-blog-posts') {
            $result = [
                'items' => []
            ];

            $posts = \RainLab\Blog\Models\Post::isPublished()
            ->orderBy('title')
            ->get();

            $page = Page::loadCached($theme, $item->cmsPage);
            if (!$page) {
                return;
            }

            foreach ($posts as $post) {
                $result['items'][] = self::getPostMenuItem($page, $post, $url);
            }
        }

        return $result;
    }

    protected static function getPostMenuItem($page, $post, $url)
    {
        $result = [];

        $pageUrl = Page::url($page->getBaseFileName(), [':slug' => $post->slug]);
        $pageUrl = Url::to($pageUrl);

        if (class_exists('\RainLab\Translate\Classes\Translator') &&
                !\RainLab\Translate\Classes\Translator::instance()->loadLocaleFromRequest()
            ){

            $defaultLocale = \RainLab\Translate\Models\Locale::getDefault()->code;
            $alternateLocales = array_keys(\RainLab\Translate\Models\Locale::listEnabled());

            $pageUrl = self::getPostPageLocaleUrl($page, $post, $defaultLocale);

            foreach ($alternateLocales as $locale) {
                $result['alternate_locale_urls'][$locale] = self::getPostPageLocaleUrl($page, $post, $locale);
            }
        }

        $result['title'] = $post->name;
        $result['url'] = $pageUrl;
        $result['isActive'] = $pageUrl == $url;
        $result['mtime'] = $post->updated_at;

        return $result;
    }

    /**
     * Returns localized URL of a post page.
     */
    protected static function getPostPageLocaleUrl($page, $post, $locale)
    {
        $translator = \RainLab\Translate\Classes\Translator::instance();

        if ($page->hasTranslatablePageUrl($locale)) {
            $page->rewriteTranslatablePageUrl($locale);
        }

        $post->lang($locale);

        $url = $translator->getPathInLocale(str_replace(':slug', $post->slug, $page->url), $locale);

        $url = Url::to($url);

        return $url;
    }

}
