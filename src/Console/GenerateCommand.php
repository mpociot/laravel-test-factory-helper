<?php

namespace Mpociot\LaravelTestFactoryHelper\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate database test factories for models
 * Class GenerateCommand
 * @package Mpociot\LaravelTestFactoryHelper\Console
 */
class GenerateCommand extends Command
{
    /**
     * @var Filesystem $files
     */
    protected $files;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'generate:model-factory';

    /**
     * @var string
     */
    protected $dir = 'app';

    /**
     * @var \Illuminate\Contracts\View\Factory
     */
    protected $view;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate database test factories for models';

    /**
     * @var string
     */
    protected $existingFactories = '';

    /**
     * @var array
     */
    protected $properties = [];

    /**
     * @var string
     */
    protected $force = '';

    /**
     * @param Filesystem $files
     * @param $view
     */
    public function __construct(Filesystem $files, $view)
    {
        parent::__construct();
        $this->files = $files;
        $this->view = $view;
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(): void
    {
        $this->dir = $this->option('dir');
        $this->force = $this->option('force');

        $models = $this->loadModels($this->argument('model'));

        foreach ($models as $model) {
            $filename = 'database/factories/' . class_basename($model) . 'Factory.php';

            if ($this->files->exists($filename) && !$this->force) {
                $this->warn("Model factory exists, use --force to overwrite: $filename");

                continue;
            }

            $result = $this->generateFactory($model);

            if ($result === false) {
                continue;
            }

            $written = $this->files->put($filename, $result);
            if ($written !== false) {
                $this->info("Model factory created: $filename");
            } else {
                $this->error("Failed to create model factory: $filename");
            }
        }
    }

    /**
     * @param array $models
     * @return mixed
     */
    protected function loadModels(array $models = [])
    {
        if (!empty($models)) {
            return array_map(function ($name) {
                if (strpos($name, '\\') !== false) {
                    return $name;
                }

                return str_replace(
                    [DIRECTORY_SEPARATOR, basename($this->laravel->path()) . '\\'],
                    ['\\', $this->laravel->getNamespace()],
                    $this->dir . DIRECTORY_SEPARATOR . $name
                );
            }, $models);
        }


        $dir = base_path($this->dir);
        if (!file_exists($dir)) {
            return [];
        }

        return array_map(function (\SplFIleInfo $file) {
            return str_replace(
                [DIRECTORY_SEPARATOR, basename($this->laravel->path()) . '\\'],
                ['\\', $this->laravel->getNamespace()],
                $file->getPath() . DIRECTORY_SEPARATOR . basename($file->getFilename(), '.php')
            );
        }, $this->files->allFiles($this->dir));
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function generateFactory(Model $model)
    {
        $output = '<?php' . "\n\n";

        $this->properties = [];
        if (!class_exists($model)) {
            if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $this->error("Unable to find '$model' class");
            }
            return false;
        }

        try {
            // handle abstract classes, interfaces, ...
            $reflectionClass = new \ReflectionClass($model);

            if (!$reflectionClass->isSubclassOf('Illuminate\Database\Eloquent\Model')) {
                return false;
            }

            if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $this->comment("Loading model '$model'");
            }

            if (!$reflectionClass->IsInstantiable()) {
                // ignore abstract class or interface
                return false;
            }

            $model = $this->laravel->make($model);

            $this->getPropertiesFromTable($model);
            $this->getPropertiesFromMethods($model);

            $output .= $this->createFactory($model);
        } catch (\Exception $e) {
            $this->error("Exception: " . $e->getMessage() . "\nCould not analyze class $model.");
        }

        return $output;
    }

    /**
     * Load the properties from the database table.
     * @param \Illuminate\Database\Eloquent\Model $model
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function getPropertiesFromTable(Model $model): void
    {
        $table = $model->getConnection()->getTablePrefix() . $model->getTable();
        $schema = $model->getConnection()->getDoctrineSchemaManager($table);
        $databasePlatform = $schema->getDatabasePlatform();
        $databasePlatform->registerDoctrineTypeMapping('enum', 'string');

        $platformName = $databasePlatform->getName();
        $customTypes = $this->laravel['config']->get("ide-helper.custom_db_types.{$platformName}", []);
        foreach ($customTypes as $yourTypeName => $doctrineTypeName) {
            $databasePlatform->registerDoctrineTypeMapping($yourTypeName, $doctrineTypeName);
        }

        $database = null;
        if (strpos($table, '.')) {
            list($database, $table) = explode('.', $table);
        }

        $columns = $schema->listTableColumns($table, $database);

        if ($columns) {
            foreach ($columns as $column) {
                $name = $column->getName();
                if (in_array($name, $model->getDates())) {
                    $type = 'datetime';
                } else {
                    $type = $column->getType()->getName();
                }
                if (!($model->incrementing && $model->getKeyName() === $name) &&
                    $name !== $model::CREATED_AT &&
                    $name !== $model::UPDATED_AT
                ) {
                    if (!method_exists($model, 'getDeletedAtColumn') || (method_exists($model, 'getDeletedAtColumn') && $name !== $model->getDeletedAtColumn())) {
                        $this->setProperty($name, $type);
                    }
                }
            }
        }
    }

    /**
     * @param string $name
     * @param string|null $type
     */
    protected function setProperty(string $name, $type = null): void
    {
        if ($type !== null && Str::startsWith($type, 'factory(')) {
            $this->properties[$name] = $type;

            return;
        }

        $fakeableTypes = [
            'string' => '$faker->word',
            'text' => '$faker->text',
            'date' => '$faker->date()',
            'time' => '$faker->time()',
            'guid' => '$faker->word',
            'datetimetz' => '$faker->dateTimeBetween()',
            'datetime' => '$faker->dateTimeBetween()',
            'integer' => '$faker->randomNumber()',
            'bigint' => '$faker->randomNumber()',
            'smallint' => '$faker->randomNumber()',
            'decimal' => '$faker->randomFloat()',
            'float' => '$faker->randomFloat()',
            'boolean' => '$faker->boolean'
        ];

        $fakeableNames = [
            'city' => '$faker->city',
            'company' => '$faker->company',
            'country' => '$faker->country',
            'description' => '$faker->text',
            'email' => '$faker->safeEmail',
            'first_name' => '$faker->firstName',
            'firstname' => '$faker->firstName',
            'guid' => '$faker->uuid',
            'last_name' => '$faker->lastName',
            'lastname' => '$faker->lastName',
            'lat' => '$faker->latitude',
            'latitude' => '$faker->latitude',
            'lng' => '$faker->longitude',
            'longitude' => '$faker->longitude',
            'name' => '$faker->name',
            'password' => 'bcrypt($faker->password)',
            'phone' => '$faker->phoneNumber',
            'phone_numer' => '$faker->phoneNumber',
            'postcode' => '$faker->postcode',
            'remember_token' => 'Str::random(10)',
            'slug' => '$faker->slug',
            'street' => '$faker->streetName',
            'summary' => '$faker->text',
            'url' => '$faker->url',
            'user_name' => '$faker->userName',
            'username' => '$faker->userName',
            'uuid' => '$faker->uuid',
            'zip' => '$faker->postcode',
        ];

        if (isset($fakeableNames[$name])) {
            $this->properties[$name] = $fakeableNames[$name];

            return;
        }

        if (isset($fakeableTypes[$type])) {
            $this->properties[$name] = $fakeableTypes[$type];

            return;
        }

        $this->properties[$name] = '$faker->word';
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model $model
     * @throws \ReflectionException
     */
    protected function getPropertiesFromMethods(Model $model)
    {
        $methods = get_class_methods($model);
        if ($methods) {
            foreach ($methods as $method) {
                if (!method_exists('Illuminate\Database\Eloquent\Model', $method) && !Str::startsWith($method, 'get')) {
                    //Use reflection to inspect the code, based on Illuminate/Support/SerializableClosure.php
                    $reflection = new \ReflectionMethod($model, $method);
                    $file = new \SplFileObject($reflection->getFileName());
                    $file->seek($reflection->getStartLine() - 1);
                    $code = '';
                    while ($file->key() < $reflection->getEndLine()) {
                        $code .= $file->current();
                        $file->next();
                    }
                    $code = trim(preg_replace('/\s\s+/', '', $code));
                    $begin = strpos($code, 'function(');
                    $code = substr($code, $begin, strrpos($code, '}') - $begin + 1);
                    foreach ([
                                 'belongsTo',
                             ] as $relation) {
                        $search = '$this->' . $relation . '(';
                        if ($pos = stripos($code, $search)) {
                            //Resolve the relation's model to a Relation object.
                            $relationObj = $model->$method();
                            if ($relationObj instanceof Relation) {
                                $relatedModel = '\\' . get_class($relationObj->getRelated());
                                $relatedObj = new $relatedModel;
                                $property = $relationObj->getForeignKeyName();
                                $this->setProperty($property, 'factory(' . get_class($relationObj->getRelated()) . '::class)->create()->' . $relatedObj->getKeyName());
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Create factory
     * @param string $class
     * @throws \ReflectionException
     */
    protected function createFactory(string $class): string
    {
        $reflection = new \ReflectionClass($class);

        $content = $this->view->make('test-factory-helper::factory', [
            'reflection' => $reflection,
            'properties' => $this->properties,
        ])->render();

        return $content;
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['model', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Which models to include', []],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['dir', 'D', InputOption::VALUE_OPTIONAL, 'The model directory', $this->dir],
            ['force', 'F', InputOption::VALUE_NONE, 'Overwrite any existing model factory'],
        ];
    }
}
