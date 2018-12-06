<?php namespace Utopigs\Sitemap;

use Backend;
use System\Classes\PluginBase;
use System\Classes\SettingsManager;

/**
 * Sitemap Plugin Information File
 */
class Plugin extends PluginBase
{
    /**
     * Returns information about this plugin.
     *
     * @return array
     */
    public function pluginDetails()
    {
        return [
            'name'        => 'utopigs.sitemap::lang.plugin.name',
            'description' => 'utopigs.sitemap::lang.plugin.description',
            'author'      => 'Utopig Studio',
            'icon'        => 'icon-sitemap',
            'homepage'    => 'https://github.com/utopigstudio/octobercms-plugin-sitemap'
        ];
    }

    /**
     * Registers administrator permissions for this plugin.
     *
     * @return array
     */
    public function registerPermissions()
    {
        return [
            'utopigs.sitemap.access_definitions' => [
                'tab'   => 'utopigs.sitemap::lang.plugin.name',
                'label' => 'utopigs.sitemap::lang.plugin.permissions.access_definitions',
            ],
        ];
    }

    /**
     * Registers settings for this plugin.
     *
     * @return array
     */
    public function registerSettings()
    {
        return [
            'definitions' => [
                'label'       => 'utopigs.sitemap::lang.plugin.name',
                'description' => 'utopigs.sitemap::lang.plugin.description',
                'icon'        => 'icon-sitemap',
                'url'         => Backend::url('utopigs/sitemap/definitions'),
                'category'    => SettingsManager::CATEGORY_CMS,
                'permissions' => ['utopigs.sitemap.access_definitions'],
            ]
        ];
    }
}
