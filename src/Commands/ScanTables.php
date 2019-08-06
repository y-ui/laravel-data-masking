<?php


namespace Yui\DataMasking\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


class ScanTables extends Command
{

    const CONFIG_FILE = 'data-masking.php';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'data:scan {--tables= : Scan the specified table name, Table names are separated by commas, eg:users,orders,logs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan Tables and generate config file';

    protected $startTime;


    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->startTime = microtime(true);

    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $options = $this->options();

        if (file_exists(config_path(self::CONFIG_FILE))) {
            $confirm = $this->confirm('The config file has exists, make sure to overwrite?');
            if (!$confirm) {
                $this->comment('Command Cancelled!');
                return;
            }
        }

        if (!empty($options['tables'])) {
            $tables = explode(',', $options['tables']);
        } else {
            $tables = DB::connection()->getDoctrineSchemaManager()->listTableNames();
        }

        $configs = [];

        foreach ($tables as $table) {
            $columns = Schema::getColumnListing($table);

            if (($index = array_search('id', $columns)) !== false) {
                unset($columns[$index]);
            }

            $configs[$table] = array_combine($columns, array_pad([], count($columns), ''));
        }

        $txt = var_export($configs, true);

        $txt = str_replace(["=> \n  array (", ')', '  ', 'array ('], ["=> [", ']', '    ', '['], $txt);

        $txt = file_get_contents(__DIR__ . '/../Configs/config.php') . 'return ' . $txt . ';';

        file_put_contents(config_path(self::CONFIG_FILE), $txt);

        $this->showInfo();
    }



    /**
     * 显示内存和时间信息
     */
    protected function showInfo()
    {
        $this->info('time cost: ' . round(microtime(true) - $this->startTime, 2) . ' seconds');
        $this->info('max memory usage: ' . round(memory_get_peak_usage(true)/1024/1024, 2) . 'M');
    }

}
