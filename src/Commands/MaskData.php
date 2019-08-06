<?php


namespace Yui\DataMasking\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use \Yui\DataMasking\Traits\ConfirmableTrait;
use Illuminate\Support\Facades\Schema;
use Yui\DataMasking\Exceptions\PrimaryKeyNotFoundException;


class MaskData extends Command
{

    use ConfirmableTrait;

    const EMAIL_KEY_PREFIX = 'mask_temp_data_email_';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'data:mask 
                            {--tables= : Mask the specified table name, Table names are separated by commas, eg:users,orders,logs} 
                            {--where= : SQL condition, use with --tables}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Masking database with config file';

    protected $startTime;

    protected $config;

    protected $where = '';

    protected $tables = [];

    protected $emailSqls = [];


    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->startTime = microtime(true);

        $this->config = config('data-masking');

    }

    /**
     * Execute the console command.
     *
     * @return mixed
     * @throws PrimaryKeyNotFoundException
     */
    public function handle()
    {
        if (! $this->confirmToProceed()) {
            return;
        }

        $this->makeOptions();
        $this->testConfig();
        $this->filterConfig();

        foreach ($this->config as $tableName => $table) {
            $table = array_filter($table);

            $sqlAttributes = $fakerAttributes = [];
            $index = 0;
            foreach ($table as $column => $value) {
                [$char, $range] = explode(":", $value);
                if (strtolower($char) == 'faker') {
                    $fakerAttributes[$column] = $range;
                } else {
                    $sqlAttributes[$column] = $this->patternToSql($column, $char, $range, $index);
                }

                $index++;
            }

            if (!empty($sqlAttributes) || !empty($fakerAttributes)) {
                $this->line("updating $tableName");
            }

            if ($sqlAttributes) {
                $this->updateWithSqlFunction($tableName, $sqlAttributes);
            }

            if ($fakerAttributes) {
                $this->updateWithFaker($tableName, $fakerAttributes);
            }
        }

        $this->showInfo();
    }

    /**
     * 纯SQL更新，最快的方式
     *
     * @param $tableName
     * @param $attributes
     * @throws PrimaryKeyNotFoundException
     */
    public function updateWithSqlFunction($tableName, $attributes)
    {
        $tableNames = [$tableName];
        if (!empty($this->emailSqls)) {
            $primaryKey = $this->getPrimaryKey($tableName);
            foreach ($this->emailSqls as $sql) {
                $tableNames[] = str_ireplace(['{table_name}', '{primary_key}'], [$tableName, $primaryKey], $sql);
            }
        }

        $tableName = implode(',', $tableNames);

        $sql = "update $tableName set " . implode(',', $attributes) . $this->where;
        DB::statement($sql);
    }

    /**
     * 用faker生成数据更新，慢
     *
     * @param $tableName
     * @param $attributes
     * @throws PrimaryKeyNotFoundException
     */
    public function updateWithFaker($tableName, $attributes)
    {
        $limit = 1000;
        $page = 0;
        $primaryKey = $this->getPrimaryKey($tableName);
        $faker = \Faker\Factory::create(config('app.faker_locale'));

        while (true) {
            $offset = $limit * $page;
            $this->line("updating $tableName $offset-" . ($page+1)*$limit);
            $sql = "select $primaryKey from $tableName {$this->where} order by $primaryKey asc limit $offset,$limit";
            $records = DB::select($sql);
            foreach ($records as $record) {
                $attr = [];
                foreach ($attributes as $attributeName => $code) {
                    $result = '';
                    eval('$result = addcslashes($faker->' . $code . ', "\'");');
                    $attr[] = "$attributeName='$result'";
                }

                DB::update("update $tableName set " . implode(',', $attr) . " where $primaryKey=" . $record->{$primaryKey});
            }
            $page++;

            if (count($records) < $limit) break;
        }
    }

    /**
     * 获取表的主键
     *
     * @param $tableName
     * @return mixed
     * @throws PrimaryKeyNotFoundException
     */
    public function getPrimaryKey($tableName)
    {
        $re = DB::select("SHOW KEYS FROM $tableName WHERE Key_name = 'PRIMARY'");
        if (!$re) throw new PrimaryKeyNotFoundException($tableName);

        return $re[0]->Column_name;
    }

    /**
     * 构造SQL
     *
     * @param $column
     * @param $char
     * @param $range
     * @return string
     */
    public function patternToSql($column, $char, $range, $index)
    {
        $targetColumn = $column;
        if (strtolower($char) == 'email') {
            $char = '*';
            $targetColumn = self::EMAIL_KEY_PREFIX . $index;
            $this->emailSqls[] = '(select substr(email,1 ,instr(email, \'@\')-1) as ' . $targetColumn . ' from {table_name} where id={table_name}.{primary_key}) as' . $column;
        }
        if (strpos($range, '~') !== false) {
            return $this->keepTheFirstAndLastSql($column, $char, $range, $targetColumn);
        }

        return $this->keepIntervalSql($column, $char, $range, $targetColumn);
    }

    /**
     * 脱敏区间字符的SQL
     *
     * @param $column
     * @param $char
     * @param $range
     * @return string
     */
    public function keepIntervalSql($column, $char, $range, $targetColumn)
    {
        [$start, $end] = explode('-', $range);

        $left = "substr(`$targetColumn`, 1, $start-1)";

        if ($end) {
            $maskStr = "'" . str_pad($char, ($end - $start + 1), $char) . "'";
            $right = "substr(`$targetColumn`, $end+1, char_length(`$targetColumn`))";
        } else {
            $maskStr = "rpad('$char', char_length(`$targetColumn`) - $start + 1, '$char')";
            $right = "''";
        }

        $emailRight = $this->getEmailRight($column, $targetColumn);

        return "$column=concat($left, $maskStr, $right, $emailRight)";
    }

    /**
     * 返回保留首尾字符的SQL
     *
     * @param $column
     * @param $char
     * @param $range
     * @return string
     */
    public function keepTheFirstAndLastSql($column, $char, $range, $targetColumn)
    {
        [$start, $end] = explode('~', $range);
        $end = $end ?: 0;

        $left = "substr(`$targetColumn`, 1, $start)";
        $total = $start + $end;

        if ($end) {
            $right = "if(char_length(`$targetColumn`)>$total,substr(`$targetColumn`, char_length(`$targetColumn`)-$end+1, char_length(`$targetColumn`)), substr(`$targetColumn`, $start+1, char_length(`$targetColumn`)-$start))";
            $maskStr = "if(char_length(`$targetColumn`)>$total, rpad('$char', char_length(`$targetColumn`) - $start - $end, '$char'), '')";
        } else {
            $maskStr = "rpad('$char', char_length(`$targetColumn`) - $start, '$char')";
            $right = "''";
        }

        $emailRight = $this->getEmailRight($column, $targetColumn);

        return "$column=concat($left, $maskStr, $right, $emailRight)";
    }

    /**
     * 邮件格式时需要填充的字符
     *
     * @param $column
     * @param $targetColumn
     * @return string
     */
    public function getEmailRight($column, $targetColumn)
    {
        if (stripos($targetColumn, self::EMAIL_KEY_PREFIX) !== false) {
            return "substr($column, instr(`$column`, '@'), 200)";
        }

        return "''";
    }


    /**
     * 测试配置文件是否正确
     */
    public function testConfig()
    {
        $errors = [];
        foreach ($this->config as $tableName => $table) {
            $table = array_filter($table);
            foreach ($table as $column => $value) {
                $t = explode(":", $value);
                if (count($t) !== 2) {
                    $errors[] = "Unknown pattern in table $tableName column $column:$value";
                }

                if (strtolower($t[0]) != 'faker' && preg_match('/\w{1,}:\d{1,}-\d{0,}/', $value) === false) {
                    $errors[] = "Unknown pattern in table $tableName column $column:$value";
                }
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            exit(0);
        }
    }

    /**
     * 按条件过滤要脱敏的表
     *
     */
    protected function filterConfig()
    {
        if (!empty($this->tables)) {
            $tablesOnly = array_combine($this->tables, $this->tables);
            $this->config = array_intersect_key($this->config, $tablesOnly);
        }
    }

    /**
     * 处理参数
     */
    protected function makeOptions()
    {
        if (!empty($this->option('where'))) {
            $this->where =  " where " . $this->option('where');
        }

        if (!empty($this->option('tables'))) {
            $this->tables = explode(',', $this->option('tables'));
        }
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
