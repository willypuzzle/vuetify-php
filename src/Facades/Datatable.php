<?php
namespace Idsign\Vuetify\Facades;

use Illuminate\Support\Facades\Facade;

class Datatable extends Facade {

    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor() { return 'Idsign.vuetify.datatable'; }
}