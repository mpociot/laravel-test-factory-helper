## Laravel Test Factory Generator

`php artisan test-factory-helper:generate`

This package helps you generate model factories from your existing models / database structure to get started with testing your Laravel application even faster.

### Example output

#### Migration and Model
```php
Schema::create('users', function (Blueprint $table) {
    $table->increments('id');
    $table->string('name');
    $table->string('username');
    $table->string('email')->unique();
    $table->string('password', 60);
    $table->integer('company_id');
    $table->rememberToken();
    $table->timestamps();
});

class User extends Model {
    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
```

#### Factory Result

```php
$factory->define(App\User::class, function (Faker\Generator $faker) {
    return [
        'name' =>  $faker->name ,
        'username' =>  $faker->userName ,
        'email' =>  $faker->safeEmail ,
        'password' =>  bcrypt($faker->password) ,
        'company_id' =>  factory(App\Company::class)->create()->id ,
        'remember_token' =>  str_random(10) ,
    ];
});
```


### Install

Require this package with composer using the following command:

```bash
composer require mpociot/laravel-test-factory-helper
```
Go to your `config/app.php` and add the service provider:

```php
Mpociot\LaravelTestFactoryHelper\TestFactoryHelperServiceProvider::class
```

### Usage

Just call the artisan command:

`php artisan test-factory-helper:generate`

This command will look for all models in your "app" folder (configurable by using the `--dir` option) and create test factories and save them in your `database/factories/ModelFactory.php`.

The output filename is also configurable by using the `--filename` option.

By default, the command will only append new models and doesn't modify the existing content of your factories file. To rewrite the file, use the `--reset` option.

### License

The Laravel Test Factory Helper is free software licensed under the MIT license.
