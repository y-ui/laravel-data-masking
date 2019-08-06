# laravel-data-masking

## Installation

    composer require y-ui/laravel-data-masking ^1.0
    
## Configuration

If you laravel version is below 5.5
1. Open your `config/app.php` and add the following to the providers array:

    ```php
    \Yui\DataMasking\DataMaskingServiceProvider::class,
    ```
    
## Usage
### Simple usage

1.scan database and generate config file `config/data-masking.php`
```shell
php artisan data:scan
```

2.Edit config file `data-masking.php`

3.Execute
```shell
php artisan data:mask
```

### Options

    #Specify the name of the table to scan
    php artisan data:scan --tables=users,orders
    
    #Update only data that meets the criteria
    php artisan data:mask --tables=users --where='id>100'
    
    
### Config File
You can customize any character

    'name' => ''          nothing to do
    'name' => '*:1-'      Tom => ***  Replace all characters
    'name' => '0:2-3'     William => W000iam
    'name' => '0:3-5'     Tom => To000
    'phone' => '*:4-7'    18666666666 => 186****6666
    'phone' => '123:4-'   18666666666 => 18612312312
    'phone' => '*:1~2'    18666666666 => 1********66  Keep the first and end character
    'email' => 'email:3-' production@google.com => pr********@google.com  Replace only the characters before @
    
You can also use faker to replace column

    'address' => 'faker:address'
    'date' => "faker:date($format = 'Y-m-d', $max = 'now')"
    'name' => 'faker:name'
    
    
Faker wiki [https://github.com/fzaninotto/Faker](https://github.com/fzaninotto/Faker)
    
If you want to use chinese, open `config/app.php` add `'faker_locale' => 'zh_CN'`,