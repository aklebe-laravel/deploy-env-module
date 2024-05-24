## Deploy Env

A **required** module for [Mercy Scaffold Application](https://github.com/AKlebe/MercyScaffold.git)
(or any based on it like [Jumble Sale](https://github.com/AKlebe/JumbleSale.git)).

This module provides deployments for your environments using artisan.
Configure tasks to fill database data, copy files, run artisan commands or prepare
everything you want for your application.

### Config

1) config [mercy-dependencies.php](..%2F..%2Fconfig%2Fmercy-dependencies.php) defines dependencies. Define your modules
   here.

2) Every module in ```/Modules``` can deploy their own stuff. Just create a
   ```module-deploy-env.php``` for your module like
   ```Modules/MyModule/Config/module-deploy-env.php```
   and/or ```/config/module-deploy-env.php``` in your app itself.
   See example config in ```Modules/DeployEnv/Config/module-deploy-env.php```
   The module SystemBase has a
   ServiceProvider ```\Modules\SystemBase\app\Providers\Base\ModuleBaseServiceProvider::registerConfig``` wich merge
   all ```module-deploy-env``` configs.
   After that the deployer is able to access```config('my-module.module-deploy-env')``` etc ...

### Console

#### Modules Info

To quick show a overview of your enabled/disabled modules type ordered by priority

```
deploy-env:module-info
```

To show detailed information for a specific module type

```
deploy-env:module-info MyModule
```

#### Add/Require Modules

Its recommend to update all stuff by using cli ```./ui.sh``` and choose [u] to update your application
and their dependencies.

Anyway, to add a module or update an existing one

```
php artisan deploy-env:require-module "vendor/my-module"
```

Without vendor the default vendor in .env MODULE_DEPLOYENV_REQUIRE_MODULES_DEFAULT_VENDOR will be used

```
php artisan deploy-env:require-module my-module
```

Run update for all modules and/or add new modules declared in ```config/mercy-dependencies.php``` just run

```
php artisan deploy-env:require-module
```

On developer host you should use --dev-mode to skipping modules with local changes and continue the process for all
other modules

```
php artisan deploy-env:require-module --dev-mode --debug
```

#### Make New Modules

To create a new module you could use:

```
php artisan deploy-env:make-module MyNewModule --update
```

The ```--update``` flag can be used to create missing files (if more requirements in a new version).
But: Files already exists will never be overwritten.

Before creating a module, config your ```.env``` file like this:

```
MODULE_DEPLOYENV_MAKE_MODULE_AUTHOR_NAME="Stefan Lanka"
MODULE_DEPLOYENV_MAKE_MODULE_AUTHOR_EMAIL="test@localhost.test"
```

Stubs/Templates for the new created module files are located
in ```Modules/DeployEnv/resources/module-stubs/ModuleTemplate```
Parser vars in the stubs/templates:

Var can inserted like ```${{VAR}}``` where VAR is one of the following:

```module_name```: The camel case module name like ```MyModule```

```module_name_lower```: The lower string module name like ```mymodule```

```module_author_name```: The name of the author for composer.json like ```Jon Doe```

```module_author_email```: The email of the author for composer.json like ```john-doe@localhost.test```

```module_vendor_name```: The vendor name of this module for composer.json like ```my-vendor```

#### Deploy Module Environment

Run a environment deployment of a module. It will create and/or update data in db.

```
php artisan deploy-env:terraform-modules {--module_name=} {--module_version=} {--force}
```

To deploy and/or update all configured modules just run the following. This will automatically run all deployments not
already precessed (like migrate)

```
php artisan deploy-env:terraform-modules
```

To force a module even it was already processed, enter something like the following.
It will definitively run the terraform scripts for this module,
but if the config itself has ```"update"  => false```, only new items will be created.

```
php artisan deploy-env:terraform-modules --module_name=Market --force
```

To force a specific version in a module even it was already processed, enter something like that:

```
php artisan deploy-env:terraform-modules --module_name=Market --module_version=0003 --force
```

Like migrations in laravel, successful deployed modules (and forced updates)
will be saved in database. The table named ```module_deployenv_deployments```.

##### Module Config Example

An example of a terraform config like  ```Modules/MyModule/Config/module-deploy-env.php```

```
return [

    'deployments' => [
        // Identifier 0001 to remember this deployment was already done.
        '0001' => [
            [
                'cmd'     => 'models',
                'sources' => [
                    'acl-resources.php',
                    'acl-groups.php',
                ],
            ],
            [
                'cmd'     => 'raw_sql',
                'sources' => [
                    'test-tables.sql',
                ],
            ],
            [
                'cmd'     => 'artisan',
                'sources' => [
                    'aktest:test --debug',
                ],
            ],
            [
                'cmd'     => 'artisan',
                'sources' => [
                    'cache:clear',
                ],
            ],
        ],
        '0002' => [
            [
                'cmd'     => 'models',
                'sources' => [
                    'acl-resources.php',
                ],
            ],
            [
                'cmd'        => 'raw_sql',
                'conditions' => [
                    [
                        'db_table_exists'            => 'acl_resources',
                        'db_table_not_exists'        => 'acl_tests_successfully_xyz_42',
                        'db_table_column_exists'     => 'acl_resources.id',
                        'db_table_not_column_exists' => 'acl_resources.missing_column',
                        'module_enabled'             => 'Acl',
                        'module_not_enabled'         => 'AclPreventTestingModule',
                    ],
                ],
                'sources'    => [
                    'test-acl-tables.sql',
                ],
            ],
        ],
    ],

];
```

### Create Forms and/or DataTables

If you have modules ```Form``` and ```DataTable``` installed, you can easy create the needed files by the following command:

```
php artisan deploy-env:former MyModule --classes=country,some-thing,AnyThing,nothing
```

This will create the datatable class, the form files and the eloquent model.
If one the files already exists, they will not be changed.
The result should look like this:

```
Modules/MyModule/app/Models/Country.php
Modules/MyModule/app/Forms/Country.php
Modules/MyModule/app/Http/Livewire/Form/Country.php
Modules/MyModule/app/Http/Livewire/DataTable/Country.php
Modules/MyModule/app/Models/SomeThing.php
Modules/MyModule/app/Forms/SomeThing.php
Modules/MyModule/app/Http/Livewire/Form/SomeThing.php
Modules/MyModule/app/Http/Livewire/DataTable/SomeThing.php
Modules/MyModule/app/Models/AnyThing.php
Modules/MyModule/app/Forms/AnyThing.php
Modules/MyModule/app/Http/Livewire/Form/AnyThing.php
Modules/MyModule/app/Http/Livewire/DataTable/AnyThing.php
Modules/MyModule/app/Models/Nothing.php
Modules/MyModule/app/Forms/Nothing.php
Modules/MyModule/app/Http/Livewire/Form/Nothing.php
Modules/MyModule/app/Http/Livewire/DataTable/Nothing.php
```

If you run it without ```--classes```, then for every eloquent model found, datatables and form will be created.

```
php artisan deploy-env:former MyModule
```

Note: more files could be created by 3rd party modules using the event ```DeployEnvFormer```.



