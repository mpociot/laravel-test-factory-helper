<?php

namespace Mpociot\LaravelTestFactoryHelper\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\ClassLoader\ClassMapGenerator;

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
    protected $name = 'test-factory-helper:generate';

    /**
     * @var string
     */
    protected $filename = 'database/factories/ModelFactory.php';

    /**
     * @var string
     */
    protected $dir = 'app';

    /** @var \Illuminate\Contracts\View\Factory */
    protected $view;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate test factories for models';

    /**
     * @var string
     */
    protected $existingFactories = '';

    /**
     * @var array
     */
    protected $properties = array();

    /**
     * @var array
     */
    protected $dirs = array();

    /**
     * @var
     */
    protected $reset;

    /**
     * @param Filesystem $files
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
    public function fire()
    {
        $filename = $this->option('filename');
        $this->dirs = $this->option('dir');
        $model = $this->argument('model');
        $ignore = $this->option('ignore');
        $this->reset = $this->option('reset');

        try {
            $this->existingFactories = $this->files->get($filename);
        } catch (FileNotFoundException $e) {
            $this->existingFactories = '';
        }

        $result = $this->generateFactories($model, $ignore);

        $written = $this->files->put('database/factories/ModelFactory.php', $result);
        if ($written !== false) {
            $this->info("Model factories were written successfully to ".$filename);
        } else {
            $this->error("Failed to write model factories to ".$filename);
        }
    }


    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return array(
            array('model', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Which models to include', array()),
        );
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return array(
            array('filename', 'F', InputOption::VALUE_OPTIONAL, 'The path to the model factory file', $this->filename),
            array('dir', 'D', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'The model dir', array($this->dir)),
            array('reset', 'R', InputOption::VALUE_NONE, 'Remove the original ModelFactory instead of appending'),
            array('ignore', 'I', InputOption::VALUE_OPTIONAL, 'Which models to ignore', ''),
        );
    }

    protected function generateFactories($loadModels, $ignore = '')
    {

        if (empty($loadModels)) {
            $models = $this->loadModels();
        } else {
            $models = array();
            foreach ($loadModels as $model) {
                $models = array_merge($models, explode(',', $model));
            }
        }

        $output = ($this->reset || !$this->files->exists('database/factories/ModelFactory.php')) ? '<?php'."\n\n" : $this->existingFactories;
        $ignore = explode(',', $ignore);

        foreach ($models as $name) {
            if (in_array($name, $ignore)) {
                if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                    $this->comment("Ignoring model '$name'");
                }
                continue;
            }

            $this->properties = array();
            if (class_exists($name)) {
                try {
                    // handle abstract classes, interfaces, ...
                    $reflectionClass = new \ReflectionClass($name);

                    if (!$reflectionClass->isSubclassOf('Illuminate\Database\Eloquent\Model')) {
                        continue;
                    }

                    if (!$this->reset && preg_match("/\\\$factory->define\((.*?)".preg_quote($reflectionClass->getName())."::class(.*?),/", $this->existingFactories)) {
                        if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                            $this->error("Model '$name' already has a factory");
                        }
                        continue;
                    }

                    if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                        $this->comment("Loading model '$name'");
                    }

                    if (!$reflectionClass->IsInstantiable()) {
                        // ignore abstract class or interface
                        continue;
                    }

                    $model = $this->laravel->make($name);

                    $this->getPropertiesFromTable($model);

                    $this->getPropertiesFromMethods($model);

                    $output .= $this->createFactory($name);
                    $ignore[] = $name;
                } catch (\Exception $e) {
                    $this->error("Exception: " . $e->getMessage() . "\nCould not analyze class $name.");
                }
            }

        }
        return $output;
    }


    protected function loadModels()
    {
        $models = array();
        foreach ($this->dirs as $dir) {
            $dir = base_path() . '/' . $dir;
            if (file_exists($dir)) {
                foreach (ClassMapGenerator::createMap($dir) as $model => $path) {
                    $models[] = $model;
                }
            }
        }
        return $models;
    }

    /**
     * Load the properties from the database table.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function getPropertiesFromTable($model)
    {
        $table = $model->getConnection()->getTablePrefix() . $model->getTable();
        $schema = $model->getConnection()->getDoctrineSchemaManager($table);
        $databasePlatform = $schema->getDatabasePlatform();
        $databasePlatform->registerDoctrineTypeMapping('enum', 'string');

        $platformName = $databasePlatform->getName();
        $customTypes = $this->laravel['config']->get("ide-helper.custom_db_types.{$platformName}", array());
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
                    if(!method_exists($model,'getDeletedAtColumn') || (method_exists($model,'getDeletedAtColumn') && $name !== $model->getDeletedAtColumn())) {
                        $this->setProperty($name, $type);
                    }
                }
            }
        }
    }


    /**
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function getPropertiesFromMethods($model)
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
                    foreach (array(
                                 'belongsTo',
                             ) as $relation) {
                        $search = '$this->' . $relation . '(';
                        if ($pos = stripos($code, $search)) {
                            //Resolve the relation's model to a Relation object.
                            $relationObj = $model->$method();
                            if ($relationObj instanceof Relation) {
                                $relatedModel = '\\' . get_class($relationObj->getRelated());
                                $relatedObj = new $relatedModel;

                                $property = $relationObj->getForeignKey();
                                $this->setProperty($property,'function () {
             return factory('.get_class($relationObj->getRelated()).'::class)->create()->'.$relatedObj->getKeyName().';
        }');
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @param string $name
     * @param string|null $type
     */
    protected function setProperty($name, $type = null)
    {
        if (!isset($this->properties[$name])) {
            $this->properties[$name] = array();
            $this->properties[$name]['type'] = 'mixed';
            $this->properties[$name]['faker'] = false;
        }
        if ($type !== null) {
            $this->properties[$name]['type'] = $type;
        }

        if (Str::startsWith($type,'function ()')) {
            $this->properties[$name]['faker'] = true;
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
            'name' => '$faker->name',
            'firstname' => '$faker->firstName',
            'first_name' => '$faker->firstName',
            'lastname' => '$faker->lastName',
            'last_name' => '$faker->lastName',
            'street' => '$faker->streetName',
            'zip' => '$faker->postcode',
            'postcode' => '$faker->postcode',
            'city' => '$faker->city',
            'country' => '$faker->country',
            'latitude' => '$faker->latitude',
            'lat' => '$faker->latitude',
            'longitude' => '$faker->longitude',
            'lng' => '$faker->longitude',
            'phone' => '$faker->phoneNumber',
            'phone_numer' => '$faker->phoneNumber',
            'company' => '$faker->company',
            'email' => '$faker->safeEmail',
            'username' => '$faker->userName',
            'user_name' => '$faker->userName',
            'password' => 'bcrypt($faker->password)',
            'url' => '$faker->url',
            'remember_token' => 'str_random(10)',
            'uuid' => '$faker->uuid',
            'guid' => '$faker->uuid',
        ];

        if (isset($fakeableNames[$name])) {
            $this->properties[$name]['faker'] = true;
            $this->properties[$name]['type'] = $fakeableNames[$name];
        }

        if (isset($fakeableTypes[$type]) && !$this->properties[$name]['faker']) {
            $this->properties[$name]['faker'] = true;
            $this->properties[$name]['type'] = $fakeableTypes[$type];
        }
    }


    /**
     * @param string $class
     * @return string
     */
    protected function createFactory($class)
    {
        $reflection = new \ReflectionClass($class);

        $content = $this->view->make('test-factory-helper::factory', [
            'reflection' => $reflection,
            'properties' => $this->properties,
        ])->render();

        return $content;
    }

}
