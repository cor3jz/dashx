<?php

namespace TCG\Voyager\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
use Symfony\Component\Console\Input\InputOption;
use TCG\Voyager\Providers\VoyagerDummyServiceProvider;
use TCG\Voyager\Seed;
use TCG\Voyager\VoyagerServiceProvider;

class InstallCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'voyager:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Процесс установки DashX';

    /**
     * The Composer instance.
     *
     * @var \Illuminate\Foundation\Composer
     */
    protected $composer;

    /**
     * Seed Folder name.
     *
     * @var string
     */
    protected $seedFolder;

    public function __construct(Composer $composer)
    {
        parent::__construct();

        $this->composer = $composer;
        $this->composer->setWorkingPath(base_path());

        $this->seedFolder = Seed::getFolderName();
    }

    protected function getOptions()
    {
        return [
            ['force', null, InputOption::VALUE_NONE, 'Принудительное выполнение операции в Production среде', null],
            ['with-dummy', null, InputOption::VALUE_NONE, 'Установка с фиктивными данными', null],
        ];
    }

    /**
     * Get the composer command for the environment.
     *
     * @return string
     */
    protected function findComposer()
    {
        if (file_exists(getcwd().'/composer.phar')) {
            return '"'.PHP_BINARY.'" '.getcwd().'/composer.phar';
        }

        return 'composer';
    }

    public function fire(Filesystem $filesystem)
    {
        return $this->handle($filesystem);
    }

    /**
     * Execute the console command.
     *
     * @param \Illuminate\Filesystem\Filesystem $filesystem
     *
     * @return void
     */
    public function handle(Filesystem $filesystem)
    {
        $this->info('Публикация ресурсов, базы данных, языковых пакетов и конфигурации DashX');

        // Publish only relevant resources on install
        $tags = ['seeds','lang'];

        $this->call('vendor:publish', ['--provider' => VoyagerServiceProvider::class, '--tag' => $tags]);

        $this->info('Миграция таблиц в базу данных приложения');
        $this->call('migrate', ['--force' => $this->option('force')]);

        $this->info('Попробовать установить пользовательскую модель DashX в качестве родительской для App\User');
        if (file_exists(app_path('User.php')) || file_exists(app_path('Models/User.php'))) {
            $userPath = file_exists(app_path('User.php')) ? app_path('User.php') : app_path('Models/User.php');

            $str = file_get_contents($userPath);

            if ($str !== false) {
                $str = str_replace('extends Authenticatable', "extends \TCG\Voyager\Models\User", $str);

                file_put_contents($userPath, $str);
            }
        } else {
            $this->warn('Не удается найти "User.php" в каталоге "app" или "app/Models". Вы переместили этот файл?');
            $this->warn('Вам нужно будет обновить это вручную. Замените "extends Authenticatable" на "extends \TCG\Voyager\Models\User" в модели User');
        }

        $this->info('Добавление маршрутов DashX в routes/web.php');
        $routes_contents = $filesystem->get(base_path('routes/web.php'));
        if (false === strpos($routes_contents, 'Voyager::routes()')) {
            $filesystem->append(
                base_path('routes/web.php'),
                PHP_EOL.PHP_EOL."Route::group(['prefix' => 'admin'], function () {".PHP_EOL."    Voyager::routes();".PHP_EOL."});".PHP_EOL
            );
        }

        $publishablePath = dirname(__DIR__).'/../publishable';

        if ($this->option('with-dummy')) {
            $this->info('Публикация сгенерированных данных');
            $tags = ['dummy_seeds', 'dummy_content', 'dummy_config', 'dummy_migrations'];
            $this->call('vendor:publish', ['--provider' => VoyagerDummyServiceProvider::class, '--tag' => $tags]);

            $this->addNamespaceIfNeeded(
                collect($filesystem->files("{$publishablePath}/database/dummy_seeds/")),
                $filesystem
            );
        } else {
            $this->call('vendor:publish', ['--provider' => VoyagerServiceProvider::class, '--tag' => ['config', 'voyager_avatar']]);
        }

        $this->addNamespaceIfNeeded(
            collect($filesystem->files("{$publishablePath}/database/seeds/")),
            $filesystem
        );

        $this->info('Сброс загруженных файлов и перезагрузка всех новых файлов');
        $this->composer->dumpAutoloads();
        require_once base_path('vendor/autoload.php');

        $this->info('Импорт данных в БД');
        $this->call('db:seed', ['--class' => 'VoyagerDatabaseSeeder']);

        if ($this->option('with-dummy')) {
            $this->info('Миграция фиктивных таблиц');
            $this->call('migrate');

            $this->info('Импорт фиктивных данных');
            $this->call('db:seed', ['--class' => 'VoyagerDummyDatabaseSeeder']);
        }

        $this->info('Добавление символической ссылки хранилища в вашу общую папку');
        $this->call('storage:link');

        $this->info('Установка DashX прошла успешно!');
    }

    private function addNamespaceIfNeeded($seeds, Filesystem $filesystem)
    {
        if ($this->seedFolder != 'seeders') {
            return;
        }

        $seeds->each(function ($file) use ($filesystem) {
            $path = database_path('seeders').'/'.$file->getFilename();
            $stub = str_replace(
                ["<?php\n\nuse", "<?php".PHP_EOL.PHP_EOL."use"],
                "<?php".PHP_EOL.PHP_EOL."namespace Database\\Seeders;".PHP_EOL.PHP_EOL."use",
                $filesystem->get($path)
            );

            $filesystem->put($path, $stub);
        });
    }
}
