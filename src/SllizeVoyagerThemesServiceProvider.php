<?php

namespace D3turnes\VoyagerThemes;

use Illuminate\Http\Request;
use TCG\Voyager\Models\Menu;
use TCG\Voyager\Models\Role;
use TCG\Voyager\Models\MenuItem;
use Illuminate\Events\Dispatcher;
use TCG\Voyager\Models\Permission;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use D3turnes\VoyagerThemes\Commands\ThemeGenerator;

class SllizeVoyagerThemesServiceProvider extends ServiceProvider
{
    private $models = [
        'Theme',
        'ThemeOptions',
    ];

    /**
     * Register is loaded every time the voyager themes hook is used.
     *
     * @return none
     */
    public function register()
    {
        //if (request()->is(config('voyager.prefix')) || request()->is(config('voyager.prefix').'/*')) {
        $this->addThemesTable();

        app(Dispatcher::class)->listen('voyager.menu.display', function ($menu) {
            $this->addThemeMenuItem($menu);
        });

        app(Dispatcher::class)->listen('voyager.admin.routing', function ($router) {
            $this->addThemeRoutes($router);
        });
        //}

        // publish config
        $this->publishes([dirname(__DIR__) . '/config/themes.php' => config_path('themes.php')], 'voyager-themes-config');

		// load helpers
        @include __DIR__ . '/helpers.php';

        // load Commands
        $this->commands(ThemeGenerator::class);
    }

    /**
     * Add the necessary Themes tables if they do not exist.
     */
    private function addThemesTable()
    {
        if (!Schema::hasTable('voyager_themes')) {
            Schema::create('voyager_themes', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name');
                $table->string('folder', 191)->unique();
                $table->boolean('active')->default(false);
                $table->string('version')->default('');
                $table->timestamps();
            });

            Schema::create('voyager_theme_options', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('voyager_theme_id')->unsigned()->index();
                $table->foreign('voyager_theme_id')->references('id')->on('voyager_themes')->onDelete('cascade');
                $table->string('key');
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Adds the Theme icon to the admin menu.
     *
     * @param TCG\Voyager\Models\Menu $menu
     */
    public function addThemeMenuItem(Menu $menu)
    {
        if ($menu->name == 'admin') {
            $url = route('voyager.theme.index', [], false);
            $menuItem = $menu->items->where('url', $url)->first();
            if (is_null($menuItem)) {
                $menu->items->add(MenuItem::create([
                    'menu_id' => $menu->id,
                    'url' => $url,
                    'title' => 'Themes',
                    'target' => '_self',
                    'icon_class' => 'voyager-paint-bucket',
                    'color' => null,
                    'parent_id' => null,
                    'order' => 98,
                ]));
                $this->ensurePermissionExist();

                return redirect()->back();
            }
        }
    }

    /**
     * Add Permissions for themes if they do not exist yet.
     *
     * @return none
     */
    protected function ensurePermissionExist()
    {
        $permission = Permission::firstOrNew([
            'key' => 'browse_themes',
            'table_name' => 'admin',
        ]);
        if (!$permission->exists) {
            $permission->save();
            $role = Role::where('name', 'admin')->first();
            if (!is_null($role)) {
                $role->permissions()->attach($permission);
            }
        }
    }

    /**
     * Admin theme routes.
     *
     * @param $router
     */
    public function addThemeRoutes($router)
    {
        $namespacePrefix = '\\D3turnes\\VoyagerThemes\\Http\\Controllers\\';
        $router->get('themes', ['uses' => $namespacePrefix . 'ThemesController@index', 'as' => 'theme.index']);
        $router->get('themes/activate/{theme}', ['uses' => $namespacePrefix . 'ThemesController@activate', 'as' => 'theme.activate']);
        $router->get('themes/options/{theme}', ['uses' => $namespacePrefix . 'ThemesController@options', 'as' => 'theme.options']);
        $router->post('themes/options/{theme}', ['uses' => $namespacePrefix . 'ThemesController@options_save', 'as' => 'theme.options.post']);
//        $router->get('themes/options', function () {
//            return redirect(route('voyager.theme.index'));
//        });

        $router->get('themes/options', $namespacePrefix . 'ThemesController@index');
        $router->delete('themes/delete', ['uses' => $namespacePrefix . 'ThemesController@delete', 'as' => 'theme.delete']);
    }

    /**
     * Register the menu options and selected theme.
     *
     * @return void
     */
    public function boot()
    {
        try {

            $this->loadViewsFrom(__DIR__ . '/../resources/views', 'themes');

            $theme = '';

            if (Schema::hasTable('voyager_themes')) {
                $theme = $this->rescue(function () {
                    return \D3turnes\VoyagerThemes\Models\Theme::where('active', '=', 1)->first();
                });
                if (Cookie::get('voyager_theme')) {
                    $theme_cookied = \D3turnes\VoyagerThemes\Models\Theme::where('folder', '=', Cookie::get('voyager_theme'))->first();
                    if (isset($theme_cookied->id)) {
                        $theme = $theme_cookied;
                    }
                }
            }

            view()->share('theme', $theme);

            $this->themes_folder = config('themes.themes_folder', resource_path('views/themes'));

            $this->loadDynamicMiddleware($this->themes_folder, $theme);

            // Make sure we have an active theme
            if (isset($theme)) {
                $this->loadViewsFrom($this->themes_folder . '/' . @$theme->folder, 'theme');
            }
            $this->loadViewsFrom($this->themes_folder, 'themes_folder');

        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    function rescue(callable $callback, $rescue = null)
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            report($e);
            return value($rescue);
        }
    }

    // Duplicating the rescue function that's available in 5.5, just in case
    // A user wants to use this hook with 5.4

    private function loadDynamicMiddleware($themes_folder, $theme)
    {
        if (empty($theme)) {
            return;
        }
        $middleware_folder = $themes_folder . '/' . $theme->folder . '/middleware';
        if (file_exists($middleware_folder)) {
            $middleware_files = scandir($middleware_folder);
            foreach ($middleware_files as $middleware) {
                if ($middleware != '.' && $middleware != '..') {
                    include($middleware_folder . '/' . $middleware);
                    $middleware_classname = 'D3turnes\\VoyagerThemes\\Middleware\\' . str_replace('.php', '', $middleware);
                    if (class_exists($middleware_classname)) {
                        // Dynamically Load The Middleware
                        $this->app->make('Illuminate\Contracts\Http\Kernel')->prependMiddleware($middleware_classname);
                    }
                }
            }
        }
    }
}
