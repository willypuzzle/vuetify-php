<?php

namespace Idsign\Vuetify;

use Illuminate\Support\ServiceProvider;
use Idsign\Vuetify\Classes\Datatable\Main;

use League\Fractal\Manager;
use League\Fractal\Serializer\DataArraySerializer;


class VuetifyServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->mergeConfigFrom(__DIR__ . '/config/vuetify_datatable.php', 'vuetify_datatable');

        $this->publishes([
            __DIR__ . '/config/vuetify_datatable.php' => config_path('vuetify_datatable.php'),
        ], 'vuetify_datatable');
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->registerKendoGrid();
    }

    /**
     * Register the authenticator services.
     *
     * @return void
     */
    protected function registerKendoGrid()
    {
        $this->app->singleton('vuetify_datatable.fractal', function () {
            $fractal = new Manager;
            $config  = $this->app['config'];
            $request = $this->app['request'];

            $includesKey = $config->get('vuetify_datatable.fractal.includes', 'include');
            if ($request->get($includesKey)) {
                $fractal->parseIncludes($request->get($includesKey));
            }

            $serializer = $config->get('vuetify_datatable.fractal.serializer', DataArraySerializer::class);
            $fractal->setSerializer(new $serializer);

            return $fractal;
        });

        $this->app->alias('Idsign.vuetify.datatable', Main::class);
        $this->app->singleton('Idsign.vuetify.datatable', function ($app) {
            return new Main($app);
        });

        $this->registerAliases();
    }

    /**
     * Create aliases for the dependency.
     */
    protected function registerAliases()
    {
        if (class_exists('Illuminate\Foundation\AliasLoader')) {
            $loader = \Illuminate\Foundation\AliasLoader::getInstance();
            $loader->alias('VuetifyDatatable', \Idsign\Vuetify\Facades\Datatable::class);
        }
    }
}
