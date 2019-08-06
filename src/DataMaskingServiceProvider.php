<?php

namespace Yui\DataMasking;

use Illuminate\Support\ServiceProvider;
use Yui\DataMasking\Commands\MaskData;
use Yui\DataMasking\Commands\ScanTables;

class DataMaskingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {

        /*$this->publishes([
            __DIR__.'/config.php' => config_path('runningtime.php'),
        ]);*/

        $this->registerCommand();
    }

    public function registerCommand()
    {
        $commands = [
            ScanTables::class,
            MaskData::class,
        ];

        $this->commands($commands);
    }
}
