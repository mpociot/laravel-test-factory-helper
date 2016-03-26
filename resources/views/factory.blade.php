
$factory->define({{$reflection->getName()}}::class, function (Faker\Generator $faker) {
    return [
@foreach($properties as $name => $property)
        '{{$name}}' => @if($property['faker']) {!!$property['type']!!} @else '{{$property['type']}}' @endif,
@endforeach
    ];
});

