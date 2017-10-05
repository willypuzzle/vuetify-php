## About Kendo

Vuetify Libraries are support vuetify libraries backend in PHP/Laravel

## Installation

composer require willypuzzle/vuetify-php

## Service providers to add

* Idsign\Vuetify\VuetifyServiceProvider::class

## Configuration Publishing

For Grid:
php artisan vendor:publish --tag=vuetify_datatable

## Use:

```php
use Idsign\Vuetify\Facades\Datatable;

// Using Eloquent
return Datatable::eloquent(User::query())->make(true);

// Using Query Builder
return Datatable::queryBuilder(DB::table('users'))->make(true);

// Using the Engine Factory
return Datatable::of(User::query())->make(true);
return Datatable::of(DB::table('users'))->make(true);
return Datatable::of(DB::select('select * from users'))->make(true);
```

## License

The Vuetify Libraries is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT).
