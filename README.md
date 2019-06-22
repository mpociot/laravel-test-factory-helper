## Laravel Test Factory Generator

`php artisan generate:model-factory`

This package will generate [factories](https://laravel.com/docs/master/database-testing#writing-factories) from your existing models so you can get started with testing your Laravel application more quickly.

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
        'name' => $faker->name,
        'username' => $faker->userName,
        'email' => $faker->safeEmail,
        'password' => bcrypt($faker->password),
        'company_id' => factory(App\Company::class),
        'remember_token' => Str::random(10),
    ];
});
```


### Install

Require this package with composer using the following command:

```bash
composer require --dev mpociot/laravel-test-factory-helper
```

### Usage

To generate multiple factories at once, run the artisan command:

`php artisan generate:model-factory`

This command will find all models within your application and create test factories. By default, this will not overwrite any existing model factories. You can _force_ overwriting existing model factories by using the `--force` option.

To generate a factory for specific model or models, run the artisan command:

`php artisan generate:model-factory User Team`

By default, this command will search under the `app` folder for models. If your models are within a different folder, for example `app/Models`, you can specify this using `--dir` option. In this case, run the artisan command:

`php artisan generate:model-factory --dir app/Models -- User Team`

### License

The Laravel Test Factory Helper is free software licensed under the MIT license.
