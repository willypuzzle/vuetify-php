<?php

namespace Idsign\Vuetify\Classes\Datatable;

use Idsign\Vuetify\Datatable\Request;
use Illuminate\Support\Collection;

class Main {
    private $app;

    /**
     * Datatables request object.
     *
     * @var \Idsign\Vuetify\Datatable\Request
     */
    protected $request;

    public function __construct($app)
    {
        $this->app = $app;
        $this->request = new Request($this->app['request']);
    }

    /**
     * Gets query and returns instance of class.
     *
     * @param  mixed $source
     * @return mixed
     * @throws \Exception
     */
    public static function of($source)
    {
        $datatables = app('Idsign.vuetify.datatable');
        $config     = app('config');
        $engines    = $config->get('vuetify_datatable.engines');
        $builders   = $config->get('vuetify_datatable.builders');

        if (is_array($source)) {
            $source = new Collection($source);
        }

        foreach ($builders as $class => $engine) {
            if ($source instanceof $class) {
                $class = $engines[$engine];

                return new $class($source, $datatables->getRequest());
            }
        }

        throw new \Exception('No available engine for ' . get_class($source));
    }

    /**
     * Get request object.
     *
     * @return \Idsign\Vuetify\Datatable\Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * Datatables using Query Builder.
     *
     * @param \Illuminate\Database\Query\Builder|mixed $builder
     * @return \Idsign\Vuetify\Engines\Datatable\QueryBuilderEngine
     */
    public function queryBuilder($builder)
    {
        return new \Idsign\Vuetify\Engines\Datatable\QueryBuilderEngine($builder, $this->request);
    }

    /**
     * Datatables using Eloquent Builder.
     *
     * @param \Illuminate\Database\Eloquent\Builder|mixed $builder
     * @return \Idsign\Vuetify\Engines\Datatable\EloquentEngine
     */
    public function eloquent($builder){
        return new \Idsign\Vuetify\Engines\Datatable\EloquentEngine($builder, $this->request);
    }


    /**
     * Datatables using Collection.
     *
     * @param \Illuminate\Support\Collection|mixed $collection
     * @return \Idsign\Vuetify\Engines\Datatable\CollectionEngine
     */
    public function collection($collection)
    {
        if (is_array($collection)) {
            $collection = new Collection($collection);
        }

        return new \Idsign\Vuetify\Engines\Datatable\CollectionEngine($collection, $this->request);
    }
}