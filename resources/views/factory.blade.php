namespace Database\Factories;

use {{$reflection->getName()}};
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class {{$reflection->getShortName()}}Factory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = {{$reflection->getShortName()}}::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        $faker = $this->faker;
        return [
@foreach($properties as $name => $property)
            '{{$name}}' => {!! $property !!},
@endforeach
        ];
    }
}
