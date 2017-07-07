# Laravel Scout Database Driver

[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)

This is a basic database backed driver [for Laravel Scout](https://laravel.com/docs/5.4/scout). It is intended for use during development to avoid the need to setup an elastic instance or agolia and instead uses your active database configuration. 

Searchable model attributes are JSON encoded an placed in a text column for simplicity, the very primative 'like' operator is used to perform queries. It is fully functional supporting additional where clauses, etc. The driver deliberately avoides using free text queries & indexes as these are somewhat provider specific and would prevent the goal of it being able to operate with any architecture.

This driver is zero configuration, requiring you to only add the service provider & run the migration.

## Installation

You can install the package via composer:

``` bash
composer require boxed-code/laravel-scout-database
```

You must add the Scout service provider and the package service provider in your app.php config:

```php
// config/app.php
'providers' => [
    ...
    Laravel\Scout\ScoutServiceProvider::class,
    ...
    BoxedCode\Laravel\Scout\DatabaseEngineServiceProvider::class,
],
```

Then run the migrations via the console
```shell
php artisan migrate
```

## Usage

Now you can use Laravel Scout as described in the [official documentation](https://laravel.com/docs/5.4/scout)

## License

The MIT License (MIT).