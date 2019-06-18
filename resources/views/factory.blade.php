/* @@var $factory \Illuminate\Database\Eloquent\Factory */

use Faker\Generator as Faker;

$factory->define({{ $reflection->getName() }}::class, function (Faker $faker) {
    return [
@foreach($properties as $name => $property)
        '{{$name}}' => {!! $property !!},
@endforeach
    ];
});
