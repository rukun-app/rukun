<?php

namespace Core\Console;

use Core\Support\ModuleName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MakeModuleCommand extends Command
{
    protected $signature = 'make:module {name}';

    protected $description = 'Create a new application module';

    public function handle(): int
    {
        $name = $this->argument('name');

        if (! ModuleName::isValid($name)) {
            $this->error('Module name must be PascalCase and alphanumeric.');

            return self::FAILURE;
        }

        $path = base_path('Modules/'.$name);

        if (File::exists($path)) {
            $this->error('Module already exists.');

            return self::FAILURE;
        }

        File::ensureDirectoryExists($path.'/Providers');
        File::ensureDirectoryExists($path.'/routes');
        File::put($path.'/Providers/'.$name.'ServiceProvider.php', str_replace('{{ name }}', $name, file_get_contents(__DIR__.'/stubs/ModuleServiceProvider.stub')));
        File::put($path.'/routes/api.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n");
        $this->components->info("Module {$name} created.");

        return self::SUCCESS;
    }
}
